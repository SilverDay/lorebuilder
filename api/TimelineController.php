<?php
/**
 * LoreBuilder — Timeline & Event Controller
 *
 * Handles:
 *   /api/v1/worlds/:wid/timelines/*                           — timeline CRUD
 *   /api/v1/worlds/:wid/timelines/:tid/events/*               — event CRUD
 *   /api/v1/worlds/:wid/timelines/:tid/events/reorder         — bulk position_order update
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

class TimelineController
{
    private const VALID_SCALE_MODES = ['era', 'numeric', 'date'];

    // ─── GET /api/v1/worlds/:wid/timelines ───────────────────────────────────

    public static function index(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $timelines = DB::query(
            'SELECT t.id, t.name, t.description, t.scale_mode, t.era_labels,
                    t.color, t.sort_order, t.created_by, t.created_at,
                    COUNT(e.id) AS event_count
               FROM timelines t
               LEFT JOIN timeline_events e ON e.timeline_id = t.id AND e.deleted_at IS NULL
              WHERE t.world_id = :wid AND t.deleted_at IS NULL
              GROUP BY t.id
              ORDER BY t.sort_order ASC, t.name ASC',
            ['wid' => $wid]
        );

        Router::json($timelines);
    }

    // ─── POST /api/v1/worlds/:wid/timelines ──────────────────────────────────

    public static function create(array $p): void
    {
        $wid    = (int) $p['wid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $data = Validator::parseJson([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'scale_mode'  => 'nullable|in:era,numeric,date',
            'era_labels'  => 'nullable|array',
            'color'       => 'nullable|string|max:7',
            'sort_order'  => 'nullable|int|min:0|max:32767',
        ]);

        $color = $data['color'] ?? '#4A90A4';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a valid hex color (#RRGGBB).', );
            return;
        }

        $eraLabels = null;
        if (!empty($data['era_labels'])) {
            // Must be a flat list of strings
            foreach ($data['era_labels'] as $label) {
                if (!is_string($label)) {
                    Router::jsonError(400, 'VALIDATION_ERROR', 'era_labels must be an array of strings.');
                    return;
                }
            }
            $eraLabels = json_encode(array_values($data['era_labels']));
        }

        $id = DB::execute(
            'INSERT INTO timelines (world_id, created_by, name, description, scale_mode, era_labels, color, sort_order)
             VALUES (:wid, :uid, :name, :desc, :mode, :era, :color, :sort)',
            [
                'wid'   => $wid,
                'uid'   => $userId,
                'name'  => $data['name'],
                'desc'  => $data['description'] ?? null,
                'mode'  => $data['scale_mode']  ?? 'era',
                'era'   => $eraLabels,
                'color' => $color,
                'sort'  => $data['sort_order']  ?? 0,
            ]
        );

        self::audit($wid, $userId, 'timeline.create', 'timeline', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/worlds/:wid/timelines/:tid ──────────────────────────────

    public static function show(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        $timeline = DB::queryOne(
            'SELECT id, name, description, scale_mode, era_labels, color,
                    sort_order, created_by, created_at, updated_at
               FROM timelines
              WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $tid, 'wid' => $wid]
        );
        if (!$timeline) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        $timeline['era_labels'] = $timeline['era_labels']
            ? json_decode((string) $timeline['era_labels'], true)
            : [];

        $events = DB::query(
            'SELECT id, entity_id, label, description, position_order,
                    position_era, position_value, position_label, color, created_at
               FROM timeline_events
              WHERE timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL
              ORDER BY position_order ASC',
            ['tid' => $tid, 'wid' => $wid]
        );

        $timeline['events'] = $events;
        Router::json($timeline);
    }

    // ─── PATCH /api/v1/worlds/:wid/timelines/:tid ────────────────────────────

    public static function update(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $timeline = DB::queryOne(
            'SELECT id FROM timelines WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $tid, 'wid' => $wid]
        );
        if (!$timeline) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        $data = Validator::parseJson([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'scale_mode'  => 'nullable|in:era,numeric,date',
            'era_labels'  => 'nullable|array',
            'color'       => 'nullable|string|max:7',
            'sort_order'  => 'nullable|int|min:0|max:32767',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a valid hex color (#RRGGBB).');
            return;
        }

        $sets   = [];
        $params = ['id' => $tid, 'wid' => $wid];

        foreach (['name', 'description', 'scale_mode', 'color', 'sort_order'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if (array_key_exists('era_labels', $data)) {
            if (!empty($data['era_labels'])) {
                foreach ($data['era_labels'] as $label) {
                    if (!is_string($label)) {
                        Router::jsonError(400, 'VALIDATION_ERROR', 'era_labels must be an array of strings.');
                        return;
                    }
                }
                $params['era_labels'] = json_encode(array_values($data['era_labels']));
            } else {
                $params['era_labels'] = null;
            }
            $sets[] = 'era_labels = :era_labels';
        }

        DB::execute(
            'UPDATE timelines SET ' . implode(', ', $sets) . ' WHERE id = :id AND world_id = :wid',
            $params
        );

        self::audit($wid, $userId, 'timeline.update', 'timeline', $tid, $data);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/timelines/:tid ───────────────────────────

    public static function destroy(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'admin', $isPlatformAdmin);

        $timeline = DB::queryOne(
            'SELECT id FROM timelines WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $tid, 'wid' => $wid]
        );
        if (!$timeline) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        DB::execute(
            'UPDATE timelines SET deleted_at = NOW() WHERE id = :id AND world_id = :wid',
            ['id' => $tid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'timeline.delete', 'timeline', $tid);
        Router::json(['deleted' => true]);
    }

    // ─── GET /api/v1/worlds/:wid/timelines/:tid/events ───────────────────────

    public static function events(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'viewer', $isPlatformAdmin);

        if (!self::fetchTimeline($wid, $tid)) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        $rows = DB::query(
            'SELECT id, entity_id, label, description, position_order,
                    position_era, position_value, position_label, color, created_at
               FROM timeline_events
              WHERE timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL
              ORDER BY position_order ASC',
            ['tid' => $tid, 'wid' => $wid]
        );

        Router::json($rows);
    }

    // ─── POST /api/v1/worlds/:wid/timelines/:tid/events ──────────────────────

    public static function createEvent(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        if (!self::fetchTimeline($wid, $tid)) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        $data = Validator::parseJson([
            'label'          => 'required|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'entity_id'      => 'nullable|int',
            'position_order' => 'nullable|int',
            'position_era'   => 'nullable|string|max:128',
            'position_value' => 'nullable|string|max:32',
            'position_label' => 'nullable|string|max:64',
            'color'          => 'nullable|string|max:7',
        ]);

        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a valid hex color (#RRGGBB).');
            return;
        }

        // If entity_id provided, confirm it belongs to this world
        if (!empty($data['entity_id'])) {
            $entity = DB::queryOne(
                'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
                ['id' => $data['entity_id'], 'wid' => $wid]
            );
            if (!$entity) {
                Router::jsonError(404, 'NOT_FOUND', 'Entity not found in this world.');
                return;
            }
        }

        // Default position_order to max+1
        $maxOrder = DB::queryOne(
            'SELECT COALESCE(MAX(position_order), -1) AS max_ord
               FROM timeline_events
              WHERE timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL',
            ['tid' => $tid, 'wid' => $wid]
        );
        $posOrder = $data['position_order'] ?? ((int) $maxOrder['max_ord'] + 1);

        $id = DB::execute(
            'INSERT INTO timeline_events
                (timeline_id, world_id, entity_id, label, description,
                 position_order, position_era, position_value, position_label, color)
             VALUES (:tid, :wid, :eid, :label, :desc, :pos, :pera, :pval, :plabel, :color)',
            [
                'tid'    => $tid,
                'wid'    => $wid,
                'eid'    => $data['entity_id']      ?? null,
                'label'  => $data['label'],
                'desc'   => $data['description']    ?? null,
                'pos'    => $posOrder,
                'pera'   => $data['position_era']   ?? null,
                'pval'   => $data['position_value'] ?? null,
                'plabel' => $data['position_label'] ?? null,
                'color'  => $data['color']           ?? null,
            ]
        );

        self::audit($wid, $userId, 'timeline.event.create', 'timeline_event', $id);

        http_response_code(201);
        echo json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    // ─── PATCH /api/v1/worlds/:wid/timelines/:tid/events/:eid ────────────────

    public static function updateEvent(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $eid    = (int) $p['eid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $event = DB::queryOne(
            'SELECT id FROM timeline_events
              WHERE id = :id AND timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $eid, 'tid' => $tid, 'wid' => $wid]
        );
        if (!$event) {
            Router::jsonError(404, 'NOT_FOUND', 'Event not found.');
            return;
        }

        $data = Validator::parseJson([
            'label'          => 'nullable|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'entity_id'      => 'nullable|int',
            'position_order' => 'nullable|int',
            'position_era'   => 'nullable|string|max:128',
            'position_value' => 'nullable|string|max:32',
            'position_label' => 'nullable|string|max:64',
            'color'          => 'nullable|string|max:7',
        ]);

        if (empty($data)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'No updatable fields provided.');
            return;
        }

        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'color must be a valid hex color (#RRGGBB).');
            return;
        }

        if (!empty($data['entity_id'])) {
            $entity = DB::queryOne(
                'SELECT id FROM entities WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
                ['id' => $data['entity_id'], 'wid' => $wid]
            );
            if (!$entity) {
                Router::jsonError(404, 'NOT_FOUND', 'Entity not found in this world.');
                return;
            }
        }

        $sets   = [];
        $params = ['id' => $eid, 'tid' => $tid, 'wid' => $wid];
        $allowed = ['label', 'description', 'entity_id', 'position_order',
                    'position_era', 'position_value', 'position_label', 'color'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        DB::execute(
            'UPDATE timeline_events SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND timeline_id = :tid AND world_id = :wid',
            $params
        );

        self::audit($wid, $userId, 'timeline.event.update', 'timeline_event', $eid, $data);
        Router::json(['updated' => true]);
    }

    // ─── DELETE /api/v1/worlds/:wid/timelines/:tid/events/:eid ───────────────

    public static function destroyEvent(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $eid    = (int) $p['eid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        $event = DB::queryOne(
            'SELECT id FROM timeline_events
              WHERE id = :id AND timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $eid, 'tid' => $tid, 'wid' => $wid]
        );
        if (!$event) {
            Router::jsonError(404, 'NOT_FOUND', 'Event not found.');
            return;
        }

        DB::execute(
            'UPDATE timeline_events SET deleted_at = NOW()
              WHERE id = :id AND timeline_id = :tid AND world_id = :wid',
            ['id' => $eid, 'tid' => $tid, 'wid' => $wid]
        );

        self::audit($wid, $userId, 'timeline.event.delete', 'timeline_event', $eid);
        Router::json(['deleted' => true]);
    }

    // ─── PUT /api/v1/worlds/:wid/timelines/:tid/events/reorder ───────────────

    public static function reorderEvents(array $p): void
    {
        $wid    = (int) $p['wid'];
        $tid    = (int) $p['tid'];
        $userId = $p['user']['id'];
        $isPlatformAdmin = (bool) $p['user']['is_platform_admin'];

        Guard::requireWorldAccess($wid, $userId, 'author', $isPlatformAdmin);

        if (!self::fetchTimeline($wid, $tid)) {
            Router::jsonError(404, 'NOT_FOUND', 'Timeline not found.');
            return;
        }

        $body   = Validator::parseJson(['order' => 'required|array']);
        $order  = $body['order']; // expected: [{id: N, position_order: N}, …]

        if (empty($order)) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'order must be a non-empty array.');
            return;
        }

        DB::transaction(function () use ($order, $tid, $wid): void {
            foreach ($order as $item) {
                if (!isset($item['id'], $item['position_order'])
                    || !is_int($item['id'])
                    || !is_int($item['position_order'])) {
                    throw new \InvalidArgumentException('Each order item must have integer id and position_order.');
                }
                DB::execute(
                    'UPDATE timeline_events
                        SET position_order = :pos
                      WHERE id = :id AND timeline_id = :tid AND world_id = :wid AND deleted_at IS NULL',
                    ['pos' => $item['position_order'], 'id' => $item['id'], 'tid' => $tid, 'wid' => $wid]
                );
            }
        });

        self::audit($wid, $userId, 'timeline.events.reorder', 'timeline', $tid);
        Router::json(['reordered' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function fetchTimeline(int $wid, int $tid): ?array
    {
        return DB::queryOne(
            'SELECT id FROM timelines WHERE id = :id AND world_id = :wid AND deleted_at IS NULL',
            ['id' => $tid, 'wid' => $wid]
        );
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
