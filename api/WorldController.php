<?php

/**
 * LoreBuilder — World & Membership Controller
 *
 * Handles:
 *   /api/v1/worlds/*                     — world CRUD
 *   /api/v1/worlds/:wid/members/*        — membership management
 *   /api/v1/worlds/:wid/invitations/*    — email invitations
 *   /api/v1/invitations/:token/*         — public invitation acceptance
 *   /api/v1/worlds/:wid/settings/ai/*    — AI key management
 *
 * Security checklist per endpoint:
 *   [x] Auth::requireSession() via Router middleware
 *   [x] Guard::requireWorldAccess() with appropriate minRole
 *   [x] Validator::parseJson() / parseQuery() on all input
 *   [x] PDO prepared statements (DB::*)
 *   [x] CSRF enforced on state-changing endpoints via Router
 *   [x] AI key NEVER returned in any response
 *   [x] Audit log written on all mutations
 */

declare(strict_types=1);

class WorldController
{
    // ─── GET /api/v1/worlds ───────────────────────────────────────────────────

    public static function index(array $p): void
    {
        $userId = $p['user']['id'];

        $worlds = DB::query(
            'SELECT w.id, w.slug, w.name, w.description, w.genre, w.tone,
                    w.status, w.ai_key_mode, w.ai_key_fingerprint,
                    w.ai_token_budget, w.ai_tokens_used, w.created_at,
                    wm.role
               FROM worlds w
               JOIN world_members wm ON wm.world_id = w.id AND wm.user_id = :uid
                                    AND wm.deleted_at IS NULL
              WHERE w.deleted_at IS NULL
              ORDER BY w.name ASC',
            ['uid' => $userId]
        );

        Router::json($worlds);
    }

    // ─── POST /api/v1/worlds ──────────────────────────────────────────────────

    public static function create(array $p): void
    {
        $userId = $p['user']['id'];

        $data = Validator::parseJson([
            'slug'             => 'required|slug|max:128',
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string|max:5000',
            'genre'            => 'nullable|string|max:128',
            'tone'             => 'nullable|string|max:128',
            'era_system'       => 'nullable|string|max:255',
            'content_warnings' => 'nullable|string|max:1000',
        ]);

        if (DB::queryOne('SELECT id FROM worlds WHERE slug = :s AND deleted_at IS NULL', ['s' => $data['slug']])) {
            Router::jsonError(409, 'CONFLICT', 'A world with that slug already exists.');
            return;
        }

        $worldId = DB::transaction(function () use ($data, $userId): int {
            $wid = DB::execute(
                'INSERT INTO worlds (owner_id, slug, name, description, genre, tone, era_system, content_warnings)
                 VALUES (:owner, :slug, :name, :desc, :genre, :tone, :era, :cw)',
                [
                    'owner' => $userId,
                    'slug'  => $data['slug'],
                    'name'  => $data['name'],
                    'desc'  => $data['description'] ?? null,
                    'genre' => $data['genre']        ?? null,
                    'tone'  => $data['tone']         ?? null,
                    'era'   => $data['era_system']   ?? null,
                    'cw'    => $data['content_warnings'] ?? null,
                ]
            );

            // Creator automatically becomes owner
            DB::execute(
                'INSERT INTO world_members (world_id, user_id, role, joined_at)
                 VALUES (:wid, :uid, :role, NOW())',
                ['wid' => $wid, 'uid' => $userId, 'role' => 'owner']
            );

            return $wid;
        });

        self::audit($worldId, $userId, 'world.create', 'world', $worldId);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $worldId, 'slug' => $data['slug']]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/worlds/:wid ──────────────────────────────────────────────

