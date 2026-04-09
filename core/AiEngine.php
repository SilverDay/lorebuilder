<?php

/**
 * LoreBuilder — AI Engine
 *
 * Central AI orchestration layer. Provider-agnostic context assembly, template
 * rendering, and API call delegation to registered providers.
 *
 * Responsibilities:
 *   1. Context assembly — pulls entity, world, relationship, arc, timeline, and
 *      note data from the DB and builds a token-budget-aware system prompt.
 *   2. API client — delegates to the registered AiProvider for the world's
 *      configured provider. API key is decrypted just-in-time and never logged.
 *   3. Template rendering — resolves {{variable}} placeholders (dot notation).
 *   4. Provider registry — manages available AI providers.
 *
 * Security invariants:
 *   - apiKey is NEVER written to any log, error message, or response field.
 *   - Only session metadata (token counts, model, status) is stored in ai_sessions.
 *   - Responses are stored in lore_notes by the caller — not here.
 *
 * Context budget drop order (design-document §7.4):
 *   NEVER   — World config (genre, tone, era system)
 *   NEVER   — Target entity (name, type, attributes, status)
 *   @ 60%   — Start trimming lore notes (oldest first)
 *   @ 80%   — Drop relationship detail notes (keep rel_type + counterpart name)
 *   @ 85%   — Drop arc membership + logline
 *   @ 88%   — Drop timeline position
 *   @ 92%   — Drop related entity attribute summaries
 *
 * Token estimation: ~4 characters per token (conservative English average).
 */

declare(strict_types=1);

class AiEngineException extends \RuntimeException {}

class AiEngine
{
    // ─── Provider Registry ────────────────────────────────────────────────────

    /** @var array<string, class-string<AiProvider>> */
    private static array $providers = [
        'anthropic' => AnthropicProvider::class,
        'openai'    => OpenAiProvider::class,
        'google'    => GeminiProvider::class,
        'ollama'    => OllamaProvider::class,
    ];

    /**
     * Register an additional provider at runtime.
     *
     * @param class-string<AiProvider> $className
     */
    public static function registerProvider(string $className): void
    {
        self::$providers[$className::id()] = $className;
    }

    /**
     * Get a provider class by ID.
     *
     * @return class-string<AiProvider>
     * @throws AiEngineException If provid ID is unknown
     */
    public static function getProvider(string $providerId): string
    {
        if (!isset(self::$providers[$providerId])) {
            throw new AiEngineException("Unknown AI provider: {$providerId}");
        }
        return self::$providers[$providerId];
    }

    /**
     * List available providers with their metadata.
     *
     * @return array<string, array{id: string, label: string, models: array, default_model: string}>
     */
    public static function availableProviders(): array
    {
        $result = [];
        foreach (self::$providers as $id => $class) {
            $result[$id] = [
                'id'            => $class::id(),
                'label'         => $class::label(),
                'models'        => $class::models(),
                'default_model' => $class::defaultModel(),
            ];
        }
        return $result;
    }

    // ─── Token Budget ─────────────────────────────────────────────────────────

    /**
     * Default context token budget (used when provider-specific budget
     * cannot be determined).
     */
    private const CONTEXT_TOKEN_BUDGET = 150_000;

    /** Reserved for the model's completion output. */
    private const MAX_TOKENS_RESPONSE = 4_096;

    /** Rough chars-to-tokens ratio for English text. */
    private const CHARS_PER_TOKEN = 4;

    // ─── Context Drop Thresholds (fraction of budget) ────────────────────────

    private const TRIM_NOTES_AT      = 0.60;
    private const DROP_REL_NOTES_AT  = 0.80;
    private const DROP_ARCS_AT       = 0.85;
    private const DROP_TIMELINE_AT   = 0.88;
    private const DROP_REL_ATTRS_AT  = 0.92;

    // ─── Public Interface ─────────────────────────────────────────────────────

