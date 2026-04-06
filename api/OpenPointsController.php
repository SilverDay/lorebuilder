<?php
/**
 * LoreBuilder — Open Points Controller
 *
 * Unresolved questions, plot holes, and clarifications scoped to a world.
 * Typically created manually by authors or automatically from AI sessions.
 *
 * Endpoints:
 *   GET    /api/v1/worlds/:wid/open-points             — list; filter by status/priority/entity
 *   POST   /api/v1/worlds/:wid/open-points             — create
 *   GET    /api/v1/worlds/:wid/open-points/:opid       — detail
 *   PATCH  /api/v1/worlds/:wid/open-points/:opid       — update (including resolve)
 *   DELETE /api/v1/worlds/:wid/open-points/:opid       — soft delete
 */

declare(strict_types=1);

class OpenPointsController
{
    private const VALID_STATUSES  = ['open','in_progress','resolved','wont_fix'];
    private const VALID_PRIORITIES = ['low','medium','high','critical'];

    // ─── GET /api/v1/worlds/:wid/open-points ─────────────────────────────────

    public static function index(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'viewer');

        $q = Validator::parseQuery([
            'status'    => 'string|in:open,in_progress,resolved,wont_fix|nullable',
            'priority'  => 'string|in:low,medium,high,critical|nullable',
            'entity_id' => 'int|min:1|nullable',
            'search'    => 'string|max:200|nullable',
            'page'      => 'int|min:1',
            'per_page'  => 'int|min:1|max:100',
        ]);

        $page    = $q['page']     ?? 1;
        $perPage = $q['per_page'] ?? 30;
        $offset  = ($page - 1) * $perPage;