    public static function show(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $world = DB::queryOne(
            'SELECT id, slug, name, description, genre, tone, era_system,
                    content_warnings, ai_key_mode, ai_key_fingerprint, ai_model,
                    ai_token_budget, ai_tokens_used, ai_budget_resets_at,
                    is_public, status, owner_id, created_at, updated_at
               FROM worlds WHERE id = :id AND deleted_at IS NULL',
            ['id' => $wid]
        );

        if (!$world) {
            Router::jsonError(404, 'NOT_FOUND', 'World not found.');
            return;
        }

        $world['your_role'] = $membership['role'];
        Router::json($world);
    }

    // ─── PATCH /api/v1/worlds/:wid ────────────────────────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $data = Validator::parseJson([
            'name'             => 'nullable|string|max:255',
            'description'      => 'nullable|string|max:5000',
            'genre'            => 'nullable|string|max:128',
            'tone'             => 'nullable|string|max:128',
            'era_system'       => 'nullable|string|max:255',
            'content_warnings' => 'nullable|string|max:1000',
            'status'           => 'nullable|in:active,archived',
            'ai_model'         => 'nullable|string|max:64',
            'ai_provider'      => 'nullable|string|max:32',
            'ai_endpoint_url'  => 'nullable|string|max:512',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        // Validate Ollama endpoint URL for SSRF prevention
        if (isset($data['ai_endpoint_url']) && $data['ai_endpoint_url'] !== null && $data['ai_endpoint_url'] !== '') {
            try {
                OllamaProvider::validateEndpoint($data['ai_endpoint_url']);
            } catch (AiProviderException $e) {
                Router::jsonError(400, 'VALIDATION_ERROR', $e->getMessage());
                return;
            }
        }

        $sets   = [];
        $params = ['id' => $wid];

        $allowed = ['name', 'description', 'genre', 'tone', 'era_system', 'content_warnings', 'status', 'ai_model', 'ai_provider', 'ai_endpoint_url'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        DB::execute('UPDATE worlds SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        self::audit($wid, $userId, 'world.update', 'world', $wid, $data);

        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid ───────────────────────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'owner', $isPlatformAdmin);

        DB::execute(
            'UPDATE worlds SET deleted_at = NOW() WHERE id = :id',
            ['id' => $wid]
        );

        self::audit($wid, $userId, 'world.delete', 'world', $wid);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/members ─────────────────────────────────────

    public static function members(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $members = DB::query(
            "SELECT wm.id, wm.user_id, wm.role, wm.joined_at,
                    u.username, u.display_name, u.email
               FROM world_members wm
               JOIN users u ON u.id = wm.user_id AND u.deleted_at IS NULL
              WHERE wm.world_id = :wid AND wm.deleted_at IS NULL
              ORDER BY FIELD(wm.role, 'owner','admin','author','reviewer','viewer'), u.display_name",
            ['wid' => $wid]
        );

        Router::json($members);
    }

    // ─── PATCH /api/v1/worlds/:wid/members/:uid ───────────────────────────────

    public static function updateMember(array $p): void
    {
        $wid       = (int) $p['wid'];
        $targetUid = (int) $p['uid'];
        $actorId   = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $actorId, 'admin', $isPlatformAdmin);
        $actorRole  = $membership['role'];

        $data = Validator::parseJson([
            'role' => 'required|in:admin,author,reviewer,viewer',
        ]);

        $target = Guard::worldMembership($wid, $targetUid);
        if (!$target) {
            Router::jsonError(404, 'NOT_FOUND', 'That user is not a member of this world.');
            return;
        }

        if ($target['role'] === 'owner') {
            Router::jsonError(403, 'FORBIDDEN', 'The world owner\'s role cannot be changed.');
            return;
        }

        // Only owner can promote to/demote from admin
        if (($data['role'] === 'admin' || $target['role'] === 'admin') && $actorRole !== 'owner' && !$isPlatformAdmin) {
            Router::jsonError(403, 'FORBIDDEN', 'Only the world owner can manage admin roles.');
            return;
        }

        DB::execute(
            'UPDATE world_members SET role = :role WHERE world_id = :wid AND user_id = :uid',
            ['role' => $data['role'], 'wid' => $wid, 'uid' => $targetUid]
        );

        self::audit(
            $wid,
            $actorId,
            'world.member.role_change',
            'user',
            $targetUid,
            ['old_role' => $target['role'], 'new_role' => $data['role']]
        );

        Router::json(['updated' => true, 'role' => $data['role']]);
    }

