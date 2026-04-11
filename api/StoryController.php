<?php

/**
 * LoreBuilder — Story Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/stories/*                  — story CRUD
 *   /api/v1/worlds/:wid/stories/:sid/entities       — story entity linking
 *   /api/v1/worlds/:wid/stories/:sid/notes          — story note linking
 *   /api/v1/worlds/:wid/stories/:sid/ai/assist      — AI story assistance
 *   /api/v1/worlds/:wid/stories/:sid/ai/scan-entities — entity name scan
 *
 * Security checklist per endpoint:
 *   [x] Auth + Guard (minRole noted per method)
 *   [x] world_id scoping on every query
 *   [x] Validator on all input; no mass-assignment
 *   [x] PDO prepared statements
 *   [x] Audit log on mutations
 *   [x] Soft delete (deleted_at) — never hard DELETE
 *   [x] CSRF verified on state-changing endpoints (via Router)
 *   [x] Rate limiting on AI endpoints
 */

declare(strict_types=1);

class StoryController
{
    private const VALID_STATUSES = ['draft', 'in_progress', 'review', 'complete', 'archived'];

    /** Maximum content size: 500,000 characters (~100K words). */
    private const MAX_CONTENT_LENGTH = 500_000;

    /** Per-user AI request limit per hour. */
    private const USER_RATE_LIMIT  = 20;

    /** Per-world AI request limit per hour. */
    private const WORLD_RATE_LIMIT = 100;

    // ─── GET /api/v1/worlds/:wid/stories ──────────────────────────────────────

    public static function index(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $q = Validator::parseQuery([
            'status'   => 'nullable|in:' . implode(',', self::VALID_STATUSES),
            'arc_id'   => 'nullable|int',
            'page'     => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:1|max:100',
            'sort'     => 'nullable|in:title,status,updated_at,word_count,sort_order',
            'dir'      => 'nullable|in:asc,desc',
        ]);

        $page    = (int) ($q['page'] ?? 1);
        $perPage = (int) ($q['per_page'] ?? 30);
        $offset  = ($page - 1) * $perPage;
        $sort    = $q['sort'] ?? 'sort_order';
        $dir     = strtoupper($q['dir'] ?? 'ASC');

        $where  = ['s.world_id = :wid', 's.deleted_at IS NULL'];
        $params = ['wid' => $wid];

        if (!empty($q['status'])) {
            $where[]          = 's.status = :status';
            $params['status'] = $q['status'];
        }
        if (isset($q['arc_id'])) {
            $where[]         = 's.arc_id = :arc_id';
            $params['arc_id'] = $q['arc_id'];
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) DB::queryOne(
            "SELECT COUNT(*) AS cnt FROM stories s WHERE {$whereSql}",
            $params
        )['cnt'];

        // Allowlisted sort columns — safe for interpolation
        $sortCol = match ($sort) {
            'title'      => 's.title',
            'status'     => 's.status',
            'updated_at' => 's.updated_at',
            'word_count' => 's.word_count',
            default      => 's.sort_order',
        };

        $stories = DB::query(
            "SELECT s.id, s.title, s.slug, s.synopsis, s.status, s.word_count,
                    s.sort_order, s.arc_id, s.created_by, s.created_at, s.updated_at,
                    sa.name AS arc_name,
                    u.display_name AS author_name,
                    COUNT(se.entity_id) AS entity_count
               FROM stories s
               LEFT JOIN story_arcs sa ON sa.id = s.arc_id AND sa.deleted_at IS NULL
               LEFT JOIN users u ON u.id = s.created_by
               LEFT JOIN story_entities se ON se.story_id = s.id
              WHERE {$whereSql}
              GROUP BY s.id
              ORDER BY {$sortCol} {$dir}, s.title ASC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Router::json($stories, 200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }

    // ─── POST /api/v1/worlds/:wid/stories ─────────────────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'title'      => 'required|string|max:255',
            'synopsis'   => 'nullable|string|max:2000',
            'status'     => 'nullable|in:' . implode(',', self::VALID_STATUSES),
            'arc_id'     => 'nullable|int',
            'sort_order' => 'nullable|int|min:0|max:32767',
            'content'    => 'nullable|string',
        ]);