    /**
     * Assemble a token-budget-aware system prompt for the given entity + mode.
     *
     * Returns an array with:
     *   'system'       => string  — assembled system prompt text
     *   'world'        => array   — world metadata used
     *   'entity'       => array   — entity metadata used
     *   'budget_used'  => int     — estimated tokens in the system prompt
     *
     * @param int    $entityId  Target entity (may be 0 for world-level modes)
     * @param int    $worldId   World context
     * @param string $mode      Invocation mode (entity_assist, arc_synthesiser, …)
     * @return array
     * @throws AiEngineException  If world or entity not found
     */
    public static function buildContext(int $entityId, int $worldId, string $mode): array
    {
        $budget   = self::CONTEXT_TOKEN_BUDGET;
        $sections = [];

        // ── 1. World config (NEVER DROP) ──────────────────────────────────────
        $world = DB::queryOne(
            'SELECT id, name, genre, tone, era_system, content_warnings,
                    ai_model, ai_provider, ai_endpoint_url, ai_token_budget, ai_tokens_used
               FROM worlds
              WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $worldId]
        );

        if ($world === null) {
            throw new AiEngineException('World not found.');
        }

        // Adjust budget based on provider + model if available
        $providerId = $world['ai_provider'] ?? 'anthropic';
        $model      = $world['ai_model'] ?? '';
        if (isset(self::$providers[$providerId]) && !empty($model)) {
            $providerClass = self::$providers[$providerId];
            $providerBudget = $providerClass::contextBudget($model);
            if ($providerBudget > 0) {
                $budget = $providerBudget;
            }
        }

        $worldSection  = "WORLD: {$world['name']}\n";
        $worldSection .= 'Genre: ' . ($world['genre'] ?? 'unspecified') . "\n";
        $worldSection .= 'Tone: '  . ($world['tone']  ?? 'unspecified') . "\n";
        if (!empty($world['era_system'])) {
            $worldSection .= "Era system: {$world['era_system']}\n";
        }
        if (!empty($world['content_warnings'])) {
            $worldSection .= "Content warnings: {$world['content_warnings']}\n";
        }
        $sections['world'] = $worldSection;
        $used = self::estimateTokens($worldSection);

        // ── 2. Target entity (NEVER DROP) ─────────────────────────────────────
        $entity = null;
        if ($entityId > 0) {
            $entity = DB::queryOne(
                'SELECT id, name, type, status, short_summary, lore_body, attributes_json
                   FROM entities
                  WHERE id = :eid AND world_id = :wid AND deleted_at IS NULL',
                ['eid' => $entityId, 'wid' => $worldId]
            );

            if ($entity === null) {
                throw new AiEngineException('Entity not found in this world.');
            }

            $entitySection  = "\nENTITY: {$entity['name']} ({$entity['type']})\n";
            $entitySection .= "Status: {$entity['status']}\n";
            if (!empty($entity['short_summary'])) {
                $entitySection .= "Summary: {$entity['short_summary']}\n";
            }

            // Structured attributes from entity_attributes table
            $attrs = DB::query(
                'SELECT attr_key, attr_value, data_type
                   FROM entity_attributes
                  WHERE entity_id = :eid AND world_id = :wid
                  ORDER BY sort_order ASC',
                ['eid' => $entityId, 'wid' => $worldId]
            );

            if (!empty($attrs)) {
                $entitySection .= "Attributes:\n";
                foreach ($attrs as $attr) {
                    $entitySection .= "  {$attr['attr_key']}: {$attr['attr_value']}\n";
                }
            } elseif (!empty($entity['lore_body'])) {
                // Fallback: truncate lore_body as attribute context
                $entitySection .= 'Lore body (excerpt): ' . mb_substr($entity['lore_body'], 0, 600) . "\n";
            }

            $sections['entity'] = $entitySection;
            $used += self::estimateTokens($entitySection);
        }