    // ─── DELETE /api/v1/worlds/:wid/members/:uid ──────────────────────────────

    public static function removeMember(array $p): void
    {
        $wid       = (int) $p['wid'];
        $targetUid = (int) $p['uid'];
        $actorId   = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $actorId, 'admin', $isPlatformAdmin);

        $target = Guard::worldMembership($wid, $targetUid);
        if (!$target) {
            Router::jsonError(404, 'NOT_FOUND', 'That user is not a member of this world.');
            return;
        }

        if ($target['role'] === 'owner') {
            Router::jsonError(403, 'FORBIDDEN', 'The world owner cannot be removed.');
            return;
        }

        if ($target['role'] === 'admin' && $membership['role'] !== 'owner' && !$isPlatformAdmin) {
            Router::jsonError(403, 'FORBIDDEN', 'Only the world owner can remove admins.');
            return;
        }

        DB::execute(
            'UPDATE world_members SET deleted_at = NOW() WHERE world_id = :wid AND user_id = :uid',
            ['wid' => $wid, 'uid' => $targetUid]
        );

        self::audit($wid, $actorId, 'world.member.remove', 'user', $targetUid);
        Router::json(['removed' => true]);
    }

    // ─── POST /api/v1/worlds/:wid/invitations ────────────────────────────────

    public static function invite(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $data = Validator::parseJson([
            'email' => 'required|email',
            'role'  => 'required|in:admin,author,reviewer,viewer',
        ]);

        $existing = DB::queryOne(
            'SELECT wm.id FROM world_members wm
               JOIN users u ON u.id = wm.user_id
              WHERE wm.world_id = :wid AND u.email = :email AND wm.deleted_at IS NULL',
            ['wid' => $wid, 'email' => $data['email']]
        );
        if ($existing) {
            Router::jsonError(409, 'CONFLICT', 'That person is already a member of this world.');
            return;
        }

        $token   = Auth::generateToken(32);
        $expires = date('Y-m-d H:i:s', time() + 86400 * 7);

        DB::execute(
            'INSERT INTO world_invitations (world_id, invited_by, email, role, token, expires_at)
             VALUES (:wid, :by, :email, :role, :token, :exp)',
            [
                'wid' => $wid,
                'by' => $userId,
                'email' => $data['email'],
                'role' => $data['role'],
                'token' => $token,
                'exp' => $expires
            ]
        );

        self::sendInvitationEmail($wid, $data['email'], $data['role'], $token);
        self::audit(
            $wid,
            $userId,
            'world.invitation.send',
            'world',
            $wid,
            ['email' => $data['email'], 'role' => $data['role']]
        );

        http_response_code(201);
        echo json_encode(['data' => ['invited' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/invitations/:token ──────────────────────────────────────

    public static function showInvitation(array $p): void
    {
        $inv = DB::queryOne(
            'SELECT i.id, i.world_id, i.email, i.role, i.expires_at,
                    w.name AS world_name, w.genre, w.slug AS world_slug
               FROM world_invitations i
               JOIN worlds w ON w.id = i.world_id AND w.deleted_at IS NULL
              WHERE i.token = :t AND i.accepted_at IS NULL AND i.expires_at > NOW()',
            ['t' => $p['token']]
        );

        if (!$inv) {
            Router::jsonError(404, 'NOT_FOUND', 'This invitation link is invalid or has expired.');
            return;
        }

        Router::json($inv);
    }

    // ─── POST /api/v1/invitations/:token/accept ───────────────────────────────

    public static function acceptInvitation(array $p): void
    {
        $userId = $p['user']['id'];

        $inv = DB::queryOne(
            'SELECT * FROM world_invitations
              WHERE token = :t AND accepted_at IS NULL AND expires_at > NOW()',
            ['t' => $p['token']]
        );

        if (!$inv) {
            Router::jsonError(404, 'NOT_FOUND', 'This invitation link is invalid or has expired.');
            return;
        }

        $user = DB::queryOne('SELECT email FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user || strtolower($user['email']) !== strtolower($inv['email'])) {
            Router::jsonError(403, 'FORBIDDEN', 'This invitation was sent to a different email address.');
            return;
        }

        DB::transaction(function () use ($inv, $userId): void {
            DB::execute(
                'INSERT INTO world_members (world_id, user_id, role, invited_by, joined_at)
                 VALUES (:wid, :uid, :role, :by, NOW())
                 ON DUPLICATE KEY UPDATE
                     role = VALUES(role), deleted_at = NULL, joined_at = NOW()',
                [
                    'wid' => $inv['world_id'],
                    'uid' => $userId,
                    'role' => $inv['role'],
                    'by' => $inv['invited_by']
                ]
            );

            DB::execute(
                'UPDATE world_invitations SET accepted_at = NOW() WHERE id = :id',
                ['id' => $inv['id']]
            );
        });

        self::audit((int) $inv['world_id'], $userId, 'world.invitation.accept', 'world', (int) $inv['world_id']);
        Router::json(['joined' => true, 'world_id' => (int) $inv['world_id'], 'role' => $inv['role']]);
    }

    // ─── GET /api/v1/worlds/:wid/settings/ai ─────────────────────────────────

    public static function aiSettings(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'owner', $isPlatformAdmin);

        $world = DB::queryOne(
            'SELECT ai_key_mode, ai_key_fingerprint, ai_model,
                    ai_token_budget, ai_tokens_used, ai_budget_resets_at
               FROM worlds WHERE id = :id AND deleted_at IS NULL',
            ['id' => $wid]
        );

        if (!$world) {
            Router::jsonError(404, 'NOT_FOUND', 'World not found.');
            return;
        }

        // ai_key_enc is NEVER included in the response
        Router::json($world);
    }

    // ─── PUT /api/v1/worlds/:wid/settings/ai/key ─────────────────────────────

    public static function saveAiKey(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'owner', $isPlatformAdmin);

        $data = Validator::parseJson([
            'api_key'  => 'required|string|min:20|max:512',
            'key_mode' => 'nullable|in:user,platform',
        ]);

        $plaintextKey = $data['api_key'];
        $encrypted    = Crypto::encryptApiKey($plaintextKey, APP_SECRET);
        $fingerprint  = Crypto::apiKeyFingerprint($plaintextKey);
        $mode         = $data['key_mode'] ?? 'user';

        DB::execute(
            'UPDATE worlds
                SET ai_key_enc = :enc, ai_key_fingerprint = :fp, ai_key_mode = :mode
              WHERE id = :id',
            ['enc' => $encrypted, 'fp' => $fingerprint, 'mode' => $mode, 'id' => $wid]
        );

        self::audit($wid, $userId, 'world.ai_key.save', 'world', $wid);

        // NEVER return the key or the encrypted blob
        Router::json(['saved' => true, 'fingerprint' => $fingerprint]);
    }

    // ─── DELETE /api/v1/worlds/:wid/settings/ai/key ──────────────────────────

    public static function deleteAiKey(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'owner', $isPlatformAdmin);

        DB::execute(
            'UPDATE worlds SET ai_key_enc = NULL, ai_key_fingerprint = NULL WHERE id = :id',
            ['id' => $wid]
        );

        self::audit($wid, $userId, 'world.ai_key.delete', 'world', $wid);
        Router::json(['deleted' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function sendInvitationEmail(int $worldId, string $email, string $role, string $token): void
    {
        $world = DB::queryOne('SELECT name FROM worlds WHERE id = :id', ['id' => $worldId]);
        $name  = str_replace(["\r", "\n"], ' ', $world['name'] ?? 'a LoreBuilder world');
        $url   = rtrim(APP_URL, '/') . '/invitations/' . urlencode($token);

        $from     = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@localhost';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'LoreBuilder';
        $subject  = "You've been invited to join \"{$name}\" on LoreBuilder";
        $body     = "You've been invited to join \"{$name}\" as a {$role}.\n\n"
            . "Accept your invitation:\n{$url}\n\n"
            . "This link expires in 7 days.\n\nLoreBuilder";
        $headers  = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        mail($email, $subject, $body, $headers);
    }

    // ─── GET /api/v1/worlds/:wid/audit-log ───────────────────────────────────

    public static function auditLog(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'limit'  => 'nullable|int|min:1|max:100',
            'offset' => 'nullable|int|min:0',
            'action' => 'nullable|string|max:64',
        ]);

        $where  = ['al.world_id = :wid'];
        $params = ['wid' => $wid];

        if (!empty($q['action'])) {
            $where[]          = 'al.action = :action';
            $params['action'] = $q['action'];
        }

        $limit  = (int) ($q['limit']  ?? 50);
        $offset = (int) ($q['offset'] ?? 0);

        $entries = DB::query(
            'SELECT al.id, al.action, al.target_type, al.target_id,
                    al.ip_address, al.created_at, al.diff_json,
                    u.display_name AS actor_name, u.username AS actor_username
               FROM audit_log al
               LEFT JOIN users u ON u.id = al.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY al.created_at DESC
              LIMIT :lim OFFSET :off',
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );

        $total = (int) DB::queryOne(
            'SELECT COUNT(*) AS c FROM audit_log al WHERE ' . implode(' AND ', $where),
            $params
        )['c'];

        // Parse diff_json back to array for the response
        foreach ($entries as &$entry) {
            if ($entry['diff_json'] !== null) {
                $entry['diff'] = json_decode($entry['diff_json'], true);
            } else {
                $entry['diff'] = null;
            }
            unset($entry['diff_json']);
        }
        unset($entry);

        Router::json($entries, meta: ['total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    // ─── GET /api/v1/worlds/:wid/stats ────────────────────────────────────────

    /**
     * Returns entity counts by type and recent arc status — used by DashboardView.
     * Guard: viewer (read-only stats).
     */
    public static function stats(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        // Entity counts by type
        $entityCounts = DB::query(
            'SELECT type, status, COUNT(*) AS count
               FROM entities
              WHERE world_id = :wid AND deleted_at IS NULL
              GROUP BY type, status
              ORDER BY type, status',
            ['wid' => $wid]
        );

        // Arc summary
        $arcSummary = DB::query(
            'SELECT status, COUNT(*) AS count
               FROM story_arcs
              WHERE world_id = :wid AND deleted_at IS NULL
              GROUP BY status',
            ['wid' => $wid]
        );

        // Recent activity (last 10 audit entries — viewer-safe fields only)
        $recent = DB::query(
            'SELECT al.action, al.target_type, al.target_id, al.created_at,
                    u.display_name AS actor_name
               FROM audit_log al
               LEFT JOIN users u ON u.id = al.user_id
              WHERE al.world_id = :wid
              ORDER BY al.created_at DESC
              LIMIT 10',
            ['wid' => $wid]
        );

        Router::json([
            'entity_counts' => $entityCounts,
            'arc_summary'   => $arcSummary,
            'recent_activity' => $recent,
        ]);
    }

    private static function audit(
        ?int $worldId,
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $diff = null
    ): void {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent, diff_json)
             VALUES (:wid, :uid, :action, :ttype, :tid, :ip, :ua, :diff)',
            [
                'wid'    => $worldId,
                'uid'    => $userId,
                'action' => $action,
                'ttype'  => $targetType,
                'tid'    => $targetId,
                'ip'     => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'diff'   => $diff ? json_encode($diff) : null,
            ]
        );
    }
}
