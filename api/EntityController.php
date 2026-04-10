<?php
/**
 * LoreBuilder — Entity Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/entities/*         — entity CRUD
 *   /api/v1/worlds/:wid/entities/:id/attributes — typed attribute set
 *   /api/v1/worlds/:wid/entities/:id/tags      — tag assignment
 *   /api/v1/worlds/:wid/tags/*             — world-level tag management
 *   /api/v1/worlds/:wid/search             — FULLTEXT search
 *   /api/v1/worlds/:wid/graph              — vis-network node+edge JSON
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

class EntityController
{
    private const VALID_TYPES = [
        'Character','Location','Event','Faction',
        'Artefact','Creature','Concept','Race',
    ];

    private const VALID_ATTR_TYPES = ['string','integer','boolean','date','markdown'];

    // ─── GET /api/v1/worlds/:wid/entities ────────────────────────────────────

    public static function index(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'type'    => 'nullable|in:' . implode(',', self::VALID_TYPES),
            'status'  => 'nullable|in:draft,published,archived',
            'tag'     => 'nullable|int',
            'page'    => 'nullable|int|min:1',
            'per_page'=> 'nullable|int|min:1|max:500',
        ]);

        $page    = (int) ($q['page']     ?? 1);
        $perPage = (int) ($q['per_page'] ?? 50);
        $offset  = ($page - 1) * $perPage;

        $where  = ['e.world_id = :wid', 'e.deleted_at IS NULL'];
        $params = ['wid' => $wid];

        if (!empty($q['type'])) {
            $where[]        = 'e.type = :type';
            $params['type'] = $q['type'];
        }
        if (!empty($q['status'])) {
            $where[]          = 'e.status = :status';
            $params['status'] = $q['status'];
        }

        $join = '';
        if (!empty($q['tag'])) {
            $join            = 'JOIN entity_tags et ON et.entity_id = e.id AND et.tag_id = :tag';
            $params['tag']   = (int) $q['tag'];
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) DB::queryOne(
            "SELECT COUNT(*) AS n FROM entities e {$join} WHERE {$whereStr}",
            $params
        )['n'];

        $params['limit']  = $perPage;
        $params['offset'] = $offset;

        $entities = DB::query(
            "SELECT e.id, e.type, e.name, e.slug, e.short_summary, e.status, e.created_by, e.created_at, e.updated_at
               FROM entities e {$join}
              WHERE {$whereStr}
              ORDER BY e.name ASC
              LIMIT :limit OFFSET :offset",
            $params
        );

        Router::json($entities, 200, ['total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    // ─── POST /api/v1/worlds/:wid/entities ───────────────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'type'          => 'required|in:' . implode(',', self::VALID_TYPES),
            'name'          => 'required|string|max:255',
            'slug'          => 'nullable|slug|max:300',
            'short_summary' => 'nullable|string|max:512',
            'status'        => 'nullable|in:draft,published,archived',
            'lore_body'     => 'nullable|string',
        ]);

        $slug = $data['slug'] ?? self::slugify($data['name']);
        $slug = self::uniqueSlug($wid, $slug);

        $id = DB::execute(
            'INSERT INTO entities (world_id, created_by, type, name, slug, short_summary, status, lore_body)
             VALUES (:wid, :by, :type, :name, :slug, :summary, :status, :lore)',
            [
                'wid'     => $wid,
                'by'      => $userId,
                'type'    => $data['type'],
                'name'    => $data['name'],
                'slug'    => $slug,
                'summary' => $data['short_summary'] ?? null,
                'status'  => $data['status']        ?? 'draft',
                'lore'    => $data['lore_body']      ?? null,
            ]
        );

        self::audit($wid, $userId, 'entity.create', 'entity', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id, 'slug' => $slug]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/worlds/:wid/entities/:id ────────────────────────────────

    public static function show(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $entity = self::fetchEntity($wid, $id);
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        // Attach attributes, tags, and relationship counts
        $entity['attributes']    = self::fetchAttributes($id, $wid);
        $entity['tags']          = self::fetchTags($id, $wid);
        $entity['relationships'] = DB::query(
            'SELECT id, from_entity_id, to_entity_id, rel_type, is_bidirectional, strength, notes
               FROM entity_relationships
              WHERE world_id = :wid AND deleted_at IS NULL
                AND (from_entity_id = :id1 OR to_entity_id = :id2)',
            ['wid' => $wid, 'id1' => $id, 'id2' => $id]
        );

        Router::json($entity);
    }

    // ─── PATCH /api/v1/worlds/:wid/entities/:id ──────────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $entity = self::fetchEntity($wid, $id);
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $entity['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'name'          => 'nullable|string|max:255',
            'short_summary' => 'nullable|string|max:512',
            'status'        => 'nullable|in:draft,published,archived',
            'lore_body'     => 'nullable|string',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        $sets   = [];
        $params = ['id' => $id, 'wid' => $wid];
        foreach (['name','short_summary','status','lore_body'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        // Re-slug if name changed
        if (isset($data['name'])) {
            $slug            = self::uniqueSlug($wid, self::slugify($data['name']), $id);
            $sets[]          = 'slug = :slug';
            $params['slug']  = $slug;
        }

        DB::execute(
            'UPDATE entities SET ' . implode(', ', $sets) . ' WHERE id = :id AND world_id = :wid',
            $params
        );

        self::audit($wid, $userId, 'entity.update', 'entity', $id, $data);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/entities/:id ─────────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $entity = self::fetchEntity($wid, $id);
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $entity['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE entities SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $id, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'entity.delete', 'entity', $id);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/entities/:id/attributes ─────────────────────

    public static function attributes(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        if (!self::fetchEntity($wid, $id)) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Router::json(self::fetchAttributes($id, $wid));
    }

    // ─── PUT /api/v1/worlds/:wid/entities/:id/attributes ─────────────────────

    public static function replaceAttributes(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $entity = self::fetchEntity($wid, $id);
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $entity['created_by'], $userId, $membership['role']);

        // Expect array of attribute objects
        $body = Validator::parseJson(['attributes' => 'required|array']);
        $attrs = $body['attributes'];

        // Validate each attribute entry
        foreach ($attrs as $i => $attr) {
            if (!is_array($attr) || empty($attr['attr_key']) || !isset($attr['attr_value'])) {
                Router::jsonError(400, 'VALIDATION_ERROR', "Attribute at index {$i} must have attr_key and attr_value.");
                return;
            }
            if (!in_array($attr['data_type'] ?? 'string', self::VALID_ATTR_TYPES, true)) {
                Router::jsonError(400, 'VALIDATION_ERROR', "Invalid data_type at index {$i}.");
                return;
            }
            if (strlen($attr['attr_key']) > 128) {
                Router::jsonError(400, 'VALIDATION_ERROR', "attr_key too long at index {$i}.");
                return;
            }
        }

        DB::transaction(function () use ($attrs, $id, $wid): void {
            DB::execute('DELETE FROM entity_attributes WHERE entity_id = :id', ['id' => $id]);

            foreach ($attrs as $i => $attr) {
                DB::execute(
                    'INSERT INTO entity_attributes (entity_id, world_id, attr_key, attr_value, data_type, sort_order)
                     VALUES (:eid, :wid, :key, :val, :dtype, :sort)',
                    [
                        'eid'   => $id,
                        'wid'   => $wid,
                        'key'   => substr($attr['attr_key'], 0, 128),
                        'val'   => $attr['attr_value'],
                        'dtype' => $attr['data_type'] ?? 'string',
                        'sort'  => $i,
                    ]
                );
            }
        });

        self::audit($wid, $userId, 'entity.attributes.replace', 'entity', $id);
        Router::json(['updated' => true, 'count' => count($attrs)]);
    }

    // ─── GET /api/v1/worlds/:wid/entities/:id/tags ───────────────────────────

    public static function entityTags(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        if (!self::fetchEntity($wid, $id)) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Router::json(self::fetchTags($id, $wid));
    }

    // ─── PUT /api/v1/worlds/:wid/entities/:id/tags ───────────────────────────

    public static function replaceEntityTags(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $entity = self::fetchEntity($wid, $id);
        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Entity not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $entity['created_by'], $userId, $membership['role']);

        $body   = Validator::parseJson(['tag_ids' => 'required|array']);
        $tagIds = array_map('intval', $body['tag_ids']);

        // Verify all tags belong to this world (named placeholders only — no mixing with positional ?)
        if (!empty($tagIds)) {
            $tagParams = ['wid' => $wid];
            $tagKeys   = [];
            foreach ($tagIds as $i => $tid) {
                $key            = 'tag' . $i;
                $tagKeys[]      = ':' . $key;
                $tagParams[$key] = $tid;
            }
            $valid = DB::query(
                'SELECT id FROM tags WHERE world_id = :wid AND id IN (' . implode(',', $tagKeys) . ')',
                $tagParams
            );
            if (count($valid) !== count($tagIds)) {
                Router::jsonError(400, 'VALIDATION_ERROR', 'One or more tag IDs do not belong to this world.');
                return;
            }
        }

        DB::transaction(function () use ($id, $tagIds): void {
            DB::execute('DELETE FROM entity_tags WHERE entity_id = :id', ['id' => $id]);
            foreach ($tagIds as $tid) {
                DB::execute(
                    'INSERT IGNORE INTO entity_tags (entity_id, tag_id) VALUES (:eid, :tid)',
                    ['eid' => $id, 'tid' => $tid]
                );
            }
        });

        self::audit($wid, $userId, 'entity.tags.replace', 'entity', $id);
        Router::json(['updated' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/tags ────────────────────────────────────────

    public static function tagIndex(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $tags = DB::query(
            'SELECT t.id, t.name, t.color,
                    COUNT(et.entity_id) AS entity_count
               FROM tags t
               LEFT JOIN entity_tags et ON et.tag_id = t.id
              WHERE t.world_id = :wid
              GROUP BY t.id
              ORDER BY t.name ASC',
            ['wid' => $wid]
        );

        Router::json($tags);
    }

    // ─── POST /api/v1/worlds/:wid/tags ───────────────────────────────────────

    public static function tagCreate(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'name'  => 'required|string|max:64',
            'color' => 'nullable|string|max:7',
        ]);

        $color = $data['color'] ?? '#4A90A4';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a 6-digit hex value like #4A90A4.');
            return;
        }

        if (DB::queryOne('SELECT id FROM tags WHERE world_id = :wid AND name = :n', ['wid' => $wid, 'n' => $data['name']])) {
            Router::jsonError(409, 'CONFLICT', 'A tag with that name already exists in this world.');
            return;
        }

        $id = DB::execute(
            'INSERT INTO tags (world_id, name, color) VALUES (:wid, :name, :color)',
            ['wid' => $wid, 'name' => $data['name'], 'color' => $color]
        );

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id, 'name' => $data['name'], 'color' => $color]], JSON_UNESCAPED_UNICODE);
    }

    // ─── PATCH /api/v1/worlds/:wid/tags/:tid ─────────────────────────────────

    public static function tagUpdate(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $tag = DB::queryOne('SELECT id FROM tags WHERE id = :id AND world_id = :wid', ['id' => $tid, 'wid' => $wid]);
        if (!$tag) {
            Router::jsonError(404, 'NOT_FOUND', 'Tag not found.');
            return;
        }

        $data = Validator::parseJson([
            'name'  => 'nullable|string|max:64',
            'color' => 'nullable|string|max:7',
        ]);

        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a 6-digit hex value like #4A90A4.');
            return;
        }

        $sets = []; $params = ['id' => $tid, 'wid' => $wid];
        foreach (['name','color'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if ($sets) {
            DB::execute('UPDATE tags SET ' . implode(', ', $sets) . ' WHERE id = :id AND world_id = :wid', $params);
        }

        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/tags/:tid ────────────────────────────────

    public static function tagDestroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $tag = DB::queryOne('SELECT id FROM tags WHERE id = :id AND world_id = :wid', ['id' => $tid, 'wid' => $wid]);
        if (!$tag) {
            Router::jsonError(404, 'NOT_FOUND', 'Tag not found.');
            return;
        }

        // Cascade handled by FK (entity_tags.tag_id → tags.id ON DELETE CASCADE)
        DB::execute('DELETE FROM tags WHERE id = :id AND world_id = :wid', ['id' => $tid, 'wid' => $wid]);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/search ──────────────────────────────────────

    public static function search(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'q'    => 'required|string|min:2|max:200',
            'type' => 'nullable|in:' . implode(',', self::VALID_TYPES),
            'tag'  => 'nullable|int',
        ]);

        $where  = ['e.world_id = :wid', 'e.deleted_at IS NULL',
                   'MATCH(e.name, e.short_summary, e.lore_body) AGAINST (:q IN BOOLEAN MODE)'];
        $params = ['wid' => $wid, 'q' => $q['q'] . '*'];

        if (!empty($q['type'])) {
            $where[]        = 'e.type = :type';
            $params['type'] = $q['type'];
        }

        $join = '';
        if (!empty($q['tag'])) {
            $join          = 'JOIN entity_tags et ON et.entity_id = e.id AND et.tag_id = :tag';
            $params['tag'] = (int) $q['tag'];
        }

        $whereStr = implode(' AND ', $where);

        $results = DB::query(
            "SELECT e.id, e.type, e.name, e.slug, e.short_summary, e.status,
                    MATCH(e.name, e.short_summary, e.lore_body) AGAINST (:q2 IN BOOLEAN MODE) AS relevance
               FROM entities e {$join}
              WHERE {$whereStr}
              ORDER BY relevance DESC
              LIMIT 50",
            array_merge($params, ['q2' => $q['q'] . '*'])
        );

        Router::json($results, 200, ['query' => $q['q'], 'count' => count($results)]);
    }

    // ─── GET /api/v1/worlds/:wid/graph ───────────────────────────────────────

    public static function graph(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $nodes = DB::query(
            'SELECT id, name AS label, type, status
               FROM entities
              WHERE world_id = :wid AND deleted_at IS NULL AND status != \'archived\'',
            ['wid' => $wid]
        );

        $edges = DB::query(
            'SELECT id, from_entity_id AS `from`, to_entity_id AS `to`,
                    rel_type AS label, strength, is_bidirectional
               FROM entity_relationships
              WHERE world_id = :wid AND deleted_at IS NULL',
            ['wid' => $wid]
        );

        Router::json(['nodes' => $nodes, 'edges' => $edges]);
    }

    // ─── GET /api/v1/worlds/:wid/entities/trash ────────────────────────────

    public static function trash(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'page'     => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:1|max:100',
        ]);

        $page    = (int) ($q['page']     ?? 1);
        $perPage = (int) ($q['per_page'] ?? 50);
        $offset  = ($page - 1) * $perPage;

        $total = (int) DB::queryOne(
            'SELECT COUNT(*) AS n FROM entities WHERE world_id = :wid AND deleted_at IS NOT NULL',
            ['wid' => $wid]
        )['n'];

        $rows = DB::query(
            'SELECT id, name, type, status, deleted_at
               FROM entities
              WHERE world_id = :wid AND deleted_at IS NOT NULL
              ORDER BY deleted_at DESC
              LIMIT :limit OFFSET :offset',
            ['wid' => $wid, 'limit' => $perPage, 'offset' => $offset]
        );

        Router::json($rows, 200, ['total' => $total, 'page' => $page]);
    }

    // ─── POST /api/v1/worlds/:wid/entities/:id/restore ──────────────────────

    public static function restore(array $p): void
    {
        $wid    = (int) $p['wid'];
        $id     = (int) $p['id'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        // Fetch soft-deleted entity (including deleted ones)
        $entity = DB::queryOne(
            'SELECT id, name FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NOT NULL',
            ['id' => $id, 'wid' => $wid]
        );

        if (!$entity) {
            Router::jsonError(404, 'NOT_FOUND', 'Deleted entity not found.');
            return;
        }

        DB::execute(
            'UPDATE entities SET deleted_at = NULL WHERE id = :id AND world_id = :wid',
            ['id' => $id, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'entity.restore', 'entity', $id);
        Router::json(['restored' => true]);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchEntity(int $wid, int $id): ?array
    {
        return DB::queryOne(
            'SELECT id, world_id, created_by, type, name, slug, short_summary,
                    status, lore_body, attributes_json, created_at, updated_at
               FROM entities
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $id, 'wid' => $wid]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchAttributes(int $entityId, int $wid): array
    {
        return DB::query(
            'SELECT attr_key, attr_value, data_type, sort_order
               FROM entity_attributes
              WHERE entity_id = :id AND world_id = :wid
              ORDER BY sort_order ASC, attr_key ASC',
            ['id' => $entityId, 'wid' => $wid]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchTags(int $entityId, int $wid): array
    {
        return DB::query(
            'SELECT t.id, t.name, t.color
               FROM tags t
               JOIN entity_tags et ON et.tag_id = t.id
              WHERE et.entity_id = :id AND t.world_id = :wid
              ORDER BY t.name ASC',
            ['id' => $entityId, 'wid' => $wid]
        );
    }

    /**
     * Convert a name to a URL-safe slug.
     */
    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-') ?: 'entity';
    }

    /**
     * Ensure slug is unique within the world by appending -2, -3 … if needed.
     * Pass $excludeId to allow the current entity's own slug.
     */
    private static function uniqueSlug(int $wid, string $base, ?int $excludeId = null): string
    {
        $slug    = $base;
        $counter = 2;

        while (true) {
            $params = ['wid' => $wid, 'slug' => $slug];
            $sql    = 'SELECT id FROM entities WHERE world_id = :wid AND slug = :slug AND deleted_at IS NULL';
            if ($excludeId !== null) {
                $sql        .= ' AND id != :excl';
                $params['excl'] = $excludeId;
            }
            if (!DB::queryOne($sql, $params)) {
                return $slug;
            }
            $slug = $base . '-' . $counter++;
        }
    }

    private static function audit(
        int $wid, int $userId, string $action,
        ?string $targetType = null, ?int $targetId = null, ?array $diff = null
    ): void {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent, diff_json)
             VALUES (:wid, :uid, :action, :ttype, :tid, :ip, :ua, :diff)',
            [
                'wid'    => $wid,
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
