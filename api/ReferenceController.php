<?php
/**
 * LoreBuilder — Reference Controller
 *
 * Research sources (URLs, books, articles, films, etc.) scoped to a world.
 *
 * Endpoints:
 *   GET    /api/v1/worlds/:wid/references           — list; filter by type/search
 *   POST   /api/v1/worlds/:wid/references           — create
 *   GET    /api/v1/worlds/:wid/references/:rid      — detail with linked entities
 *   PATCH  /api/v1/worlds/:wid/references/:rid      — update
 *   DELETE /api/v1/worlds/:wid/references/:rid      — soft delete
 *   PUT    /api/v1/worlds/:wid/references/:rid/entities — replace linked entities
 */

declare(strict_types=1);

class ReferenceController
{
    private const VALID_TYPES = ['url','book','article','film','podcast','other'];

    // ─── GET /api/v1/worlds/:wid/references ──────────────────────────────────

    public static function index(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'viewer');

        $q = Validator::parseQuery([
            'type'     => 'string|in:url,book,article,film,podcast,other|nullable',
            'search'   => 'string|max:200|nullable',
            'page'     => 'int|min:1',
            'per_page' => 'int|min:1|max:100',
        ]);

        $page    = $q['page']     ?? 1;
        $perPage = $q['per_page'] ?? 30;
        $offset  = ($page - 1) * $perPage;