        // ── 3. Lore notes — trim oldest first at 60% budget ───────────────────
        $noteSection = '';
        if ($entityId > 0) {
            $notes = DB::query(
                'SELECT content, is_canonical, ai_generated, created_at
                   FROM lore_notes
                  WHERE entity_id = :eid AND world_id = :wid AND deleted_at IS NULL
                  ORDER BY created_at DESC
                  LIMIT 20',
                ['eid' => $entityId, 'wid' => $worldId]
            );

            if (!empty($notes)) {
                $noteLines = [];
                foreach ($notes as $note) {
                    $tag        = $note['is_canonical'] ? '[CANONICAL] ' : ($note['ai_generated'] ? '[AI] ' : '');
                    $noteLines[] = $tag . mb_substr(trim($note['content']), 0, 400);
                }

                // Fill from newest; stop when we'd exceed the 60% trim threshold
                $noteSection = "\nLORE NOTES (newest first):\n";
                $allowedForNotes = (int) ($budget * self::TRIM_NOTES_AT) - $used;
                $notesUsed       = self::estimateTokens($noteSection);

                foreach ($noteLines as $line) {
                    $lineTokens = self::estimateTokens($line . "\n");
                    if ($notesUsed + $lineTokens > $allowedForNotes) {
                        break;  // oldest notes dropped
                    }
                    $noteSection .= "- {$line}\n";
                    $notesUsed   += $lineTokens;
                }
                $sections['notes'] = $noteSection;
                $used += $notesUsed;
            }
        }

        // ── 4. Relationships (notes dropped at 80%, detail at 80%) ────────────
        $relSection = '';
        if ($entityId > 0) {
            $rels = DB::query(
                'SELECT r.rel_type, r.strength, r.notes AS rel_notes, r.is_bidirectional,
                        ef.name AS from_name, ef.type AS from_type,
                        et.name AS to_name,   et.type AS to_type,
                        r.from_entity_id, r.to_entity_id
                   FROM entity_relationships r
                   JOIN entities ef ON ef.id = r.from_entity_id
                   JOIN entities et ON et.id = r.to_entity_id
                  WHERE r.world_id = :wid
                    AND (r.from_entity_id = :eid1 OR r.to_entity_id = :eid2)
                    AND r.deleted_at IS NULL
                  ORDER BY r.strength DESC
                  LIMIT 30',
                ['wid' => $worldId, 'eid1' => $entityId, 'eid2' => $entityId]
            );

            if (!empty($rels)) {
                $dropRelNotes = ($used / $budget) >= self::DROP_REL_NOTES_AT;

                $relSection = "\nRELATIONSHIPS:\n";
                foreach ($rels as $rel) {
                    // Identify the counterpart entity
                    if ((int) $rel['from_entity_id'] === $entityId) {
                        $counterpart = "{$rel['to_name']} ({$rel['to_type']})";
                        $direction   = '→';
                    } else {
                        $counterpart = "{$rel['from_name']} ({$rel['from_type']})";
                        $direction   = '←';
                    }
                    $strength = $rel['strength'] !== null ? " [strength:{$rel['strength']}]" : '';
                    $line     = "- {$direction} {$rel['rel_type']}: {$counterpart}{$strength}";
                    if (!$dropRelNotes && !empty($rel['rel_notes'])) {
                        $line .= ' — ' . mb_substr($rel['rel_notes'], 0, 120);
                    }
                    $relSection .= $line . "\n";
                }
                $sections['relationships'] = $relSection;
                $used += self::estimateTokens($relSection);
            }
        }

        // ── 5. Story arc membership (drop at 85%) ─────────────────────────────
        $arcSection = '';
        if ($entityId > 0 && ($used / $budget) < self::DROP_ARCS_AT) {
            $arcs = DB::query(
                'SELECT sa.name, sa.logline, sa.status, sa.theme
                   FROM arc_entities sae
                   JOIN story_arcs sa ON sa.id = sae.arc_id
                  WHERE sae.entity_id = :eid
                    AND sa.world_id   = :wid
                    AND sa.deleted_at IS NULL
                  LIMIT 5',
                ['eid' => $entityId, 'wid' => $worldId]
            );

            if (!empty($arcs)) {
                $arcSection = "\nSTORY ARCS:\n";
                foreach ($arcs as $arc) {
                    $arcSection .= "- {$arc['name']} (status: {$arc['status']})";
                    if (!empty($arc['logline'])) {
                        $arcSection .= ': ' . mb_substr($arc['logline'], 0, 150);
                    }
                    if (!empty($arc['theme'])) {
                        $arcSection .= " [theme: {$arc['theme']}]";
                    }
                    $arcSection .= "\n";
                }
                $sections['arcs'] = $arcSection;
                $used += self::estimateTokens($arcSection);
            }
        }

