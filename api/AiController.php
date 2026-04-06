<?php
/**
 * LoreBuilder — AI Controller
 *
 * Endpoints:
 *   POST   /api/v1/worlds/:wid/ai/assist              — entity/world-level AI assist
 *   POST   /api/v1/worlds/:wid/ai/consistency-check   — full-world consistency analysis
 *   GET    /api/v1/worlds/:wid/ai/sessions             — paginated AI session history
 *   GET    /api/v1/worlds/:wid/settings/ai/budget      — token budget status
 *   GET    /api/v1/worlds/:wid/prompt-templates        — list world + default templates
 *   POST   /api/v1/worlds/:wid/prompt-templates        — create world-specific template
 *   PATCH  /api/v1/worlds/:wid/prompt-templates/:id    — update template
 *   DELETE /api/v1/worlds/:wid/prompt-templates/:id    — delete template
 *
 * Security invariants:
 *   - API key is NEVER returned in any response field.
 *   - API key is NEVER logged.
 *   - Rate limiting applied per-user AND per-world on AI endpoints.
 *   - All world_id values come from the validated route parameter, not the request body.
 */

declare(strict_types=1);

class AiController
{
    /** Allowed AI invocation modes. */
    private const VALID_MODES = [
        'entity_assist',
        'arc_synthesiser',
        'consistency_check',
        'world_overview',
        'custom',
    ];

    /** Per-user AI request limit per hour. */
    private const USER_RATE_LIMIT  = 20;

    /** Per-world AI request limit per hour. */
    private const WORLD_RATE_LIMIT = 100;

    // ─── POST /api/v1/worlds/:wid/ai/assist ───────────────────────────────────

    public static function assist(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        // Rate limits: per-user and per-world
        RateLimit::check("ai:user:{$userId}", self::USER_RATE_LIMIT,  3600);
        RateLimit::check("ai:world:{$wid}",  self::WORLD_RATE_LIMIT, 3600);

        $data = Validator::parseJson([
            'entity_id'   => 'int|nullable',
            'mode'        => 'required|string|in:entity_assist,arc_synthesiser,world_overview,custom',
            'user_prompt' => 'required|string|min:1|max:4000',
        ]);

        $entityId   = isset($data['entity_id']) ? (int) $data['entity_id'] : 0;
        $mode       = $data['mode'];
        $userPrompt = $data['user_prompt'];

        self::runAssist($wid, $userId, $entityId, $mode, $userPrompt);
    }

    // ─── POST /api/v1/worlds/:wid/ai/consistency-check ────────────────────────

    public static function consistencyCheck(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        RateLimit::check("ai:user:{$userId}", self::USER_RATE_LIMIT,  3600);
        RateLimit::check("ai:world:{$wid}",  self::WORLD_RATE_LIMIT, 3600);

        $data = Validator::parseJson([
            'user_prompt' => 'string|max:2000|nullable',
        ]);

        $userPrompt = $data['user_prompt'] ?? 'Analyse this world for narrative inconsistencies, contradictions, and unresolved plot threads. Provide a structured report.';

        self::runAssist($wid, $userId, 0, 'consistency_check', $userPrompt);
    }

    // ─── GET /api/v1/worlds/:wid/ai/sessions ──────────────────────────────────