        // Build query branches — no dynamic SQL concatenation
        if (isset($q['search'])) {
            $rows = DB::query(
                'SELECT op.id, op.title, op.description, op.status, op.priority,
                        op.entity_id, op.ai_session_id, op.resolution,
                        op.resolved_at, op.created_at,
                        u.display_name AS creator_name,
                        e.name AS entity_name
                   FROM open_points op
                   JOIN users u ON u.id = op.created_by
                   LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
                  WHERE op.world_id = :wid AND op.deleted_at IS NULL
                    AND MATCH(op.title, op.description) AGAINST(:q IN BOOLEAN MODE)
                  ORDER BY FIELD(op.priority,"critical","high","medium","low"), op.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'q' => $q['search'] . '*', 'lim' => $perPage, 'off' => $offset]
            );
            $total = count($rows);
        } elseif (isset($q['status']) && isset($q['priority'])) {
            $rows = DB::query(
                'SELECT op.id, op.title, op.description, op.status, op.priority,
                        op.entity_id, op.ai_session_id, op.resolution,
                        op.resolved_at, op.created_at,
                        u.display_name AS creator_name,
                        e.name AS entity_name
                   FROM open_points op
                   JOIN users u ON u.id = op.created_by
                   LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
                  WHERE op.world_id = :wid AND op.status = :status AND op.priority = :priority
                    AND op.deleted_at IS NULL
                  ORDER BY op.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'status' => $q['status'], 'priority' => $q['priority'],
                 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM open_points WHERE world_id = :wid AND status = :status AND priority = :priority AND deleted_at IS NULL',
                ['wid' => $wid, 'status' => $q['status'], 'priority' => $q['priority']]
            )['n'];
        } elseif (isset($q['status'])) {
            $rows = DB::query(
                'SELECT op.id, op.title, op.description, op.status, op.priority,
                        op.entity_id, op.ai_session_id, op.resolution,
                        op.resolved_at, op.created_at,
                        u.display_name AS creator_name,
                        e.name AS entity_name
                   FROM open_points op
                   JOIN users u ON u.id = op.created_by
                   LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
                  WHERE op.world_id = :wid AND op.status = :status AND op.deleted_at IS NULL
                  ORDER BY FIELD(op.priority,"critical","high","medium","low"), op.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'status' => $q['status'], 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM open_points WHERE world_id = :wid AND status = :status AND deleted_at IS NULL',
                ['wid' => $wid, 'status' => $q['status']]
            )['n'];
        } elseif (isset($q['entity_id'])) {
            $eid = (int) $q['entity_id'];
            $rows = DB::query(
                'SELECT op.id, op.title, op.description, op.status, op.priority,
                        op.entity_id, op.ai_session_id, op.resolution,
                        op.resolved_at, op.created_at,
                        u.display_name AS creator_name,
                        e.name AS entity_name
                   FROM open_points op
                   JOIN users u ON u.id = op.created_by
                   LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
                  WHERE op.world_id = :wid AND op.entity_id = :eid AND op.deleted_at IS NULL
                  ORDER BY FIELD(op.priority,"critical","high","medium","low"), op.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'eid' => $eid, 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM open_points WHERE world_id = :wid AND entity_id = :eid AND deleted_at IS NULL',
                ['wid' => $wid, 'eid' => $eid]
            )['n'];
        } else {
            $rows = DB::query(
                'SELECT op.id, op.title, op.description, op.status, op.priority,
                        op.entity_id, op.ai_session_id, op.resolution,
                        op.resolved_at, op.created_at,
                        u.display_name AS creator_name,
                        e.name AS entity_name
                   FROM open_points op
                   JOIN users u ON u.id = op.created_by
                   LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
                  WHERE op.world_id = :wid AND op.deleted_at IS NULL
                  ORDER BY FIELD(op.priority,"critical","high","medium","low"), op.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM open_points WHERE world_id = :wid AND deleted_at IS NULL',
                ['wid' => $wid]
            )['n'];
        }

        http_response_code(200);
        echo json_encode([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage,
                       'pages' => (int) ceil(max(1, $total) / $perPage)],
        ]);
    }

    // ─── POST /api/v1/worlds/:wid/open-points ────────────────────────────────

    public static function create(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $data = Validator::parseJson([
            'title'         => 'required|string|min:1|max:512',
            'description'   => 'string|max:4000|nullable',
            'status'        => 'string|in:open,in_progress,resolved,wont_fix|nullable',
            'priority'      => 'string|in:low,medium,high,critical|nullable',
            'entity_id'     => 'int|min:1|nullable',
            'ai_session_id' => 'int|min:1|nullable',
        ]);

        // Validate entity belongs to this world if provided
        if (!empty($data['entity_id'])) {
            $ent = DB::queryOne(
                'SELECT id FROM entities WHERE id = :eid AND world_id = :wid AND deleted_at IS NULL',
                ['eid' => (int) $data['entity_id'], 'wid' => $wid]
            );
            if (!$ent) {
                http_response_code(422);
                echo json_encode(['error' => 'Entity not found in this world.', 'code' => 'NOT_FOUND']);
                return;
            }
        }

        $newId = DB::execute(
            'INSERT INTO open_points (world_id, created_by, entity_id, ai_session_id, title, description, status, priority)
             VALUES (:wid, :uid, :eid, :sid, :title, :desc, :status, :priority)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'eid'    => !empty($data['entity_id'])     ? (int) $data['entity_id']     : null,
                'sid'    => !empty($data['ai_session_id']) ? (int) $data['ai_session_id'] : null,
                'title'  => $data['title'],
                'desc'   => $data['description'] ?? null,
                'status' => $data['status']       ?? 'open',
                'priority' => $data['priority']   ?? 'medium',
            ]
        );

        self::audit($wid, $userId, 'open_point.create', $newId);
        http_response_code(201);
        echo json_encode(['data' => ['id' => $newId]]);
    }

    // ─── GET /api/v1/worlds/:wid/open-points/:opid ───────────────────────────

    public static function show(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $opid    = (int) $p['opid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'viewer');

        $row = DB::queryOne(
            'SELECT op.*, u.display_name AS creator_name,
                    e.name AS entity_name,
                    r.display_name AS resolver_name
               FROM open_points op
               JOIN users u ON u.id = op.created_by
               LEFT JOIN entities e ON e.id = op.entity_id AND e.deleted_at IS NULL
               LEFT JOIN users r ON r.id = op.resolved_by
              WHERE op.id = :opid AND op.world_id = :wid AND op.deleted_at IS NULL',
            ['opid' => $opid, 'wid' => $wid]
        );

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Open point not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        http_response_code(200);
        echo json_encode(['data' => $row]);
    }

    // ─── PATCH /api/v1/worlds/:wid/open-points/:opid ─────────────────────────

    public static function update(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $opid    = (int) $p['opid'];

        $membership = Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $existing = DB::queryOne(
            'SELECT id, created_by, status FROM open_points WHERE id = :opid AND world_id = :wid AND deleted_at IS NULL',
            ['opid' => $opid, 'wid' => $wid]
        );
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Open point not found.', 'code' => 'NOT_FOUND']);
            return;
        }
        Guard::requireOwnerOrRole((int) $existing['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'title'       => 'string|min:1|max:512|nullable',
            'description' => 'string|max:4000|nullable',
            'status'      => 'string|in:open,in_progress,resolved,wont_fix|nullable',
            'priority'    => 'string|in:low,medium,high,critical|nullable',
            'resolution'  => 'string|max:4000|nullable',
            'entity_id'   => 'int|min:1|nullable',
        ]);

        $sets  = [];
        $binds = ['opid' => $opid, 'wid' => $wid];

        if (isset($data['title']))       { $sets[] = 'title = :title';      $binds['title']    = $data['title']; }
        if (isset($data['status']))      { $sets[] = 'status = :status';    $binds['status']   = $data['status']; }
        if (isset($data['priority']))    { $sets[] = 'priority = :priority';$binds['priority'] = $data['priority']; }
        if (array_key_exists('description', $data)) { $sets[] = 'description = :desc';      $binds['desc']   = $data['description']; }
        if (array_key_exists('resolution', $data))  { $sets[] = 'resolution = :resolution'; $binds['resolution'] = $data['resolution']; }
        if (array_key_exists('entity_id', $data))   { $sets[] = 'entity_id = :eid';         $binds['eid']    = $data['entity_id']; }

        // Auto-set resolved fields when status transitions to resolved/wont_fix
        if (isset($data['status']) && in_array($data['status'], ['resolved', 'wont_fix'], true)
            && !in_array($existing['status'], ['resolved', 'wont_fix'], true)) {
            $sets[] = 'resolved_by = :resolver';
            $sets[] = 'resolved_at = NOW()';
            $binds['resolver'] = $userId;
        }

        // Clear resolved fields when re-opening
        if (isset($data['status']) && in_array($data['status'], ['open', 'in_progress'], true)
            && in_array($existing['status'], ['resolved', 'wont_fix'], true)) {
            $sets[] = 'resolved_by = NULL';
            $sets[] = 'resolved_at = NULL';
        }

        if (!empty($sets)) {
            DB::execute(
                'UPDATE open_points SET ' . implode(', ', $sets) . ' WHERE id = :opid AND world_id = :wid',
                $binds
            );
            self::audit($wid, $userId, 'open_point.update', $opid);
        }

        http_response_code(200);
        echo json_encode(['data' => ['id' => $opid]]);
    }

    // ─── DELETE /api/v1/worlds/:wid/open-points/:opid ────────────────────────

    public static function destroy(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $opid    = (int) $p['opid'];

        $membership = Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $existing = DB::queryOne(
            'SELECT id, created_by FROM open_points WHERE id = :opid AND world_id = :wid AND deleted_at IS NULL',
            ['opid' => $opid, 'wid' => $wid]
        );
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Open point not found.', 'code' => 'NOT_FOUND']);
            return;
        }
        Guard::requireOwnerOrRole((int) $existing['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE open_points SET deleted_at = NOW() WHERE id = :opid AND world_id = :wid',
            ['opid' => $opid, 'wid' => $wid]
        );
        self::audit($wid, $userId, 'open_point.delete', $opid);
        http_response_code(200);
        echo json_encode(['data' => ['deleted' => true]]);
    }

    private static function audit(int $wid, int $userId, string $action, int $targetId): void
    {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address)
             VALUES (:wid, :uid, :action, :type, :tid, :ip)',
            ['wid' => $wid, 'uid' => $userId, 'action' => $action,
             'type' => 'open_point', 'tid' => $targetId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]
        );
    }
}