        // ── 6. Timeline position (drop at 88%) ────────────────────────────────
        $timelineSection = '';
        if ($entityId > 0 && ($used / $budget) < self::DROP_TIMELINE_AT) {
            // Events that reference this entity as the subject
            $events = DB::query(
                'SELECT te.label AS title, te.position_label, te.position_era, te.position_order,
                        tl.name AS timeline_name, tl.scale_mode
                   FROM timeline_events te
                   JOIN timelines tl ON tl.id = te.timeline_id
                  WHERE te.entity_id = :eid
                    AND te.world_id  = :wid
                    AND te.deleted_at IS NULL
                    AND tl.deleted_at IS NULL
                  ORDER BY te.position_order ASC
                  LIMIT 5',
                ['eid' => $entityId, 'wid' => $worldId]
            );

            if (!empty($events)) {
                $timelineSection = "\nTIMELINE POSITIONS:\n";
                foreach ($events as $ev) {
                    $pos = $ev['position_label'] ?? $ev['position_era'] ?? "position {$ev['position_order']}";
                    $timelineSection .= "- [{$ev['timeline_name']}] {$ev['title']} @ {$pos}\n";
                }
                $sections['timeline'] = $timelineSection;
                $used += self::estimateTokens($timelineSection);
            }
        }

        // ── 7. Related entity attribute summaries (drop at 92%) ───────────────
        $relAttrSection = '';
        if ($entityId > 0 && ($used / $budget) < self::DROP_REL_ATTRS_AT) {
            // Collect unique counterpart IDs from relationship data
            $rels = DB::query(
                'SELECT from_entity_id, to_entity_id
                   FROM entity_relationships
                  WHERE world_id = :wid
                    AND (from_entity_id = :eid1 OR to_entity_id = :eid2)
                    AND deleted_at IS NULL
                  LIMIT 10',
                ['wid' => $worldId, 'eid1' => $entityId, 'eid2' => $entityId]
            );

            $counterpartIds = [];
            foreach ($rels as $rel) {
                $cid = (int) $rel['from_entity_id'] === $entityId
                    ? (int) $rel['to_entity_id']
                    : (int) $rel['from_entity_id'];
                $counterpartIds[$cid] = true;
            }

            if (!empty($counterpartIds)) {
                $idParams = [];
                $idBinds  = ['wid' => $worldId];
                foreach (array_keys($counterpartIds) as $i => $cid) {
                    $key           = 'id' . $i;
                    $idParams[]    = ':' . $key;
                    $idBinds[$key] = (int) $cid;
                }
                $inClause     = implode(',', $idParams);
                $counterparts = DB::query(
                    "SELECT e.id, e.name, e.type, e.short_summary
                       FROM entities e
                      WHERE e.id IN ({$inClause})
                        AND e.world_id  = :wid
                        AND e.deleted_at IS NULL",
                    $idBinds
                );

                if (!empty($counterparts)) {
                    $relAttrSection = "\nRELATED ENTITIES (summaries):\n";
                    foreach ($counterparts as $cp) {
                        $relAttrSection .= "- {$cp['name']} ({$cp['type']})";
                        if (!empty($cp['short_summary'])) {
                            $relAttrSection .= ': ' . mb_substr($cp['short_summary'], 0, 120);
                        }
                        $relAttrSection .= "\n";
                    }
                    $sections['related_entities'] = $relAttrSection;
                    $used += self::estimateTokens($relAttrSection);
                }
            }
        }

