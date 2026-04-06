<?php
/**
 * LoreBuilder — Claude AI Integration
 *
 * Responsibilities:
 *   1. Context assembly — pulls entity, world, relationship, arc, timeline, and
 *      note data from the DB and builds a token-budget-aware system prompt.
 *   2. API client — sends messages to Anthropic's Messages API using PHP streams
 *      (no cURL dependency). API key is decrypted just-in-time and never logged.
 *   3. Template rendering — resolves {{variable}} placeholders (dot notation).
 *
 * Security invariants:
 *   - apiKey is NEVER written to any log, error message, or response field.
 *   - apiKey is sodium_memzero()'d is not feasible in PHP strings, but it is
 *     never persisted beyond the scope of callApi().
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
 * Anthropic's actual tokeniser may differ slightly; this gives ~25% safety margin.
 */

declare(strict_types=1);

class ClaudeException extends \RuntimeException {}

class Claude
{
    // ─── Token Budget ─────────────────────────────────────────────────────────

    /**
     * Maximum tokens to allocate for the assembled context (system prompt).
     * Claude Sonnet 4 supports 200k context; we reserve headroom for the user
     * turn and the completion response.
     */
    private const CONTEXT_TOKEN_BUDGET = 150_000;

    /** Reserved for the model's completion output. */
    private const MAX_TOKENS_RESPONSE = 4_096;

    /** Rough chars-to-tokens ratio for English text. */
    private const CHARS_PER_TOKEN = 4;

    // ─── Context Drop Thresholds (fraction of CONTEXT_TOKEN_BUDGET) ──────────

    private const TRIM_NOTES_AT      = 0.60;
    private const DROP_REL_NOTES_AT  = 0.80;
    private const DROP_ARCS_AT       = 0.85;
    private const DROP_TIMELINE_AT   = 0.88;
    private const DROP_REL_ATTRS_AT  = 0.92;

    // ─── Anthropic API ────────────────────────────────────────────────────────

    private const API_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION    = '2023-06-01';
    private const CONNECT_TIMEOUT = 10;  // seconds
    private const READ_TIMEOUT    = 60;  // seconds for full response

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
     * @throws ClaudeException  If world or entity not found
     */
    public static function buildContext(int $entityId, int $worldId, string $mode): array
    {
        $budget   = self::CONTEXT_TOKEN_BUDGET;
        $sections = [];

        // ── 1. World config (NEVER DROP) ──────────────────────────────────────
        $world = DB::queryOne(
            'SELECT id, name, genre, tone, era_system, content_warnings,
                    ai_model, ai_token_budget, ai_tokens_used
               FROM worlds
              WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $worldId]
        );

