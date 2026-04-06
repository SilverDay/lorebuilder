<?php
/**
 * LoreBuilder — Lore Note Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/notes                    — world-level notes
 *   /api/v1/worlds/:wid/entities/:id/notes       — entity notes
 *   /api/v1/worlds/:wid/notes/:nid               — note update / delete
 *   /api/v1/worlds/:wid/notes/:nid/promote       — canonical promotion
 *
 * Security checklist per endpoint:
 *   [x] Auth + Guard (minRole noted per method)
 *   [x] world_id scoping on every query
 *   [x] Validator on all input; no mass-assignment
 *   [x] PDO prepared statements
 *   [x] Audit log on mutations
 *   [x] Soft delete (deleted_at) — never hard DELETE
 */

declare(strict_types=1);

class NoteController
{
    // ─── GET /api/v1/worlds/:wid/notes ───────────────────────────────────────

    public static function worldNotes(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'canonical'    => 'nullable|bool',
            'ai_generated' => 'nullable|bool',
            'page'         => 'nullable|int|min:1',
            'per_page'     => 'nullable|int|min:1|max:100',
        ]);

        $page    = (int) ($q['page']     ?? 1);
        $perPage = (int) ($q['per_page'] ?? 30);
        $offset  = ($page - 1) * $perPage;

        // Four explicit query branches — no dynamic SQL
        if (isset($q['canonical']) && isset($q['ai_generated'])) {
            $notes = DB::query(
                'SELECT n.id, n.entity_id, n.content, n.is_canonical, n.ai_generated,
                        n.created_by, n.created_at, n.promoted_by, n.promoted_at,
                        u.display_name AS author_name, e.name AS entity_name
                   FROM lore_notes n
                   JOIN users u ON u.id = n.created_by
                   LEFT JOIN entities e ON e.id = n.entity_id AND e.deleted_at IS NULL
                  WHERE n.world_id = :wid AND n.deleted_at IS NULL
                    AND n.is_canonical = :canon AND n.ai_generated = :ai
                  ORDER BY n.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'canon' => (int) $q['canonical'],
                 'ai' => (int) $q['ai_generated'], 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM lore_notes WHERE world_id = :wid AND deleted_at IS NULL
                  AND is_canonical = :canon AND ai_generated = :ai',
                ['wid' => $wid, 'canon' => (int) $q['canonical'], 'ai' => (int) $q['ai_generated']]
            )['n'];
        } elseif (isset($q['canonical'])) {
            $notes = DB::query(
                'SELECT n.id, n.entity_id, n.content, n.is_canonical, n.ai_generated,
                        n.created_by, n.created_at, n.promoted_by, n.promoted_at,
                        u.display_name AS author_name, e.name AS entity_name
                   FROM lore_notes n
                   JOIN users u ON u.id = n.created_by
                   LEFT JOIN entities e ON e.id = n.entity_id AND e.deleted_at IS NULL
                  WHERE n.world_id = :wid AND n.deleted_at IS NULL
                    AND n.is_canonical = :canon
                  ORDER BY n.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'canon' => (int) $q['canonical'], 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM lore_notes WHERE world_id = :wid AND deleted_at IS NULL AND is_canonical = :canon',
                ['wid' => $wid, 'canon' => (int) $q['canonical']]
            )['n'];
        } elseif (isset($q['ai_generated'])) {
            $notes = DB::query(
                'SELECT n.id, n.entity_id, n.content, n.is_canonical, n.ai_generated,
                        n.created_by, n.created_at, n.promoted_by, n.promoted_at,
                        u.display_name AS author_name, e.name AS entity_name
                   FROM lore_notes n
                   JOIN users u ON u.id = n.created_by
                   LEFT JOIN entities e ON e.id = n.entity_id AND e.deleted_at IS NULL
                  WHERE n.world_id = :wid AND n.deleted_at IS NULL
                    AND n.ai_generated = :ai
                  ORDER BY n.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'ai' => (int) $q['ai_generated'], 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM lore_notes WHERE world_id = :wid AND deleted_at IS NULL AND ai_generated = :ai',
                ['wid' => $wid, 'ai' => (int) $q['ai_generated']]
            )['n'];
        } else {
            $notes = DB::query(
                'SELECT n.id, n.entity_id, n.content, n.is_canonical, n.ai_generated,
                        n.created_by, n.created_at, n.promoted_by, n.promoted_at,
                        u.display_name AS author_name, e.name AS entity_name
                   FROM lore_notes n
                   JOIN users u ON u.id = n.created_by
                   LEFT JOIN entities e ON e.id = n.entity_id AND e.deleted_at IS NULL
                  WHERE n.world_id = :wid AND n.deleted_at IS NULL
                  ORDER BY n.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM lore_notes WHERE world_id = :wid AND deleted_at IS NULL',
                ['wid' => $wid]
            )['n'];
        }

        http_response_code(200);
        echo json_encode([
            'data' => $notes,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                       'pages' => (int) ceil(max(1, $total) / $perPage)],
        ]);
    }

    // ─── GET /api/v1/worlds/:wid/entities/:id/notes ──────────────────────────

    public static function entityNotes(array $p): void
    {
        $wid    = (int) $p['wid'];
        $eid    = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $entity = DB::queryOne(
            'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $eid, 'wid' => $wid]
        );
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        $q = Validator::parseQuery([
            'canonical' => 'nullable|bool',
            'limit'     => 'nullable|int|min:1|max:100',
            'offset'    => 'nullable|int|min:0',
        ]);

        $where  = ['n.world_id = :wid', 'n.entity_id = :eid', 'n.deleted_at IS NULL'];
        $params = ['wid' => $wid, 'eid' => $eid];

        if (isset($q['canonical'])) {
            $where[]         = 'n.is_canonical = :canon';
            $params['canon'] = (int) $q['canonical'];
        }

        $limit  = (int) ($q['limit']  ?? 50);
        $offset = (int) ($q['offset'] ?? 0);

        $notes = DB::query(
            'SELECT n.id, n.entity_id, n.content, n.is_canonical, n.ai_generated,
                    n.created_by, n.created_at, n.promoted_by, n.promoted_at,
                    u.display_name AS author_name
               FROM lore_notes n
               JOIN users u ON u.id = n.created_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY n.is_canonical DESC, n.created_at DESC
              LIMIT :lim OFFSET :off',
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );

        Router::json($notes, 200, ['limit' => $limit, 'offset' => $offset, 'count' => count($notes)]);
    }

    // ─── POST /api/v1/worlds/:wid/entities/:id/notes ─────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $eid    = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $entity = DB::queryOne(
            'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $eid, 'wid' => $wid]
        );
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        $data = Validator::parseJson([
            'content' => 'required|string|min:1|max:65535',
        ]);

        $id = DB::execute(
            'INSERT INTO lore_notes (world_id, entity_id, created_by, content)
             VALUES (:wid, :eid, :uid, :content)',
            ['wid' => $wid, 'eid' => $eid, 'uid' => $userId, 'content' => $data['content']]
        );

        self::audit($wid, $userId, 'note.create', 'note', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    // ─── PATCH /api/v1/worlds/:wid/notes/:nid ────────────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $nid    = (int) $p['nid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $note = DB::queryOne(
            'SELECT id, created_by, ai_generated
               FROM lore_notes
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $nid, 'wid' => $wid]
        );
        if (!$note) {
            Router::jsonError(404, 'NOT_FOUND', 'Note not found.');
            return;
        }

        if ($note['ai_generated']) {
            Router::jsonError(403, 'FORBIDDEN', 'AI-generated notes cannot be edited directly.');
            return;
        }

        Guard::requireOwnerOrRole((int) $note['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'content' => 'required|string|min:1|max:65535',
        ]);

        DB::execute(
            'UPDATE lore_notes SET content = :content WHERE id = :id AND world_id = :wid',
            ['content' => $data['content'], 'id' => $nid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'note.update', 'note', $nid);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/notes/:nid ───────────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $nid    = (int) $p['nid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $note = DB::queryOne(
            'SELECT id, created_by FROM lore_notes
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $nid, 'wid' => $wid]
        );
        if (!$note) {
            Router::jsonError(404, 'NOT_FOUND', 'Note not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $note['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE lore_notes SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $nid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'note.delete', 'note', $nid);
        Router::json(['deleted' => true]);
    }

    // ─── POST /api/v1/worlds/:wid/notes/:nid/promote ─────────────────────────

    public static function promote(array $p): void
    {
        $wid    = (int) $p['wid'];
        $nid    = (int) $p['nid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $note = DB::queryOne(
            'SELECT id, is_canonical FROM lore_notes
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $nid, 'wid' => $wid]
        );
        if (!$note) {
            Router::jsonError(404, 'NOT_FOUND', 'Note not found.');
            return;
        }

        if ($note['is_canonical']) {
            Router::jsonError(409, 'CONFLICT', 'Note is already canonical.');
            return;
        }

        DB::execute(
            'UPDATE lore_notes
                SET is_canonical = 1, promoted_by = :uid, promoted_at = NOW()
              WHERE id = :id AND world_id = :wid',
            ['uid' => $userId, 'id' => $nid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'note.promote', 'note', $nid);
        Router::json(['promoted' => true]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private static function audit(int $wid, int $userId, string $action,
        ?string $tt = null, ?int $tid = null): void
    {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent)
             VALUES (:wid, :uid, :action, :tt, :tid, :ip, :ua)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'action' => $action,
                'tt'     => $tt,
                'tid'    => $tid,
                'ip'     => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            ]
        );
    }
}