        // ── Mode-specific instruction block ────────────────────────────────────
        if ($mode === 'image_prompt') {
            $genre = $world['genre'] ?? 'fantasy';
            $tone  = $world['tone']  ?? 'epic';
            $sections['mode_instruction'] = <<<PROMPT

IMAGE PROMPT GENERATION INSTRUCTIONS:
You are an expert at writing detailed image-generation prompts for AI art tools
(Midjourney, DALL-E, Stable Diffusion, Flux, etc.).

Based on the entity data and world context above, produce a richly detailed
visual description. Structure your output as follows:

**Subject**: Describe the entity's physical appearance, attire, pose, expression,
distinguishing features. Draw from entity attributes and lore.

**Environment**: Describe the setting, lighting, atmosphere. Derive from the
world's genre ({$genre}) and tone ({$tone}).

**Style**: Suggest an art style that fits the world's aesthetic. For example:
epic fantasy → "oil painting, baroque lighting"; sci-fi → "concept art, cinematic";
horror → "dark atmospheric digital painting".

**Technical details**: Camera angle, composition, color palette.

**Negative prompt**: List common artefacts to exclude (e.g. "blurry, deformed hands,
extra limbs, watermark, text, low quality").

Format the final prompt as a single block of descriptive text (suitable for
pasting into an image generator), followed by a separate "Negative prompt:" line.
Use vivid, concrete visual language. Avoid abstract or narrative descriptions
that image generators cannot interpret.

PROMPT;
        }

        // ── Assemble final system prompt ───────────────────────────────────────
        $system = implode('', array_values($sections));

        return [
            'system'      => $system,
            'world'       => $world,
            'entity'      => $entity,
            'budget_used' => $used,
        ];
    }

    /**
     * Call the AI API via the appropriate provider.
     *
     * Delegates to the provider configured for the world (defaults to Anthropic).
     * Maintains the same return format as the original Claude::callApi() for
     * backward compatibility.
     *
     * SECURITY: $apiKey is NEVER logged. It exists in scope only for the
     * duration of this method call.
     *
     * @param array  $context     Output of buildContext()
     * @param string $userPrompt  The user's actual request
     * @param string $apiKey      Plaintext API key (decrypted by caller)
     * @param string $model       Model ID (from worlds.ai_model)
     * @param int    $maxTokens   Max completion tokens
     * @param string $providerId  Provider identifier (from worlds.ai_provider)
     * @return array{text:string, prompt_tokens:int, completion_tokens:int, total_tokens:int, model:string, provider:string}
     * @throws AiEngineException  On network error, auth failure, or API error
     */
    public static function callApi(
        array  $context,
        string $userPrompt,
        string $apiKey,
        string $model      = 'claude-sonnet-4-20250514',
        int    $maxTokens   = self::MAX_TOKENS_RESPONSE,
        string $providerId  = 'anthropic'
    ): array {
        $providerClass = self::getProvider($providerId);

        // Set endpoint URL for Ollama provider (per-world custom endpoint)
        if ($providerId === 'ollama' && method_exists($providerClass, 'setEndpoint')) {
            $endpointUrl = $context['world']['ai_endpoint_url'] ?? null;
            /** @var OllamaProvider $providerClass */
            $providerClass::setEndpoint($endpointUrl);
        }

        try {
            $response = $providerClass::call(
                $context['system'] ?? '',
                $userPrompt,
                $apiKey,
                $model,
                $maxTokens
            );
        } catch (AiProviderException $e) {
            // Wrap provider exception in AiEngineException for consistent handling
            throw new AiEngineException($e->getMessage(), $e->getCode(), $e);
        }

        return [
            'text'              => $response->text,
            'prompt_tokens'     => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'total_tokens'      => $response->totalTokens,
            'model'             => $response->model,
            'provider'          => $response->provider,
        ];
    }

