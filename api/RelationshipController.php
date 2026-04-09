<?php
/**
 * LoreBuilder — Relationship Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/relationships/*   — directed entity relationship CRUD
 *
 * All relationships are world-scoped. Both from_entity and to_entity must
 * belong to the same world as the relationship — enforced before INSERT.
 */

declare(strict_types=1);

class RelationshipController
{
    // ─── GET /api/v1/worlds/:wid/relationships ────────────────────────────────

    public static function index(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'entity_id'      => 'nullable|int',   // either side
            'from_entity_id' => 'nullable|int',
            'to_entity_id'   => 'nullable|int',
            'rel_type'       => 'nullable|string|max:64',
        ]);

        $where  = ['r.world_id = :wid', 'r.deleted_at IS NULL'];
        $params = ['wid' => $wid];

        if (!empty($q['entity_id'])) {
            $where[] = '(r.from_entity_id = :eid_from OR r.to_entity_id = :eid_to)';
            $params['eid_from'] = (int) $q['entity_id'];
            $params['eid_to']   = (int) $q['entity_id'];
        } elseif (!empty($q['from_entity_id'])) {
            $where[] = 'r.from_entity_id = :from';
            $params['from'] = (int) $q['from_entity_id'];
        } elseif (!empty($q['to_entity_id'])) {
            $where[] = 'r.to_entity_id = :to';
            $params['to'] = (int) $q['to_entity_id'];
        }
        if (!empty($q['rel_type'])) { $where[] = 'r.rel_type = :rtype'; $params['rtype'] = $q['rel_type']; }

        $rows = DB::query(
            'SELECT r.id, r.from_entity_id, r.to_entity_id, r.rel_type,
                    r.is_bidirectional AS bidirectional, r.strength, r.notes,
                    ef.name AS from_name, ef.type AS from_type,
                    et.name AS to_name,   et.type AS to_type
               FROM entity_relationships r
               JOIN entities ef ON ef.id = r.from_entity_id AND ef.deleted_at IS NULL
               JOIN entities et ON et.id = r.to_entity_id   AND et.deleted_at IS NULL
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY r.rel_type ASC, r.id ASC',
            $params
        );

        Router::json($rows);
    }

    // ─── GET /api/v1/worlds/:wid/relationships/types ────────────────────────

    public static function types(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $rows = DB::query(
            'SELECT DISTINCT rel_type FROM entity_relationships
              WHERE world_id = :wid AND deleted_at IS NULL
              ORDER BY rel_type ASC',
            ['wid' => $wid]
        );

        Router::json(array_column($rows, 'rel_type'));
    }

    // ─── POST /api/v1/worlds/:wid/relationships ───────────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'from_entity_id'  => 'required|int',
            'to_entity_id'    => 'required|int',
            'rel_type'        => 'required|string|max:64',
            'is_bidirectional'=> 'nullable|bool',
            'strength'        => 'nullable|int|min:1|max:10',
            'notes'           => 'nullable|string|max:2000',
        ]);

        if ($data['from_entity_id'] === $data['to_entity_id']) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'An entity cannot have a relationship with itself.');
            return;
        }

        // Both entities must exist in this world
        foreach (['from_entity_id' => $data['from_entity_id'], 'to_entity_id' => $data['to_entity_id']] as $field => $eid) {
            $exists = DB::queryOne(
                'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
                ['id' => $eid, 'wid' => $wid]
            );
            if (!$exists) {
                Router::jsonError(404, 'NOT_FOUND', "Entity {$eid} not found in this world.");
                return;
            }
        }

        $id = DB::execute(
            'INSERT INTO entity_relationships
                (world_id, from_entity_id, to_entity_id, rel_type, is_bidirectional, strength, notes, created_by)
             VALUES (:wid, :from, :to, :rtype, :bidir, :strength, :notes, :by)',
            [
                'wid'      => $wid,
                'from'     => $data['from_entity_id'],
                'to'       => $data['to_entity_id'],
                'rtype'    => $data['rel_type'],
                'bidir'    => isset($data['is_bidirectional']) ? (int) $data['is_bidirectional'] : 0,
                'strength' => $data['strength'] ?? 5,
                'notes'    => $data['notes']    ?? null,
                'by'       => $userId,
            ]
        );

        self::audit($wid, $userId, 'relationship.create', 'relationship', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    // ─── PATCH /api/v1/worlds/:wid/relationships/:id ─────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $rel = DB::queryOne(
            'SELECT id, created_by FROM entity_relationships
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $id, 'wid' => $wid]
        );
        if (!$rel) {
            Router::jsonError(404, 'NOT_FOUND', 'Relationship not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $rel['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'rel_type'        => 'nullable|string|max:64',
            'is_bidirectional'=> 'nullable|bool',
            'strength'        => 'nullable|int|min:1|max:10',
            'notes'           => 'nullable|string|max:2000',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        $sets = []; $params = ['id' => $id, 'wid' => $wid];
        if (array_key_exists('rel_type', $data))         { $sets[] = 'rel_type = :rel_type';               $params['rel_type'] = $data['rel_type']; }
        if (array_key_exists('is_bidirectional', $data)) { $sets[] = 'is_bidirectional = :bidir';           $params['bidir'] = (int) $data['is_bidirectional']; }
        if (array_key_exists('strength', $data))         { $sets[] = 'strength = :strength';               $params['strength'] = $data['strength']; }
        if (array_key_exists('notes', $data))            { $sets[] = 'notes = :notes';                     $params['notes'] = $data['notes']; }

        DB::execute(
            'UPDATE entity_relationships SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND world_id = :wid',
            $params
        );

        self::audit($wid, $userId, 'relationship.update', 'relationship', $id, $data);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/relationships/:id ────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $rel = DB::queryOne(
            'SELECT id, created_by FROM entity_relationships
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $id, 'wid' => $wid]
        );
        if (!$rel) {
            Router::jsonError(404, 'NOT_FOUND', 'Relationship not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $rel['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE entity_relationships SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $id, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'relationship.delete', 'relationship', $id);
        Router::json(['deleted' => true]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private static function audit(int $wid, int $userId, string $action,
        ?string $tt = null, ?int $tid = null, ?array $diff = null): void
    {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent, diff_json)
             VALUES (:wid, :uid, :action, :tt, :tid, :ip, :ua, :diff)',
            [
                'wid' => $wid, 'uid' => $userId, 'action' => $action,
                'tt' => $tt, 'tid' => $tid,
                'ip'   => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'diff' => $diff ? json_encode($diff) : null,
            ]
        );
    }
}