        if (isset($q['search'])) {
            $rows = DB::query(
                'SELECT r.id, r.ref_type, r.title, r.url, r.author, r.description,
                        r.tags, r.created_at, u.display_name AS creator_name
                   FROM world_references r
                   JOIN users u ON u.id = r.created_by
                  WHERE r.world_id = :wid AND r.deleted_at IS NULL
                    AND MATCH(r.title, r.description) AGAINST(:q IN BOOLEAN MODE)
                  ORDER BY r.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'q' => $q['search'] . '*', 'lim' => $perPage, 'off' => $offset]
            );
            $total = count($rows);
        } elseif (isset($q['type'])) {
            $rows = DB::query(
                'SELECT r.id, r.ref_type, r.title, r.url, r.author, r.description,
                        r.tags, r.created_at, u.display_name AS creator_name
                   FROM world_references r
                   JOIN users u ON u.id = r.created_by
                  WHERE r.world_id = :wid AND r.ref_type = :type AND r.deleted_at IS NULL
                  ORDER BY r.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'type' => $q['type'], 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM world_references WHERE world_id = :wid AND ref_type = :type AND deleted_at IS NULL',
                ['wid' => $wid, 'type' => $q['type']]
            )['n'];
        } else {
            $rows = DB::query(
                'SELECT r.id, r.ref_type, r.title, r.url, r.author, r.description,
                        r.tags, r.created_at, u.display_name AS creator_name
                   FROM world_references r
                   JOIN users u ON u.id = r.created_by
                  WHERE r.world_id = :wid AND r.deleted_at IS NULL
                  ORDER BY r.created_at DESC
                  LIMIT :lim OFFSET :off',
                ['wid' => $wid, 'lim' => $perPage, 'off' => $offset]
            );
            $total = (int) DB::queryOne(
                'SELECT COUNT(*) AS n FROM world_references WHERE world_id = :wid AND deleted_at IS NULL',
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

    // ─── POST /api/v1/worlds/:wid/references ─────────────────────────────────

    public static function create(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $data = Validator::parseJson([
            'ref_type'    => 'required|string|in:url,book,article,film,podcast,other',
            'title'       => 'required|string|min:1|max:512',
            'url'         => 'string|max:2048|nullable',
            'author'      => 'string|max:255|nullable',
            'description' => 'string|max:4000|nullable',
            'tags'        => 'array|nullable',
        ]);

        $newId = DB::execute(
            'INSERT INTO world_references (world_id, created_by, ref_type, title, url, author, description, tags)
             VALUES (:wid, :uid, :type, :title, :url, :author, :desc, :tags)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'type'   => $data['ref_type'],
                'title'  => $data['title'],
                'url'    => $data['url']    ?? null,
                'author' => $data['author'] ?? null,
                'desc'   => $data['description'] ?? null,
                'tags'   => isset($data['tags']) ? json_encode($data['tags']) : null,
            ]
        );

        self::audit($wid, $userId, 'reference.create', $newId);
        http_response_code(201);
        echo json_encode(['data' => ['id' => $newId]]);
    }

    // ─── GET /api/v1/worlds/:wid/references/:rid ─────────────────────────────

    public static function show(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $rid     = (int) $p['rid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'viewer');

        $ref = DB::queryOne(
            'SELECT r.*, u.display_name AS creator_name
               FROM world_references r
               JOIN users u ON u.id = r.created_by
              WHERE r.id = :rid AND r.world_id = :wid AND r.deleted_at IS NULL',
            ['rid' => $rid, 'wid' => $wid]
        );
        if (!$ref) {
            http_response_code(404);
            echo json_encode(['error' => 'Reference not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        $linkedEntities = DB::query(
            'SELECT e.id, e.name, e.type, e.status
               FROM reference_entities re
               JOIN entities e ON e.id = re.entity_id
              WHERE re.reference_id = :rid AND e.deleted_at IS NULL',
            ['rid' => $rid]
        );
        $ref['linked_entities'] = $linkedEntities;

        http_response_code(200);
        echo json_encode(['data' => $ref]);
    }

    // ─── PATCH /api/v1/worlds/:wid/references/:rid ───────────────────────────

    public static function update(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $rid     = (int) $p['rid'];

        $membership = Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $existing = DB::queryOne(
            'SELECT id, created_by FROM world_references WHERE id = :rid AND world_id = :wid AND deleted_at IS NULL',
            ['rid' => $rid, 'wid' => $wid]
        );
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Reference not found.', 'code' => 'NOT_FOUND']);
            return;
        }
        Guard::requireOwnerOrRole((int) $existing['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'ref_type'    => 'string|in:url,book,article,film,podcast,other|nullable',
            'title'       => 'string|min:1|max:512|nullable',
            'url'         => 'string|max:2048|nullable',
            'author'      => 'string|max:255|nullable',
            'description' => 'string|max:4000|nullable',
            'tags'        => 'array|nullable',
        ]);

        $sets  = [];
        $binds = ['rid' => $rid, 'wid' => $wid];

        if (isset($data['ref_type']))    { $sets[] = 'ref_type = :type';    $binds['type']   = $data['ref_type']; }
        if (isset($data['title']))       { $sets[] = 'title = :title';      $binds['title']  = $data['title']; }
        if (array_key_exists('url', $data))         { $sets[] = 'url = :url';          $binds['url']    = $data['url']; }
        if (array_key_exists('author', $data))      { $sets[] = 'author = :author';    $binds['author'] = $data['author']; }
        if (array_key_exists('description', $data)) { $sets[] = 'description = :desc'; $binds['desc']   = $data['description']; }
        if (array_key_exists('tags', $data))        { $sets[] = 'tags = :tags';        $binds['tags']   = isset($data['tags']) ? json_encode($data['tags']) : null; }

        if (!empty($sets)) {
            DB::execute(
                'UPDATE world_references SET ' . implode(', ', $sets) . ' WHERE id = :rid AND world_id = :wid',
                $binds
            );
            self::audit($wid, $userId, 'reference.update', $rid);
        }

        http_response_code(200);
        echo json_encode(['data' => ['id' => $rid]]);
    }

    // ─── DELETE /api/v1/worlds/:wid/references/:rid ──────────────────────────

    public static function destroy(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $rid     = (int) $p['rid'];

        $membership = Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $existing = DB::queryOne(
            'SELECT id, created_by FROM world_references WHERE id = :rid AND world_id = :wid AND deleted_at IS NULL',
            ['rid' => $rid, 'wid' => $wid]
        );
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Reference not found.', 'code' => 'NOT_FOUND']);
            return;
        }
        Guard::requireOwnerOrRole((int) $existing['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE world_references SET deleted_at = NOW() WHERE id = :rid AND world_id = :wid',
            ['rid' => $rid, 'wid' => $wid]
        );
        self::audit($wid, $userId, 'reference.delete', $rid);
        http_response_code(200);
        echo json_encode(['data' => ['deleted' => true]]);
    }

    // ─── PUT /api/v1/worlds/:wid/references/:rid/entities ────────────────────

    public static function linkEntities(array $p): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $p['wid'];
        $rid     = (int) $p['rid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $existing = DB::queryOne(
            'SELECT id FROM world_references WHERE id = :rid AND world_id = :wid AND deleted_at IS NULL',
            ['rid' => $rid, 'wid' => $wid]
        );
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Reference not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        $data     = Validator::parseJson(['entity_ids' => 'required|array']);
        $entityIds = array_unique(array_filter(array_map('intval', (array) $data['entity_ids'])));

        // Validate all entity IDs belong to this world
        foreach ($entityIds as $eid) {
            $ent = DB::queryOne(
                'SELECT id FROM entities WHERE id = :eid AND world_id = :wid AND deleted_at IS NULL',
                ['eid' => $eid, 'wid' => $wid]
            );
            if (!$ent) {
                http_response_code(422);
                echo json_encode(['error' => "Entity {$eid} not found in this world.", 'code' => 'NOT_FOUND']);
                return;
            }
        }

        DB::transaction(function () use ($rid, $wid, $entityIds): void {
            DB::execute('DELETE FROM reference_entities WHERE reference_id = :rid', ['rid' => $rid]);
            foreach ($entityIds as $eid) {
                DB::execute(
                    'INSERT INTO reference_entities (reference_id, entity_id, world_id) VALUES (:rid, :eid, :wid)',
                    ['rid' => $rid, 'eid' => $eid, 'wid' => $wid]
                );
            }
        });

        self::audit($wid, $userId, 'reference.link_entities', $rid);
        http_response_code(200);
        echo json_encode(['data' => ['linked' => count($entityIds)]]);
    }

    private static function audit(int $wid, int $userId, string $action, int $targetId): void
    {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address)
             VALUES (:wid, :uid, :action, :type, :tid, :ip)',
            ['wid' => $wid, 'uid' => $userId, 'action' => $action,
             'type' => 'reference', 'tid' => $targetId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]
        );
    }
}