        if ($world === null) {
            throw new ClaudeException('World not found.');
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
                throw new ClaudeException('Entity not found in this world.');
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
                'SELECT r.rel_type, r.strength, r.notes AS rel_notes, r.bidirectional,
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
                // Build named placeholders (:id0, :id1, …) to avoid dynamic SQL literals.
                // IDs are integers from DB results, but we follow the no-interpolation rule.
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
     * Call the Anthropic Messages API.
     *
     * SECURITY: $apiKey is NEVER logged. It exists in scope only for the
     * duration of this method call and is not stored anywhere after return.
     *
     * @param array  $context     Output of buildContext()
     * @param string $userPrompt  The user's actual request
     * @param string $apiKey      Plaintext Anthropic API key (decrypted by caller)
     * @param string $model       Model ID (from worlds.ai_model)
     * @param int    $maxTokens   Max completion tokens
     * @return array{text:string, prompt_tokens:int, completion_tokens:int, total_tokens:int, model:string}
     * @throws ClaudeException  On network error, auth failure, or API error
     */
    public static function callApi(
        array  $context,
        string $userPrompt,
        string $apiKey,
        string $model     = 'claude-sonnet-4-20250514',
        int    $maxTokens = self::MAX_TOKENS_RESPONSE
    ): array {
        if (empty(trim($apiKey))) {
            throw new ClaudeException('API key is missing.');
        }
        if (empty(trim($userPrompt))) {
            throw new ClaudeException('User prompt is empty.');
        }

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $context['system'] ?? '',
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Build stream context — no cURL, native PHP streams only
        $opts = [
            'http' => [
                'method'           => 'POST',
                'header'           => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'          => $payload,
                'timeout'          => self::READ_TIMEOUT,
                'ignore_errors'    => true,  // fetch response body even on 4xx/5xx
                'follow_location'  => false,
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        $streamCtx = stream_context_create($opts);
        $response  = @file_get_contents(self::API_ENDPOINT, false, $streamCtx);

        // $http_response_header is populated by file_get_contents with HTTP headers
        $statusCode = self::extractStatusCode($http_response_header ?? []);

        if ($response === false) {
            // Network-level failure — log the fact but never log the key
            error_log('[Claude] Network error calling Anthropic API (no response body)');
            throw new ClaudeException('Failed to reach Anthropic API. Check network connectivity.');
        }

        $body = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);

        if ($statusCode !== 200) {
            $errType = $body['error']['type']    ?? 'unknown_error';
            $errMsg  = $body['error']['message'] ?? 'No error message returned.';

            // Map API error types to ClaudeException messages suitable for the client
            $mapped = match ($errType) {
                'authentication_error'   => 'API key is invalid or revoked.',
                'permission_error'       => 'API key lacks permission for this model.',
                'rate_limit_error'       => 'Anthropic rate limit reached. Try again later.',
                'overloaded_error'       => 'Anthropic API is overloaded. Try again later.',
                'invalid_request_error'  => "Invalid API request: {$errMsg}",
                default                  => "Anthropic API error ({$errType}).",
            };

            // Log type but NOT the key
            error_log("[Claude] API error {$statusCode} — {$errType}: {$errMsg}");

            throw new ClaudeException($mapped);
        }

        // Extract response text
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $usage = $body['usage'] ?? [];

        return [
            'text'              => $text,
            'prompt_tokens'     => (int) ($usage['input_tokens']  ?? 0),
            'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens'      => (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
            'model'             => $body['model'] ?? $model,
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
     *   'platform' — use PLATFORM_ANTHROPIC_KEY constant from config.php
     *   'oauth'    — not implemented in Phase 1 (throws ClaudeException)
     *
     * SECURITY: caller must NEVER log or return the returned string.
     *
     * @return string  Plaintext Anthropic API key
     * @throws ClaudeException  If key is missing, mode is unsupported, or decryption fails
     */
    public static function resolveApiKey(int $worldId): string
    {
        $world = DB::queryOne(
            'SELECT ai_key_mode, ai_key_enc FROM worlds WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $worldId]
        );

        if ($world === null) {
            throw new ClaudeException('World not found.');
        }

        return match ($world['ai_key_mode']) {
            'user' => self::decryptUserKey((string) ($world['ai_key_enc'] ?? '')),
            'platform' => self::getPlatformKey(),
            'oauth' => throw new ClaudeException('OAuth key mode is not implemented in Phase 1.'),
            default => throw new ClaudeException("Unknown AI key mode: {$world['ai_key_mode']}"),
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
     * Extract HTTP status code from the $http_response_header superglobal array.
     * Returns 0 if the array is empty or the status line is unparseable.
     */
    private static function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * Decrypt the user-provided API key stored in worlds.ai_key_enc.
     * Throws ClaudeException (not CryptoException) so the caller sees a
     * consistent exception type from this layer.
     */
    private static function decryptUserKey(string $enc): string
    {
        if (empty($enc)) {
            throw new ClaudeException('No API key configured for this world. Please add one in World Settings.');
        }

        try {
            return Crypto::decryptApiKey($enc, APP_SECRET);
        } catch (CryptoException $e) {
            // Log the fact of failure (not the key)
            error_log('[Claude] API key decryption failed for a world: ' . $e->getMessage());
            throw new ClaudeException('Stored API key could not be decrypted. It may need to be re-entered.');
        }
    }

    /**
     * Return the platform-wide Anthropic API key from config.
     * Throws if the operator has not configured one.
     */
    private static function getPlatformKey(): string
    {
        if (!defined('PLATFORM_ANTHROPIC_KEY') || empty(PLATFORM_ANTHROPIC_KEY)) {
            throw new ClaudeException(
                'Platform AI key is not configured. Contact the site administrator.'
            );
        }

        return PLATFORM_ANTHROPIC_KEY;
    }
}
