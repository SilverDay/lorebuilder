<?php
/**
 * LoreBuilder — Story Arc Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/story-arcs/*               — story arc CRUD
 *   /api/v1/worlds/:wid/story-arcs/:aid/entities   — arc entity membership
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

class StoryArcController
{
    private const VALID_STATUSES = ['seed', 'rising_action', 'climax', 'resolution', 'complete', 'abandoned'];

    // ─── GET /api/v1/worlds/:wid/story-arcs ──────────────────────────────────

    public static function index(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'status' => 'nullable|in:' . implode(',', self::VALID_STATUSES),
        ]);

        $where  = ['a.world_id = :wid', 'a.deleted_at IS NULL'];
        $params = ['wid' => $wid];

        if (!empty($q['status'])) {
            $where[]          = 'a.status = :status';
            $params['status'] = $q['status'];
        }

        $arcs = DB::query(
            'SELECT a.id, a.name, a.logline, a.theme, a.status, a.sort_order,
                    a.ai_synopsis_at, a.created_by, a.created_at,
                    COUNT(ae.entity_id) AS entity_count
               FROM story_arcs a
               LEFT JOIN arc_entities ae ON ae.arc_id = a.id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY a.id
              ORDER BY a.sort_order ASC, a.name ASC',
            $params
        );

        Router::json($arcs);
    }

    // ─── POST /api/v1/worlds/:wid/story-arcs ─────────────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'name'       => 'required|string|max:255',
            'logline'    => 'nullable|string|max:512',
            'theme'      => 'nullable|string|max:255',
            'status'     => 'nullable|in:' . implode(',', self::VALID_STATUSES),
            'sort_order' => 'nullable|int|min:0|max:32767',
        ]);

        $id = DB::execute(
            'INSERT INTO story_arcs (world_id, created_by, name, logline, theme, status, sort_order)
             VALUES (:wid, :uid, :name, :logline, :theme, :status, :sort)',
            [
                'wid'     => $wid,
                'uid'     => $userId,
                'name'    => $data['name'],
                'logline' => $data['logline']    ?? null,
                'theme'   => $data['theme']      ?? null,
                'status'  => $data['status']     ?? 'seed',
                'sort'    => $data['sort_order'] ?? 0,
            ]
        );

        self::audit($wid, $userId, 'arc.create', 'story_arc', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/worlds/:wid/story-arcs/:aid ─────────────────────────────

    public static function show(array $p): void
    {
        $wid    = (int) $p['wid'];
        $aid    = (int) $p['aid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $arc = DB::queryOne(
            'SELECT id, name, logline, theme, status, sort_order,
                    ai_synopsis, ai_synopsis_at, created_by, created_at, updated_at
               FROM story_arcs
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $aid, 'wid' => $wid]
        );
        if (!$arc) {
            Router::jsonError(404, 'NOT_FOUND', 'Story arc not found.');
            return;
        }

        $entities = DB::query(
            'SELECT ae.entity_id, ae.role, ae.notes, ae.sort_order,
                    e.name AS entity_name, e.type AS entity_type, e.status AS entity_status
               FROM arc_entities ae
               JOIN entities e ON e.id = ae.entity_id AND e.deleted_at IS NULL
              WHERE ae.arc_id = :aid AND ae.world_id = :wid
              ORDER BY ae.sort_order ASC, e.name ASC',
            ['aid' => $aid, 'wid' => $wid]
        );

        $arc['entities'] = $entities;
        Router::json($arc);
    }

    // ─── PATCH /api/v1/worlds/:wid/story-arcs/:aid ───────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $aid    = (int) $p['aid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $arc = DB::queryOne(
            'SELECT id, created_by FROM story_arcs
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $aid, 'wid' => $wid]
        );
        if (!$arc) {
            Router::jsonError(404, 'NOT_FOUND', 'Story arc not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $arc['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'name'       => 'nullable|string|max:255',
            'logline'    => 'nullable|string|max:512',
            'theme'      => 'nullable|string|max:255',
            'status'     => 'nullable|in:' . implode(',', self::VALID_STATUSES),
            'sort_order' => 'nullable|int|min:0|max:32767',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        $sets   = [];
        $params = ['id' => $aid, 'wid' => $wid];
        foreach (['name', 'logline', 'theme', 'status', 'sort_order'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        DB::execute(
            'UPDATE story_arcs SET ' . implode(', ', $sets) . ' WHERE id = :id AND world_id = :wid',
            $params
        );

        self::audit($wid, $userId, 'arc.update', 'story_arc', $aid, $data);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/story-arcs/:aid ──────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $aid    = (int) $p['aid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $arc = DB::queryOne(
            'SELECT id, created_by FROM story_arcs
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $aid, 'wid' => $wid]
        );
        if (!$arc) {
            Router::jsonError(404, 'NOT_FOUND', 'Story arc not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $arc['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE story_arcs SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $aid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'arc.delete', 'story_arc', $aid);
        Router::json(['deleted' => true]);
    }

    // ─── PUT /api/v1/worlds/:wid/story-arcs/:aid/entities ────────────────────

    public static function replaceEntities(array $p): void
    {
        $wid    = (int) $p['wid'];
        $aid    = (int) $p['aid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $arc = DB::queryOne(
            'SELECT id FROM story_arcs WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $aid, 'wid' => $wid]
        );
        if (!$arc) {
            Router::jsonError(404, 'NOT_FOUND', 'Story arc not found.');
            return;
        }

        // Payload: array of {entity_id, role?, notes?, sort_order?}
        $body    = Validator::parseJson(['entities' => 'required|array']);
        $entries = $body['entities'];

        // Validate and collect entity IDs for world-scope check
        $entityIds = [];
        $validated = [];
        foreach ($entries as $i => $item) {
            if (!isset($item['entity_id']) || !is_int($item['entity_id'])) {
                Router::jsonError(400, 'VALIDATION_ERROR', "entities[{$i}].entity_id must be an integer.");
                return;
            }
            $role  = isset($item['role'])  && is_string($item['role'])  ? substr($item['role'], 0, 128)  : null;
            $notes = isset($item['notes']) && is_string($item['notes']) ? substr($item['notes'], 0, 2000) : null;
            $sort  = isset($item['sort_order']) && is_int($item['sort_order']) ? $item['sort_order'] : $i;

            $entityIds[] = $item['entity_id'];
            $validated[] = ['entity_id' => $item['entity_id'], 'role' => $role, 'notes' => $notes, 'sort' => $sort];
        }

        // Verify all entity IDs belong to this world (named placeholders)
        if (!empty($entityIds)) {
            $entityParams = ['wid' => $wid];
            $entityKeys   = [];
            foreach ($entityIds as $i => $eid) {
                $key              = 'eid' . $i;
                $entityKeys[]     = ':' . $key;
                $entityParams[$key] = $eid;
            }
            $valid = DB::query(
                'SELECT id FROM entities WHERE world_id = :wid AND deleted_at IS NULL
                  AND id IN (' . implode(',', $entityKeys) . ')',
                $entityParams
            );
            if (count($valid) !== count($entityIds)) {
                Router::jsonError(400, 'VALIDATION_ERROR', 'One or more entity IDs do not belong to this world.');
                return;
            }
        }

        DB::transaction(function () use ($aid, $wid, $validated): void {
            DB::execute('DELETE FROM arc_entities WHERE arc_id = :aid', ['aid' => $aid]);
            foreach ($validated as $item) {
                DB::execute(
                    'INSERT INTO arc_entities (arc_id, entity_id, world_id, role, notes, sort_order)
                     VALUES (:aid, :eid, :wid, :role, :notes, :sort)',
                    [
                        'aid'   => $aid,
                        'eid'   => $item['entity_id'],
                        'wid'   => $wid,
                        'role'  => $item['role'],
                        'notes' => $item['notes'],
                        'sort'  => $item['sort'],
                    ]
                );
            }
        });

        self::audit($wid, $userId, 'arc.entities.replace', 'story_arc', $aid);
        Router::json(['updated' => true, 'entity_count' => count($validated)]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private static function audit(int $wid, int $userId, string $action,
        ?string $tt = null, ?int $tid = null, ?array $diff = null): void
    {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent, diff_json)
             VALUES (:wid, :uid, :action, :tt, :tid, :ip, :ua, :diff)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'action' => $action,
                'tt'     => $tt,
                'tid'    => $tid,
                'ip'     => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'diff'   => $diff ? json_encode($diff) : null,
            ]
        );
    }
}