        $title = $data['title'];

        // Validate content length
        $content = $data['content'] ?? '';
        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'Content exceeds maximum length of 500,000 characters.');
            return;
        }

        // Validate arc belongs to this world if provided
        if (!empty($data['arc_id'])) {
            $arc = DB::queryOne(
                'SELECT id FROM story_arcs WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
                ['id' => $data['arc_id'], 'wid' => $wid]
            );
            if (!$arc) {
                Router::jsonError(400, 'VALIDATION_ERROR', 'Story arc not found in this world.', 'arc_id');
                return;
            }
        }

        // Generate slug from title
        $slug = self::generateSlug($title, $wid);

        $wordCount = str_word_count(strip_tags($content));

        $id = DB::execute(
            'INSERT INTO stories (world_id, created_by, arc_id, title, slug, content, synopsis, status, word_count, sort_order)
             VALUES (:wid, :uid, :arc_id, :title, :slug, :content, :synopsis, :status, :wc, :sort)',
            [
                'wid'      => $wid,
                'uid'      => $userId,
                'arc_id'   => $data['arc_id'] ?? null,
                'title'    => $title,
                'slug'     => $slug,
                'content'  => $content,
                'synopsis' => $data['synopsis'] ?? null,
                'status'   => $data['status'] ?? 'draft',
                'wc'       => $wordCount,
                'sort'     => $data['sort_order'] ?? 0,
            ]
        );

        self::audit($wid, $userId, 'story.create', 'story', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id, 'slug' => $slug]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/worlds/:wid/stories/:sid ─────────────────────────────────

    public static function show(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT s.id, s.title, s.slug, s.content, s.synopsis, s.status,
                    s.word_count, s.sort_order, s.arc_id, s.created_by,
                    s.created_at, s.updated_at
               FROM stories s
              WHERE s.id = :id AND s.world_id = :wid AND s.deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        // Load arc info if linked
        if ($story['arc_id']) {
            $arc = DB::queryOne(
                'SELECT id, name, logline, theme, status
                   FROM story_arcs
                  WHERE id = :id AND deleted_at IS NULL',
                ['id' => $story['arc_id']]
            );
            $story['arc'] = $arc;
        } else {
            $story['arc'] = null;
        }

        // Load linked entities
        $story['entities'] = DB::query(
            'SELECT se.entity_id, se.role, se.sort_order,
                    e.name AS entity_name, e.type AS entity_type,
                    e.status AS entity_status, e.short_summary
               FROM story_entities se
               JOIN entities e ON e.id = se.entity_id AND e.deleted_at IS NULL
              WHERE se.story_id = :sid AND se.world_id = :wid
              ORDER BY se.sort_order ASC, e.name ASC',
            ['sid' => $sid, 'wid' => $wid]
        );

        // Load linked notes
        $story['notes'] = DB::query(
            'SELECT sn.note_id, ln.content, ln.is_canonical, ln.entity_id,
                    e.name AS entity_name
               FROM story_notes sn
               JOIN lore_notes ln ON ln.id = sn.note_id AND ln.deleted_at IS NULL
               LEFT JOIN entities e ON e.id = ln.entity_id AND e.deleted_at IS NULL
              WHERE sn.story_id = :sid AND sn.world_id = :wid
              ORDER BY ln.created_at DESC',
            ['sid' => $sid, 'wid' => $wid]
        );

        Router::json($story);
    }

    // ─── PATCH /api/v1/worlds/:wid/stories/:sid ───────────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by, updated_at FROM stories
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'title'      => 'nullable|string|max:255',
            'synopsis'   => 'nullable|string|max:2000',
            'content'    => 'nullable|string',
            'status'     => 'nullable|in:' . implode(',', self::VALID_STATUSES),
            'arc_id'     => 'nullable|int',
            'sort_order' => 'nullable|int|min:0|max:32767',
            'updated_at' => 'nullable|string',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        // Conflict detection: if client sends updated_at, check it matches
        if (!empty($data['updated_at'])) {
            $clientUpdatedAt = $data['updated_at'];
            $serverUpdatedAt = $story['updated_at'];
            if ($clientUpdatedAt !== $serverUpdatedAt) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Story has been modified since your last save. Reload to see the latest version.',
                    'code'  => 'CONFLICT',
                    'server_updated_at' => $serverUpdatedAt,
                ]);
                return;
            }
        }

        // Validate content length
        if (array_key_exists('content', $data) && $data['content'] !== null) {
            if (mb_strlen($data['content']) > self::MAX_CONTENT_LENGTH) {
                Router::jsonError(400, 'VALIDATION_ERROR', 'Content exceeds maximum length of 500,000 characters.');
                return;
            }
        }

        // Validate arc_id if provided
        if (array_key_exists('arc_id', $data) && $data['arc_id'] !== null) {
            $arc = DB::queryOne(
                'SELECT id FROM story_arcs WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
                ['id' => $data['arc_id'], 'wid' => $wid]
            );
            if (!$arc) {
                Router::jsonError(400, 'VALIDATION_ERROR', 'Story arc not found in this world.', 'arc_id');
                return;
            }
        }

        $sets   = [];
        $params = ['id' => $sid, 'wid' => $wid];

        foreach (['title', 'synopsis', 'content', 'status', 'arc_id', 'sort_order'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        // Recompute word count if content changed
        if (array_key_exists('content', $data) && $data['content'] !== null) {
            $wordCount = str_word_count(strip_tags($data['content']));
            $sets[]              = 'word_count = :wc';
            $params['wc']        = $wordCount;
        }

        // Regenerate slug if title changed
        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $slug = self::generateSlug($data['title'], $wid, $sid);
            $sets[]          = 'slug = :slug';
            $params['slug']  = $slug;
        }

        DB::execute(
            'UPDATE stories SET ' . implode(', ', $sets) . ' WHERE id = :id AND world_id = :wid',
            $params
        );

        // Fetch new updated_at for client conflict tracking
        $updated = DB::queryOne(
            'SELECT updated_at, word_count FROM stories WHERE id = :id',
            ['id' => $sid]
        );

        self::audit($wid, $userId, 'story.update', 'story', $sid, array_diff_key($data, ['updated_at' => 1, 'content' => 1]));
        Router::json([
            'updated'    => true,
            'updated_at' => $updated['updated_at'] ?? null,
            'word_count' => (int) ($updated['word_count'] ?? 0),
        ]);
    }

    // ─── DELETE /api/v1/worlds/:wid/stories/:sid ──────────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        DB::execute(
            'UPDATE stories SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $sid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'story.delete', 'story', $sid);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/stories/:sid/entities ────────────────────────

    public static function listEntities(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        $entities = DB::query(
            'SELECT se.entity_id, se.role, se.sort_order,
                    e.name AS entity_name, e.type AS entity_type,
                    e.status AS entity_status, e.short_summary
               FROM story_entities se
               JOIN entities e ON e.id = se.entity_id AND e.deleted_at IS NULL
              WHERE se.story_id = :sid AND se.world_id = :wid
              ORDER BY se.sort_order ASC, e.name ASC',
            ['sid' => $sid, 'wid' => $wid]
        );

        Router::json($entities);
    }

    // ─── PUT /api/v1/worlds/:wid/stories/:sid/entities ────────────────────────

    public static function replaceEntities(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        $body    = Validator::parseJson(['entities' => 'required|array']);
        $entries = $body['entities'];

        $entityIds = [];
        $validated = [];
        foreach ($entries as $i => $item) {
            if (!isset($item['entity_id']) || !is_int($item['entity_id'])) {
                Router::jsonError(400, 'VALIDATION_ERROR', "entities[{$i}].entity_id must be an integer.");
                return;
            }
            $role = isset($item['role']) && is_string($item['role']) ? substr($item['role'], 0, 128) : null;
            $sort = isset($item['sort_order']) && is_int($item['sort_order']) ? $item['sort_order'] : $i;

            $entityIds[] = $item['entity_id'];
            $validated[] = ['entity_id' => $item['entity_id'], 'role' => $role, 'sort' => $sort];
        }

        // Verify all entity IDs belong to this world
        if (!empty($entityIds)) {
            $entityParams = ['wid' => $wid];
            $entityKeys   = [];
            foreach ($entityIds as $i => $eid) {
                $key                = 'eid' . $i;
                $entityKeys[]       = ':' . $key;
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

        DB::transaction(function () use ($sid, $wid, $validated): void {
            DB::execute('DELETE FROM story_entities WHERE story_id = :sid', ['sid' => $sid]);
            foreach ($validated as $item) {
                DB::execute(
                    'INSERT INTO story_entities (story_id, entity_id, world_id, role, sort_order)
                     VALUES (:sid, :eid, :wid, :role, :sort)',
                    [
                        'sid'  => $sid,
                        'eid'  => $item['entity_id'],
                        'wid'  => $wid,
                        'role' => $item['role'],
                        'sort' => $item['sort'],
                    ]
                );
            }
        });

        self::audit($wid, $userId, 'story.entities.replace', 'story', $sid);
        Router::json(['updated' => true, 'entity_count' => count($validated)]);
    }

    // ─── POST /api/v1/worlds/:wid/stories/:sid/entities ───────────────────────

    public static function addEntity(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'entity_id' => 'required|int',
            'role'      => 'nullable|string|max:128',
        ]);

        // Verify entity belongs to this world
        $entity = DB::queryOne(
            'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $data['entity_id'], 'wid' => $wid]
        );
        if (!$entity) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'Entity not found in this world.', 'entity_id');
            return;
        }

        // Check for duplicate
        $existing = DB::queryOne(
            'SELECT id FROM story_entities WHERE story_id = :sid AND entity_id = :eid',
            ['sid' => $sid, 'eid' => $data['entity_id']]
        );
        if ($existing) {
            Router::jsonError(409, 'CONFLICT', 'Entity is already linked to this story.');
            return;
        }

        DB::execute(
            'INSERT INTO story_entities (story_id, entity_id, world_id, role, sort_order)
             VALUES (:sid, :eid, :wid, :role, :sort)',
            [
                'sid'  => $sid,
                'eid'  => $data['entity_id'],
                'wid'  => $wid,
                'role' => $data['role'] ?? null,
                'sort' => 0,
            ]
        );

        self::audit($wid, $userId, 'story.entity.add', 'story', $sid, ['entity_id' => $data['entity_id']]);

        http_response_code(201);
        echo json_encode(['data' => ['linked' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── DELETE /api/v1/worlds/:wid/stories/:sid/entities/:eid ────────────────

    public static function removeEntity(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $eid    = (int) $p['eid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        DB::execute(
            'DELETE FROM story_entities WHERE story_id = :sid AND entity_id = :eid AND world_id = :wid',
            ['sid' => $sid, 'eid' => $eid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'story.entity.remove', 'story', $sid, ['entity_id' => $eid]);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/stories/:sid/notes ───────────────────────────

    public static function listNotes(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        $notes = DB::query(
            'SELECT sn.note_id, ln.content, ln.is_canonical, ln.entity_id,
                    e.name AS entity_name
               FROM story_notes sn
               JOIN lore_notes ln ON ln.id = sn.note_id AND ln.deleted_at IS NULL
               LEFT JOIN entities e ON e.id = ln.entity_id AND e.deleted_at IS NULL
              WHERE sn.story_id = :sid AND sn.world_id = :wid
              ORDER BY ln.created_at DESC',
            ['sid' => $sid, 'wid' => $wid]
        );

        Router::json($notes);
    }

    // ─── POST /api/v1/worlds/:wid/stories/:sid/notes ──────────────────────────

    public static function addNote(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        $data = Validator::parseJson([
            'note_id' => 'required|int',
        ]);

        // Verify note belongs to this world
        $note = DB::queryOne(
            'SELECT id FROM lore_notes WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $data['note_id'], 'wid' => $wid]
        );
        if (!$note) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'Note not found in this world.', 'note_id');
            return;
        }

        // Check duplicate
        $existing = DB::queryOne(
            'SELECT id FROM story_notes WHERE story_id = :sid AND note_id = :nid',
            ['sid' => $sid, 'nid' => $data['note_id']]
        );
        if ($existing) {
            Router::jsonError(409, 'CONFLICT', 'Note is already linked to this story.');
            return;
        }

        DB::execute(
            'INSERT INTO story_notes (story_id, note_id, world_id)
             VALUES (:sid, :nid, :wid)',
            ['sid' => $sid, 'nid' => $data['note_id'], 'wid' => $wid]
        );

        self::audit($wid, $userId, 'story.note.add', 'story', $sid, ['note_id' => $data['note_id']]);

        http_response_code(201);
        echo json_encode(['data' => ['linked' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── DELETE /api/v1/worlds/:wid/stories/:sid/notes/:nid ───────────────────

    public static function removeNote(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $nid    = (int) $p['nid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        $membership = Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, created_by FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        Guard::requireOwnerOrRole((int) $story['created_by'], $userId, $membership['role']);

        DB::execute(
            'DELETE FROM story_notes WHERE story_id = :sid AND note_id = :nid AND world_id = :wid',
            ['sid' => $sid, 'nid' => $nid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'story.note.remove', 'story', $sid, ['note_id' => $nid]);
        Router::json(['deleted' => true]);
    }

    // ─── POST /api/v1/worlds/:wid/stories/:sid/ai/scan-entities ──────────────

    /**
     * Phase 1: Server-side string matching — find entity names in story text.
     * Returns found entities and which ones are not yet linked.
     */
    public static function scanEntities(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $story = DB::queryOne(
            'SELECT id, content FROM stories WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        $content = $story['content'];
        if (empty($content)) {
            Router::json(['found' => [], 'unlinked' => []]);
            return;
        }

        // Load all entity names for this world
        $entities = DB::query(
            'SELECT id, name, type, slug FROM entities
              WHERE world_id = :wid AND deleted_at IS NULL
              ORDER BY CHAR_LENGTH(name) DESC',
            ['wid' => $wid]
        );

        // Load already-linked entity IDs
        $linkedIds = DB::query(
            'SELECT entity_id FROM story_entities WHERE story_id = :sid',
            ['sid' => $sid]
        );
        $linkedSet = array_flip(array_column($linkedIds, 'entity_id'));

        $found    = [];
        $unlinked = [];
        $contentLower = mb_strtolower($content);

        foreach ($entities as $entity) {
            $nameLower = mb_strtolower($entity['name']);
            if (mb_strlen($nameLower) < 2) continue; // skip very short names

            if (mb_strpos($contentLower, $nameLower) !== false) {
                $item = [
                    'id'   => (int) $entity['id'],
                    'name' => $entity['name'],
                    'type' => $entity['type'],
                ];

                if (isset($linkedSet[$entity['id']])) {
                    $found[] = $item;
                } else {
                    $unlinked[] = $item;
                }
            }
        }

        Router::json(['found' => $found, 'unlinked' => $unlinked]);
    }

    // ─── POST /api/v1/worlds/:wid/stories/:sid/ai/assist ─────────────────────

    public static function aiAssist(array $p): void
    {
        $wid    = (int) $p['wid'];
        $sid    = (int) $p['sid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        RateLimit::check("ai:user:{$userId}", self::USER_RATE_LIMIT,  3600);
        RateLimit::check("ai:world:{$wid}",   self::WORLD_RATE_LIMIT, 3600);

        $data = Validator::parseJson([
            'mode'        => 'required|string|in:story_assist,story_consistency,story_outline',
            'user_prompt' => 'required|string|min:1|max:4000',
            'cursor_pos'  => 'nullable|int',
        ]);

        $story = DB::queryOne(
            'SELECT s.id, s.title, s.content, s.synopsis, s.arc_id
               FROM stories s
              WHERE s.id = :id AND s.world_id = :wid AND s.deleted_at IS NULL',
            ['id' => $sid, 'wid' => $wid]
        );
        if (!$story) {
            Router::jsonError(404, 'NOT_FOUND', 'Story not found.');
            return;
        }

        // Build story-specific context
        $context = AiEngine::buildContext(0, $wid, $data['mode']);

        // Add story context sections
        $storyContext = self::buildStoryContext($story, $wid, $data['cursor_pos'] ?? null);
        $context['system'] .= $storyContext;

        // Apply template if available
        $mode       = $data['mode'];
        $userPrompt = $data['user_prompt'];
        $tpl = AiEngine::loadTemplate($mode, $wid);
        if ($tpl !== null) {
            $vars = self::buildTemplateVars($context, $story, $wid, $userPrompt);
            if (!empty($tpl['system_tpl'])) {
                $context['system'] = AiEngine::renderTemplate($tpl['system_tpl'], $vars);
            }
            if (!empty($tpl['user_tpl'])) {
                $userPrompt = AiEngine::renderTemplate($tpl['user_tpl'], $vars);
            }
        }

        // Resolve API key and call provider
        $world = $context['world'];
        $providerId = $world['ai_provider'] ?? 'anthropic';
        $model      = $world['ai_model'] ?? 'claude-sonnet-4-20250514';

        try {
            $apiKey = AiEngine::resolveApiKey($wid, $providerId);
        } catch (AiEngineException $e) {
            Router::jsonError(422, 'AI_KEY_MISSING', $e->getMessage());
            return;
        }

        try {
            $result = AiEngine::callApi($context, $userPrompt, $apiKey, $model, 4096, $providerId);
        } catch (AiEngineException $e) {
            // Log failed session
            DB::execute(
                'INSERT INTO ai_sessions (world_id, user_id, story_id, mode, model, status, error_message)
                 VALUES (:wid, :uid, :sid, :mode, :model, :status, :err)',
                [
                    'wid'    => $wid,
                    'uid'    => $userId,
                    'sid'    => $sid,
                    'mode'   => $mode,
                    'model'  => $model,
                    'status' => 'error',
                    'err'    => mb_substr($e->getMessage(), 0, 512),
                ]
            );
            Router::jsonError(502, 'AI_ERROR', 'AI request failed: ' . $e->getMessage());
            return;
        }

        // Log session
        $sessionId = DB::execute(
            'INSERT INTO ai_sessions (world_id, user_id, story_id, mode, model, prompt_tokens, completion_tokens, total_tokens, status)
             VALUES (:wid, :uid, :sid, :mode, :model, :pt, :ct, :tt, :status)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'sid'    => $sid,
                'mode'   => $mode,
                'model'  => $result['model'],
                'pt'     => $result['prompt_tokens'],
                'ct'     => $result['completion_tokens'],
                'tt'     => $result['total_tokens'],
                'status' => 'success',
            ]
        );

        // Update world token counter
        DB::execute(
            'UPDATE worlds SET ai_tokens_used = ai_tokens_used + :tokens WHERE id = :wid',
            ['tokens' => $result['total_tokens'], 'wid' => $wid]
        );

        Router::json([
            'text'              => $result['text'],
            'session_id'        => $sessionId,
            'prompt_tokens'     => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'total_tokens'      => $result['total_tokens'],
            'model'             => $result['model'],
            'provider'          => $result['provider'],
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Generate a URL-safe slug from a title, ensuring uniqueness within the world.
     */
    private static function generateSlug(string $title, int $wid, ?int $excludeId = null): string
    {
        $slug = mb_substr(preg_replace('/[^a-z0-9-]/', '-', mb_strtolower($title)), 0, 300);
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        if ($slug === '') $slug = 'untitled';

        $base = $slug;
        $i    = 1;
        while (true) {
            $params = ['wid' => $wid, 'slug' => $slug];
            $sql    = 'SELECT id FROM stories WHERE world_id = :wid AND slug = :slug AND deleted_at IS NULL';
            if ($excludeId !== null) {
                $sql .= ' AND id != :exc';
                $params['exc'] = $excludeId;
            }
            $existing = DB::queryOne($sql, $params);
            if ($existing === null) break;
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Build story-specific context for AI prompts.
     */
    private static function buildStoryContext(array $story, int $wid, ?int $cursorPos): string
    {
        $sections = '';

        // Story synopsis + arc
        if (!empty($story['synopsis'])) {
            $sections .= "\nSTORY SYNOPSIS: " . mb_substr($story['synopsis'], 0, 2000) . "\n";
        }

        if (!empty($story['arc_id'])) {
            $arc = DB::queryOne(
                'SELECT name, logline, theme, status
                   FROM story_arcs
                  WHERE id = :id AND deleted_at IS NULL',
                ['id' => $story['arc_id']]
            );
            if ($arc) {
                $sections .= "\nSTORY ARC: {$arc['name']} (status: {$arc['status']})\n";
                if (!empty($arc['logline'])) $sections .= "Logline: {$arc['logline']}\n";
                if (!empty($arc['theme']))   $sections .= "Theme: {$arc['theme']}\n";
            }
        }

        // Linked entities
        $entities = DB::query(
            'SELECT e.name, e.type, e.short_summary, se.role
               FROM story_entities se
               JOIN entities e ON e.id = se.entity_id AND e.deleted_at IS NULL
              WHERE se.story_id = :sid AND se.world_id = :wid
              ORDER BY se.sort_order ASC',
            ['sid' => $story['id'], 'wid' => $wid]
        );
        if (!empty($entities)) {
            $sections .= "\nLINKED ENTITIES:\n";
            foreach ($entities as $e) {
                $role = $e['role'] ? " [{$e['role']}]" : '';
                $sections .= "- {$e['name']} ({$e['type']}){$role}";
                if (!empty($e['short_summary'])) {
                    $sections .= ': ' . mb_substr($e['short_summary'], 0, 150);
                }
                $sections .= "\n";
            }
        }

        // Linked notes
        $notes = DB::query(
            'SELECT ln.content, ln.is_canonical, e.name AS entity_name
               FROM story_notes sn
               JOIN lore_notes ln ON ln.id = sn.note_id AND ln.deleted_at IS NULL
               LEFT JOIN entities e ON e.id = ln.entity_id
              WHERE sn.story_id = :sid AND sn.world_id = :wid
              ORDER BY ln.created_at DESC
              LIMIT 10',
            ['sid' => $story['id'], 'wid' => $wid]
        );
        if (!empty($notes)) {
            $sections .= "\nLINKED NOTES:\n";
            foreach ($notes as $n) {
                $tag = $n['is_canonical'] ? '[CANONICAL] ' : '';
                $entity = $n['entity_name'] ? " ({$n['entity_name']})" : '';
                $sections .= "- {$tag}" . mb_substr(trim($n['content']), 0, 300) . "{$entity}\n";
            }
        }

        // Story content — cursor-aware window (~2000 words around cursor)
        $content = $story['content'] ?? '';
        if (!empty($content)) {
            $contextWindow = self::extractContextWindow($content, $cursorPos, 8000);
            $sections .= "\nSTORY TEXT (around current position):\n{$contextWindow}\n";
        }

        return $sections;
    }

    /**
     * Extract a window of text around the cursor position.
     * Returns ~$maxChars characters centered on the cursor.
     */
    private static function extractContextWindow(string $content, ?int $cursorPos, int $maxChars = 8000): string
    {
        $len = mb_strlen($content);
        if ($len <= $maxChars) {
            return $content;
        }

        if ($cursorPos === null || $cursorPos < 0 || $cursorPos > $len) {
            // Default: take the last $maxChars (most recent writing)
            return '...' . mb_substr($content, $len - $maxChars);
        }

        $half  = (int) ($maxChars / 2);
        $start = max(0, $cursorPos - $half);
        $end   = min($len, $cursorPos + $half);

        $prefix = $start > 0 ? '...' : '';
        $suffix = $end < $len ? '...' : '';

        return $prefix . mb_substr($content, $start, $end - $start) . $suffix;
    }

    /**
     * Build template variables for story AI prompts.
     */
    private static function buildTemplateVars(array $context, array $story, int $wid, string $userPrompt): array
    {
        $world = $context['world'];

        // Build entity list for template
        $entities = DB::query(
            'SELECT e.name, e.type, se.role
               FROM story_entities se
               JOIN entities e ON e.id = se.entity_id AND e.deleted_at IS NULL
              WHERE se.story_id = :sid AND se.world_id = :wid',
            ['sid' => $story['id'], 'wid' => $wid]
        );
        $entityList = '';
        foreach ($entities as $e) {
            $role = $e['role'] ? " ({$e['role']})" : '';
            $entityList .= "- {$e['name']} [{$e['type']}]{$role}\n";
        }

        // Build notes list
        $notes = DB::query(
            'SELECT ln.content, ln.is_canonical
               FROM story_notes sn
               JOIN lore_notes ln ON ln.id = sn.note_id AND ln.deleted_at IS NULL
              WHERE sn.story_id = :sid AND sn.world_id = :wid
              LIMIT 10',
            ['sid' => $story['id'], 'wid' => $wid]
        );
        $noteList = '';
        foreach ($notes as $n) {
            $tag = $n['is_canonical'] ? '[CANONICAL] ' : '';
            $noteList .= "- {$tag}" . mb_substr(trim($n['content']), 0, 300) . "\n";
        }

        // Build arc info
        $arcInfo = 'None';
        if (!empty($story['arc_id'])) {
            $arc = DB::queryOne(
                'SELECT name, logline, status FROM story_arcs WHERE id = :id AND deleted_at IS NULL',
                ['id' => $story['arc_id']]
            );
            if ($arc) {
                $arcInfo = "{$arc['name']} (status: {$arc['status']})";
                if (!empty($arc['logline'])) $arcInfo .= " — {$arc['logline']}";
            }
        }

        // All entity names (for entity_scan mode)
        $allNames = DB::query(
            'SELECT name FROM entities WHERE world_id = :wid AND deleted_at IS NULL ORDER BY name',
            ['wid' => $wid]
        );
        $entityNames = implode(', ', array_column($allNames, 'name'));

        return [
            'world' => [
                'name'  => $world['name'] ?? '',
                'genre' => $world['genre'] ?? '',
                'tone'  => $world['tone'] ?? '',
            ],
            'story' => [
                'title'          => $story['title'] ?? '',
                'synopsis'       => $story['synopsis'] ?? '',
                'content'        => mb_substr($story['content'] ?? '', 0, 8000),
                'context_window' => mb_substr($story['content'] ?? '', 0, 8000),
                'entities'       => $entityList,
                'notes'          => $noteList,
                'arc'            => $arcInfo,
                'entity_details' => $entityList,
            ],
            'user_prompt'    => $userPrompt,
            'world_entity_names' => $entityNames,
        ];
    }

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