    public static function sessions(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $query = Validator::parseQuery([
            'page'      => 'int|min:1',
            'per_page'  => 'int|min:1|max:100',
            'entity_id' => 'int|nullable',
        ]);

        $page    = $query['page']     ?? 1;
        $perPage = $query['per_page'] ?? 20;
        $offset  = ($page - 1) * $perPage;

        $filterByEntity = isset($query['entity_id']);

        if ($filterByEntity) {
            $rows = DB::query(
                'SELECT s.id, s.entity_id, s.mode, s.model,
                        s.prompt_tokens, s.completion_tokens, s.total_tokens,
                        s.status, s.error_message, s.created_at,
                        u.display_name AS user_display_name,
                        e.name AS entity_name
                   FROM ai_sessions s
                   JOIN users u ON u.id = s.user_id
                   LEFT JOIN entities e ON e.id = s.entity_id
                  WHERE s.world_id = :wid AND s.entity_id = :eid
                  ORDER BY s.created_at DESC
                  LIMIT :limit OFFSET :offset',
                ['wid' => $wid, 'eid' => $query['entity_id'], 'limit' => $perPage, 'offset' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM ai_sessions s WHERE s.world_id = :wid AND s.entity_id = :eid',
                ['wid' => $wid, 'eid' => $query['entity_id']]
            )['n'];
        } else {
            $rows = DB::query(
                'SELECT s.id, s.entity_id, s.mode, s.model,
                        s.prompt_tokens, s.completion_tokens, s.total_tokens,
                        s.status, s.error_message, s.created_at,
                        u.display_name AS user_display_name,
                        e.name AS entity_name
                   FROM ai_sessions s
                   JOIN users u ON u.id = s.user_id
                   LEFT JOIN entities e ON e.id = s.entity_id
                  WHERE s.world_id = :wid
                  ORDER BY s.created_at DESC
                  LIMIT :limit OFFSET :offset',
                ['wid' => $wid, 'limit' => $perPage, 'offset' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM ai_sessions s WHERE s.world_id = :wid',
                ['wid' => $wid]
            )['n'];
        }

        http_response_code(200);
        echo json_encode([
            'data' => $rows,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    // ─── GET /api/v1/worlds/:wid/settings/ai/budget ───────────────────────────

    public static function budget(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'owner');

        $world = DB::queryOne(
            'SELECT ai_key_mode, ai_key_fingerprint, ai_model,
                    ai_token_budget, ai_tokens_used, ai_budget_resets_at
               FROM worlds
              WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $wid]
        );

        if ($world === null) {
            http_response_code(404);
            echo json_encode(['error' => 'World not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        // Token usage breakdown by month
        $usageByDay = DB::query(
            'SELECT DATE(created_at) AS day, SUM(total_tokens) AS tokens
               FROM ai_sessions
              WHERE world_id = :wid
                AND created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')
              GROUP BY DATE(created_at)
              ORDER BY day ASC',
            ['wid' => $wid]
        );

        http_response_code(200);
        echo json_encode([
            'data' => [
                'ai_key_mode'        => $world['ai_key_mode'],
                'ai_key_fingerprint' => $world['ai_key_fingerprint'],
                'ai_model'           => $world['ai_model'],
                'ai_token_budget'    => (int) $world['ai_token_budget'],
                'ai_tokens_used'     => (int) $world['ai_tokens_used'],
                'ai_budget_resets_at'=> $world['ai_budget_resets_at'],
                'usage_by_day'       => $usageByDay,
            ],
        ]);
    }

    // ─── GET /api/v1/worlds/:wid/prompt-templates ─────────────────────────────

    public static function templateIndex(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        // World-specific + platform defaults
        $templates = DB::query(
            'SELECT id, world_id, mode, name, system_tpl, user_tpl, is_default,
                    created_by, created_at, updated_at
               FROM prompt_templates
              WHERE world_id = :wid OR (world_id IS NULL AND is_default = 1)
              ORDER BY world_id DESC, mode ASC, name ASC',
            ['wid' => $wid]
        );

        http_response_code(200);
        echo json_encode(['data' => $templates]);
    }

    // ─── POST /api/v1/worlds/:wid/prompt-templates ────────────────────────────

    public static function templateCreate(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'admin');

        $data = Validator::parseJson([
            'mode'       => 'required|string|in:entity_assist,arc_synthesiser,consistency_check,world_overview,custom',
            'name'       => 'required|string|max:128',
            'system_tpl' => 'required|string|max:8000',
            'user_tpl'   => 'required|string|max:4000',
        ]);

        $newId = DB::execute(
            'INSERT INTO prompt_templates (world_id, mode, name, system_tpl, user_tpl, created_by)
             VALUES (:wid, :mode, :name, :system_tpl, :user_tpl, :uid)',
            [
                'wid'        => $wid,
                'mode'       => $data['mode'],
                'name'       => $data['name'],
                'system_tpl' => $data['system_tpl'],
                'user_tpl'   => $data['user_tpl'],
                'uid'        => $userId,
            ]
        );

        self::writeAuditLog($wid, $userId, 'prompt_template.create', 'prompt_template', $newId, null, [
            'mode' => $data['mode'],
            'name' => $data['name'],
        ]);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $newId]]);
    }

    // ─── PATCH /api/v1/worlds/:wid/prompt-templates/:id ──────────────────────

    public static function templateUpdate(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];
        $tplId   = (int) $params['id'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'admin');

        // Only world-specific templates can be updated (world_id must match)
        $existing = DB::queryOne(
            'SELECT id, mode, name, system_tpl, user_tpl
               FROM prompt_templates
              WHERE id = :id AND world_id = :wid',
            ['id' => $tplId, 'wid' => $wid]
        );

        if ($existing === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        $data = Validator::parseJson([
            'name'       => 'string|max:128|nullable',
            'system_tpl' => 'string|max:8000|nullable',
            'user_tpl'   => 'string|max:4000|nullable',
        ]);

        $updates = [];
        $binds   = ['id' => $tplId, 'wid' => $wid];

        if (isset($data['name'])) {
            $updates[]       = 'name = :name';
            $binds['name']   = $data['name'];
        }
        if (isset($data['system_tpl'])) {
            $updates[]            = 'system_tpl = :system_tpl';
            $binds['system_tpl']  = $data['system_tpl'];
        }
        if (isset($data['user_tpl'])) {
            $updates[]          = 'user_tpl = :user_tpl';
            $binds['user_tpl']  = $data['user_tpl'];
        }

        if (empty($updates)) {
            http_response_code(200);
            echo json_encode(['data' => $existing]);
            return;
        }

        DB::execute(
            'UPDATE prompt_templates SET ' . implode(', ', $updates) . ' WHERE id = :id AND world_id = :wid',
            $binds
        );

        self::writeAuditLog($wid, $userId, 'prompt_template.update', 'prompt_template', $tplId, $existing, $data);

        http_response_code(200);
        echo json_encode(['data' => ['id' => $tplId]]);
    }

    // ─── DELETE /api/v1/worlds/:wid/prompt-templates/:id ─────────────────────

    public static function templateDestroy(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];
        $tplId   = (int) $params['id'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'admin');

        $existing = DB::queryOne(
            'SELECT id, name FROM prompt_templates WHERE id = :id AND world_id = :wid',
            ['id' => $tplId, 'wid' => $wid]
        );

        if ($existing === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        DB::execute(
            'DELETE FROM prompt_templates WHERE id = :id AND world_id = :wid',
            ['id' => $tplId, 'wid' => $wid]
        );

        self::writeAuditLog($wid, $userId, 'prompt_template.delete', 'prompt_template', $tplId, $existing, null);

        http_response_code(200);
        echo json_encode(['data' => ['deleted' => true]]);
    }

    // ─── Shared AI Pipeline ───────────────────────────────────────────────────

    /**
     * Core AI request pipeline shared by assist() and consistencyCheck().
     *
     * 1. Resolve API key
     * 2. Build context
     * 3. Load template and render user prompt
     * 4. Call Anthropic API
     * 5. Write ai_sessions row
     * 6. Write lore_notes row (ai_generated=1)
     * 7. Update world token counter
     * 8. Return response to client
     *
     * SECURITY: apiKey is NEVER returned in any response field.
     *           apiKey is NEVER written to any log.
     */
    private static function runAssist(
        int    $wid,
        int    $userId,
        int    $entityId,
        string $mode,
        string $userPrompt
    ): void {
        // 1. Resolve API key — throws ClaudeException with user-friendly message if missing
        try {
            $apiKey = Claude::resolveApiKey($wid);
        } catch (ClaudeException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage(), 'code' => 'AI_KEY_MISSING']);
            return;
        }

        // 2. Build context — pulls entity/world/relationship data from DB
        try {
            $context = Claude::buildContext($entityId, $wid, $mode);
        } catch (ClaudeException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage(), 'code' => 'NOT_FOUND']);
            return;
        }

        // 3. Load prompt template and render
        $tpl = Claude::loadTemplate($mode, $wid);
        if ($tpl !== null) {
            $vars = [
                'world'        => $context['world']  ?? [],
                'entity'       => $context['entity'] ?? [],
                'user_request' => $userPrompt,
            ];
            // Override system prompt if template provides one
            if (!empty($tpl['system_tpl'])) {
                $context['system'] = Claude::renderTemplate($tpl['system_tpl'], $vars);
            }
            // Render user turn through template if provided
            if (!empty($tpl['user_tpl'])) {
                $userPrompt = Claude::renderTemplate($tpl['user_tpl'], $vars);
            }
        }

        // Fetch model from world config
        $worldModel = $context['world']['ai_model'] ?? 'claude-sonnet-4-20250514';

        $sessionId    = null;
        $sessionStatus = 'success';
        $sessionError  = null;
        $result        = null;

        // 4. Call Anthropic API
        try {
            $result = Claude::callApi($context, $userPrompt, $apiKey, $worldModel);
        } catch (ClaudeException $e) {
            $sessionStatus = 'error';
            $sessionError  = mb_substr($e->getMessage(), 0, 512);

            // Record failed session before returning error
            $sessionId = self::writeSession(
                $wid, $userId, $entityId, $mode, $worldModel,
                0, 0, 0, hash('sha256', $userPrompt), 'error', $sessionError
            );

            $code = str_contains($e->getMessage(), 'API key') ? 'AI_KEY_INVALID' : 'INTERNAL_ERROR';
            http_response_code(502);
            echo json_encode(['error' => $e->getMessage(), 'code' => $code]);
            return;
        }

        // 5. Write ai_sessions row
        $promptHash = hash('sha256', $context['system'] . "\n\n" . $userPrompt);
        $sessionId  = self::writeSession(
            $wid, $userId, $entityId, $mode, $result['model'],
            $result['prompt_tokens'], $result['completion_tokens'], $result['total_tokens'],
            $promptHash, 'success', null
        );

        // 6. Write lore_notes row (ai_generated=1, links to session)
        $noteContent = $result['text'];
        $noteId = DB::execute(
            'INSERT INTO lore_notes (world_id, entity_id, created_by, content, ai_generated, ai_session_id)
             VALUES (:wid, :eid, :uid, :content, 1, :sid)',
            [
                'wid'     => $wid,
                'eid'     => $entityId > 0 ? $entityId : null,
                'uid'     => $userId,
                'content' => $noteContent,
                'sid'     => $sessionId,
            ]
        );

        // 7. Update world token usage counter (non-fatal if it fails)
        try {
            DB::execute(
                'UPDATE worlds
                    SET ai_tokens_used = ai_tokens_used + :tokens
                  WHERE id = :wid',
                ['tokens' => $result['total_tokens'], 'wid' => $wid]
            );
        } catch (\Throwable $e) {
            error_log('[AiController] Failed to update token counter for world ' . $wid . ': ' . $e->getMessage());
        }

        self::writeAuditLog($wid, $userId, 'ai.assist', 'ai_session', $sessionId, null, [
            'mode'             => $mode,
            'entity_id'        => $entityId > 0 ? $entityId : null,
            'model'            => $result['model'],
            'total_tokens'     => $result['total_tokens'],
        ]);

        // 8. Return to client — NEVER include api_key
        http_response_code(200);
        echo json_encode([
            'data' => [
                'text'              => $result['text'],
                'session_id'        => $sessionId,
                'note_id'           => $noteId,
                'entity_id'         => $entityId > 0 ? $entityId : null,
                'prompt_tokens'     => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'total_tokens'      => $result['total_tokens'],
                'model'             => $result['model'],
            ],
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private static function writeSession(
        int     $wid,
        int     $userId,
        int     $entityId,
        string  $mode,
        string  $model,
        int     $promptTokens,
        int     $completionTokens,
        int     $totalTokens,
        string  $promptHash,
        string  $status,
        ?string $errorMessage
    ): int {
        return DB::execute(
            'INSERT INTO ai_sessions
                (world_id, user_id, entity_id, mode, model,
                 prompt_tokens, completion_tokens, total_tokens,
                 prompt_hash, status, error_message)
             VALUES
                (:wid, :uid, :eid, :mode, :model,
                 :pt, :ct, :tt,
                 :hash, :status, :err)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'eid'    => $entityId > 0 ? $entityId : null,
                'mode'   => $mode,
                'model'  => $model,
                'pt'     => $promptTokens,
                'ct'     => $completionTokens,
                'tt'     => $totalTokens,
                'hash'   => $promptHash,
                'status' => $status,
                'err'    => $errorMessage,
            ]
        );
    }

    private static function writeAuditLog(
        int     $wid,
        int     $userId,
        string  $action,
        string  $targetType,
        ?int    $targetId,
        ?array  $before,
        ?array  $after
    ): void {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, diff_json)
             VALUES (:wid, :uid, :action, :type, :tid, :ip, :diff)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'action' => $action,
                'type'   => $targetType,
                'tid'    => $targetId,
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'diff'   => json_encode(['before' => $before, 'after' => $after]),
            ]
        );
    }
}