    /**
     * Resolve {{variable}} and {{object.field}} placeholders in a template string.
     *
     * Dot notation traverses nested arrays one level deep:
     *   {{entity.name}}  → $vars['entity']['name']
     *   {{world.genre}}  → $vars['world']['genre']
     *   {{user_request}} → $vars['user_request']
     *
     * Unknown variables are replaced with an empty string so templates never
     * leak placeholder tokens to the model.
     *
     * @param string                    $tpl   Template string with {{…}} placeholders
     * @param array<string,mixed>       $vars  Variable map (strings or arrays)
     * @return string
     */
    public static function renderTemplate(string $tpl, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{([a-zA-Z0-9_.]+)\}\}/',
            static function (array $m) use ($vars): string {
                $key   = $m[1];
                $parts = explode('.', $key, 2);

                if (count($parts) === 2) {
                    [$group, $field] = $parts;
                    $val = $vars[$group][$field] ?? '';
                } else {
                    $val = $vars[$key] ?? '';
                }

                return is_scalar($val) ? (string) $val : '';
            },
            $tpl
        );
    }

    /**
     * Load a prompt template from the DB for a given mode and world.
     *
     * Preference order:
     *   1. World-specific custom template (world_id = $worldId, mode = $mode)
     *   2. Platform default template (world_id IS NULL, mode = $mode, is_default = 1)
     *
     * Returns null if no template found for the mode.
     *
     * @return array{system_tpl:string, user_tpl:string, name:string}|null
     */
    public static function loadTemplate(string $mode, int $worldId): ?array
    {
        // World-specific override first
        $tpl = DB::queryOne(
            'SELECT system_tpl, user_tpl, name
               FROM prompt_templates
              WHERE mode = :mode AND world_id = :wid
              ORDER BY created_at DESC
              LIMIT 1',
            ['mode' => $mode, 'wid' => $worldId]
        );

        if ($tpl !== null) {
            return $tpl;
        }

        // Fall back to platform default
        return DB::queryOne(
            'SELECT system_tpl, user_tpl, name
               FROM prompt_templates
              WHERE mode = :mode AND world_id IS NULL AND is_default = 1
              LIMIT 1',
            ['mode' => $mode]
        );
    }

    /**
     * Retrieve and decrypt the API key for a world.
     *
     * Selects the correct key based on ai_key_mode:
     *   'user'     — decrypt world.ai_key_enc with APP_SECRET
     *   'platform' — use PLATFORM_*_KEY constant from config.php
     *   'oauth'    — not implemented in Phase 1 (throws AiEngineException)
     *
     * SECURITY: caller must NEVER log or return the returned string.
     *
     * @param int    $worldId
     * @param string $providerId  Provider whose platform key to use (for platform mode)
     * @return string  Plaintext API key
     * @throws AiEngineException  If key is missing, mode is unsupported, or decryption fails
     */
    public static function resolveApiKey(int $worldId, string $providerId = 'anthropic'): string
    {
        $world = DB::queryOne(
            'SELECT ai_key_mode, ai_key_enc FROM worlds WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $worldId]
        );

        if ($world === null) {
            throw new AiEngineException('World not found.');
        }

        // Ollama doesn't require an API key — return empty string or user key for proxy-auth
        if ($providerId === 'ollama') {
            if ($world['ai_key_mode'] === 'user' && !empty($world['ai_key_enc'])) {
                return self::decryptUserKey((string) $world['ai_key_enc']);
            }
            return ''; // No key needed for local Ollama
        }

        return match ($world['ai_key_mode']) {
            'user'     => self::decryptUserKey((string) ($world['ai_key_enc'] ?? '')),
            'platform' => self::getPlatformKey($providerId),
            'oauth'    => throw new AiEngineException('OAuth key mode is not implemented in Phase 1.'),
            default    => throw new AiEngineException("Unknown AI key mode: {$world['ai_key_mode']}"),
        };
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Rough token count estimate: total chars / CHARS_PER_TOKEN.
     * Intentionally over-estimates to leave safety margin.
     */
    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Decrypt the user-provided API key stored in worlds.ai_key_enc.
     */
    private static function decryptUserKey(string $enc): string
    {
        if (empty($enc)) {
            throw new AiEngineException('No API key configured for this world. Please add one in World Settings.');
        }

        try {
            return Crypto::decryptApiKey($enc, APP_SECRET);
        } catch (CryptoException $e) {
            error_log('[AiEngine] API key decryption failed for a world: ' . $e->getMessage());
            throw new AiEngineException('Stored API key could not be decrypted. It may need to be re-entered.');
        }
    }

    /**
     * Return the platform-wide API key for the specified provider.
     *
     * @throws AiEngineException If the operator has not configured the key
     */
    private static function getPlatformKey(string $providerId = 'anthropic'): string
    {
        $constName = match ($providerId) {
            'anthropic' => 'PLATFORM_ANTHROPIC_KEY',
            'openai'    => 'PLATFORM_OPENAI_KEY',
            'google'    => 'PLATFORM_GEMINI_KEY',
            default     => throw new AiEngineException("No platform key constant for provider: {$providerId}"),
        };

        if (!defined($constName) || empty(constant($constName))) {
            throw new AiEngineException(
                'Platform AI key is not configured for this provider. Contact the site administrator.'
            );
        }

        return constant($constName);
    }
}
