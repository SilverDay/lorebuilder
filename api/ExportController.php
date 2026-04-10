<?php
/**
 * LoreBuilder — Export / Import Controller
 *
 * Endpoints:
 *   GET  /api/v1/worlds/:wid/export?format=json|markdown  — full world export
 *   POST /api/v1/worlds/:wid/import                       — import JSON snapshot
 *
 * JSON export schema:
 *   { "lorebuilder_version": "1", "world": {...}, "entities": [...],
 *     "relationships": [...], "tags": [...], "timelines": [...],
 *     "events": [...], "arcs": [...], "notes": [...] }
 *
 * Markdown export:
 *   One Markdown block per entity with YAML front-matter + lore body + notes.
 *   All blocks separated by --- and returned as a single text/markdown file.
 *
 * Security:
 *   - Export requires Guard(author); includes no api keys or password hashes.
 *   - Import requires Guard(owner); validates every field via Validator.
 *   - All world_id values taken from route, never from request body.
 *   - Import runs in a single DB transaction: all-or-nothing.
 */

declare(strict_types=1);

class ExportController
{
    // ─── GET /api/v1/worlds/:wid/export ───────────────────────────────────────

    public static function export(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'author');

        $query  = Validator::parseQuery([
            'format' => 'string|in:json,markdown',
        ]);
        $format = $query['format'] ?? 'json';

        // Fetch world metadata (never include ai_key_enc)
        $world = DB::queryOne(
            'SELECT id, slug, name, description, genre, tone, era_system,
                    content_warnings, ai_provider, ai_model, ai_endpoint_url, created_at
               FROM worlds
              WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $wid]
        );
        if ($world === null) {
            http_response_code(404);
            echo json_encode(['error' => 'World not found.', 'code' => 'NOT_FOUND']);
            return;
        }

        // Fetch all lore data
        $entities = DB::query(
            'SELECT id, type, name, slug, short_summary, status, lore_body, attributes_json, created_at
               FROM entities
              WHERE world_id = :wid AND deleted_at IS NULL
              ORDER BY type, name',
            ['wid' => $wid]
        );

        $entityIds = array_column($entities, 'id');

        $attributes = $entityIds ? DB::query(
            'SELECT entity_id, attr_key, attr_value, data_type, sort_order
               FROM entity_attributes
              WHERE world_id = :wid
              ORDER BY entity_id, sort_order',
            ['wid' => $wid]
        ) : [];

        $tags = DB::query(
            'SELECT id, name, color FROM tags WHERE world_id = :wid ORDER BY name',
            ['wid' => $wid]
        );

        $entityTags = $entityIds ? DB::query(
            'SELECT et.entity_id, t.name AS tag_name
               FROM entity_tags et
               JOIN tags t ON t.id = et.tag_id
              WHERE t.world_id = :wid',
            ['wid' => $wid]
        ) : [];

        $relationships = DB::query(
            'SELECT r.id, r.from_entity_id, r.to_entity_id, r.rel_type,
                    r.strength, r.notes, r.is_bidirectional AS bidirectional
               FROM entity_relationships r
              WHERE r.world_id = :wid AND r.deleted_at IS NULL
              ORDER BY r.from_entity_id',
            ['wid' => $wid]
        );

        $timelines = DB::query(
            'SELECT id, name, description, scale_mode, created_at
               FROM timelines
              WHERE world_id = :wid AND deleted_at IS NULL
              ORDER BY name',
            ['wid' => $wid]
        );

        $events = DB::query(
            'SELECT te.id, te.timeline_id, te.entity_id, te.label,
                    te.description, te.position_order, te.position_label, te.position_era
               FROM timeline_events te
              WHERE te.world_id = :wid AND te.deleted_at IS NULL
              ORDER BY te.timeline_id, te.position_order',
            ['wid' => $wid]
        );

        $arcs = DB::query(
            'SELECT sa.id, sa.name, sa.logline, sa.theme, sa.status, sa.sort_order, sa.created_at
               FROM story_arcs sa
              WHERE sa.world_id = :wid AND sa.deleted_at IS NULL
              ORDER BY sa.sort_order',
            ['wid' => $wid]
        );

        $arcEntities = DB::query(
            'SELECT arc_id, entity_id
               FROM arc_entities
              WHERE world_id = :wid',
            ['wid' => $wid]
        );

        $notes = DB::query(
            'SELECT id, entity_id, content, is_canonical, ai_generated, created_at
               FROM lore_notes
              WHERE world_id = :wid AND deleted_at IS NULL
              ORDER BY entity_id, created_at',
            ['wid' => $wid]
        );

        // Index attributes and tags by entity_id for easy lookup
        $attrsByEntity = [];
        foreach ($attributes as $a) {
            $attrsByEntity[(int) $a['entity_id']][] = $a;
        }
        $tagsByEntity = [];
        foreach ($entityTags as $et) {
            $tagsByEntity[(int) $et['entity_id']][] = $et['tag_name'];
        }
        $arcEntsByArc = [];
        foreach ($arcEntities as $ae) {
            $arcEntsByArc[(int) $ae['arc_id']][] = (int) $ae['entity_id'];
        }

        // Attach per-entity data
        foreach ($entities as &$e) {
            $eid = (int) $e['id'];
            $e['attributes'] = $attrsByEntity[$eid] ?? [];
            $e['tags']       = $tagsByEntity[$eid]  ?? [];
        }
        unset($e);

        foreach ($arcs as &$arc) {
            $arc['entity_ids'] = $arcEntsByArc[(int) $arc['id']] ?? [];
        }
        unset($arc);

        if ($format === 'markdown') {
            self::sendMarkdown($world, $entities, $relationships, $notes);
            return;
        }

        // ── JSON output ────────────────────────────────────────────────────────
        $payload = [
            'lorebuilder_version' => '1',
            'exported_at'         => date('c'),
            'world'               => $world,
            'entities'            => $entities,
            'relationships'       => $relationships,
            'tags'                => $tags,
            'timelines'           => $timelines,
            'events'              => $events,
            'arcs'                => $arcs,
            'notes'               => $notes,
        ];

        $filename = preg_replace('/[^a-z0-9_-]/', '-', strtolower($world['slug']));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '-export.json"');
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // ─── POST /api/v1/worlds/:wid/import ──────────────────────────────────────

    public static function import(array $params): void
    {
        $session = Auth::requireSession();
        $userId  = (int) $session['id'];
        $wid     = (int) $params['wid'];

        Guard::requireWorldAccess($wid, $userId, minRole: 'owner');

        $body = file_get_contents('php://input');
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Request body is empty.', 'code' => 'VALIDATION_ERROR']);
            return;
        }

        $data = json_decode($body, associative: true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON in request body.', 'code' => 'VALIDATION_ERROR']);
            return;
        }

        if (($data['lorebuilder_version'] ?? '') !== '1') {
            http_response_code(422);
            echo json_encode(['error' => 'Unsupported export version. Expected "1".', 'code' => 'VALIDATION_ERROR']);
            return;
        }

        // Validate conflict mode
        $query        = Validator::parseQuery(['conflict' => 'string|in:skip,overwrite']);
        $conflictMode = $query['conflict'] ?? 'skip';

        $stats = ['entities' => 0, 'relationships' => 0, 'notes' => 0,
                  'timelines' => 0, 'events' => 0, 'arcs' => 0, 'tags' => 0,
                  'open_points' => 0];

        DB::transaction(function () use ($wid, $userId, $data, $conflictMode, &$stats): void {
            // ── Tags ──────────────────────────────────────────────────────────
            $tagNameToId = [];
            foreach ((array) ($data['tags'] ?? []) as $tag) {
                $name  = mb_substr(trim((string) ($tag['name'] ?? '')), 0, 64);
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', $tag['color'] ?? '')
                    ? $tag['color'] : '#4A90A4';
                if ($name === '') continue;

                $existing = DB::queryOne(
                    'SELECT id FROM tags WHERE world_id = :wid AND name = :name',
                    ['wid' => $wid, 'name' => $name]
                );
                if ($existing) {
                    $tagNameToId[$name] = (int) $existing['id'];
                } else {
                    $newId = DB::execute(
                        'INSERT INTO tags (world_id, name, color) VALUES (:wid, :name, :color)',
                        ['wid' => $wid, 'name' => $name, 'color' => $color]
                    );
                    $tagNameToId[$name] = $newId;
                    $stats['tags']++;
                }
            }

            // ── Entities ──────────────────────────────────────────────────────
            $validTypes   = ['Character','Location','Event','Faction','Artefact','Creature','Concept','StoryArc','Timeline','Race'];
            $validStatuses = ['draft','published','archived'];
            $oldToNewEntity = [];

            foreach ((array) ($data['entities'] ?? []) as $ent) {
                $name   = mb_substr(trim((string) ($ent['name'] ?? '')), 0, 255);
                $type   = in_array($ent['type'] ?? '', $validTypes, true) ? $ent['type'] : 'Concept';
                $status = in_array($ent['status'] ?? '', $validStatuses, true) ? $ent['status'] : 'draft';
                $slug   = mb_substr(preg_replace('/[^a-z0-9-]/', '-', strtolower($name)), 0, 300);
                if ($name === '') continue;

                // Ensure slug uniqueness within world
                $slugBase = $slug;
                $i = 1;
                while (DB::queryOne(
                    'SELECT id FROM entities WHERE world_id = :wid AND slug = :slug AND deleted_at IS NULL',
                    ['wid' => $wid, 'slug' => $slug]
                ) !== null) {
                    $slug = $slugBase . '-' . $i++;
                }

                $newId = DB::execute(
                    'INSERT INTO entities (world_id, created_by, type, name, slug, short_summary, status, lore_body, attributes_json)
                     VALUES (:wid, :uid, :type, :name, :slug, :summary, :status, :lore, :attrs)',
                    [
                        'wid'     => $wid,
                        'uid'     => $userId,
                        'type'    => $type,
                        'name'    => $name,
                        'slug'    => $slug,
                        'summary' => mb_substr((string) ($ent['short_summary'] ?? ''), 0, 512),
                        'status'  => $status,
                        'lore'    => $ent['lore_body'] ?? null,
                        'attrs'   => isset($ent['attributes_json']) ? json_encode($ent['attributes_json']) : null,
                    ]
                );
                $oldToNewEntity[(int) ($ent['id'] ?? 0)] = $newId;
                $stats['entities']++;

                // Attributes
                foreach ((array) ($ent['attributes'] ?? []) as $i => $attr) {
                    $key = mb_substr(trim((string) ($attr['attr_key'] ?? '')), 0, 64);
                    if ($key === '') continue;
                    DB::execute(
                        'INSERT INTO entity_attributes (entity_id, world_id, attr_key, attr_value, data_type, sort_order)
                         VALUES (:eid, :wid, :key, :val, :dtype, :sort)',
                        [
                            'eid'   => $newId,
                            'wid'   => $wid,
                            'key'   => $key,
                            'val'   => mb_substr((string) ($attr['attr_value'] ?? ''), 0, 4000),
                            'dtype' => in_array($attr['data_type'] ?? '', ['string','integer','boolean','date','markdown'], true) ? $attr['data_type'] : 'string',
                            'sort'  => (int) ($attr['sort_order'] ?? $i),
                        ]
                    );
                }

                // Tags
                foreach ((array) ($ent['tags'] ?? []) as $tagName) {
                    $tagId = $tagNameToId[$tagName] ?? null;
                    if (!$tagId) continue;
                    DB::execute(
                        'INSERT IGNORE INTO entity_tags (entity_id, tag_id) VALUES (:eid, :tid)',
                        ['eid' => $newId, 'tid' => $tagId]
                    );
                }
            }

            // ── Relationships ─────────────────────────────────────────────────
            foreach ((array) ($data['relationships'] ?? []) as $rel) {
                $fromId = $oldToNewEntity[(int) ($rel['from_entity_id'] ?? 0)] ?? null;
                $toId   = $oldToNewEntity[(int) ($rel['to_entity_id']   ?? 0)] ?? null;
                if (!$fromId || !$toId) continue;
                $relType = mb_substr(trim((string) ($rel['rel_type'] ?? 'related')), 0, 64);
                if ($relType === '') $relType = 'related';
                DB::execute(
                    'INSERT INTO entity_relationships
                        (world_id, from_entity_id, to_entity_id, rel_type, strength, notes, is_bidirectional, created_by)
                     VALUES (:wid, :from, :to, :rtype, :str, :notes, :bi, :uid)',
                    [
                        'wid'   => $wid,
                        'from'  => $fromId,
                        'to'    => $toId,
                        'rtype' => $relType,
                        'str'   => $rel['strength'] ?? null,
                        'notes' => mb_substr((string) ($rel['notes'] ?? ''), 0, 1000),
                        'bi'    => (int) (bool) ($rel['bidirectional'] ?? false),
                        'uid'   => $userId,
                    ]
                );
                $stats['relationships']++;
            }

            // ── Timelines ─────────────────────────────────────────────────────
            $oldToNewTimeline = [];
            foreach ((array) ($data['timelines'] ?? []) as $tl) {
                $name = mb_substr(trim((string) ($tl['name'] ?? '')), 0, 255);
                if ($name === '') continue;
                $newId = DB::execute(
                    'INSERT INTO timelines (world_id, created_by, name, description, scale_mode)
                     VALUES (:wid, :uid, :name, :desc, :scale)',
                    [
                        'wid'   => $wid,
                        'uid'   => $userId,
                        'name'  => $name,
                        'desc'  => $tl['description'] ?? null,
                        'scale' => in_array($tl['scale_mode'] ?? '', ['numeric','date','era'], true)
                            ? $tl['scale_mode'] : 'numeric',
                    ]
                );
                $oldToNewTimeline[(int) ($tl['id'] ?? 0)] = $newId;
                $stats['timelines']++;
            }

            // ── Timeline Events ───────────────────────────────────────────────
            foreach ((array) ($data['events'] ?? []) as $ev) {
                $tlId    = $oldToNewTimeline[(int) ($ev['timeline_id'] ?? 0)] ?? null;
                $entId   = $oldToNewEntity[(int) ($ev['entity_id'] ?? 0)] ?? null;
                $label   = mb_substr(trim((string) ($ev['label'] ?? '')), 0, 255);
                if (!$tlId || $label === '') continue;
                DB::execute(
                    'INSERT INTO timeline_events
                        (timeline_id, world_id, entity_id, label, description, position_order, position_label, position_era)
                     VALUES (:tid, :wid, :eid, :label, :desc, :pos, :plabel, :pera)',
                    [
                        'tid'    => $tlId,
                        'wid'    => $wid,
                        'eid'    => $entId,
                        'label'  => $label,
                        'desc'   => $ev['description'] ?? null,
                        'pos'    => (int) ($ev['position_order'] ?? 0),
                        'plabel' => mb_substr((string) ($ev['position_label'] ?? ''), 0, 128),
                        'pera'   => mb_substr((string) ($ev['position_era']   ?? ''), 0, 128),
                    ]
                );
                $stats['events']++;
            }

            // ── Story Arcs ────────────────────────────────────────────────────
            $validArcStatuses = ['seed','rising_action','climax','resolution','complete','abandoned'];
            $oldToNewArc = [];
            foreach ((array) ($data['arcs'] ?? []) as $idx => $arc) {
                $name = mb_substr(trim((string) ($arc['name'] ?? '')), 0, 255);
                if ($name === '') continue;
                $newId = DB::execute(
                    'INSERT INTO story_arcs (world_id, created_by, name, logline, theme, status, sort_order)
                     VALUES (:wid, :uid, :name, :logline, :theme, :status, :sort)',
                    [
                        'wid'     => $wid,
                        'uid'     => $userId,
                        'name'    => $name,
                        'logline' => mb_substr((string) ($arc['logline'] ?? ''), 0, 512),
                        'theme'   => mb_substr((string) ($arc['theme']   ?? ''), 0, 255),
                        'status'  => in_array($arc['status'] ?? '', $validArcStatuses, true)
                            ? $arc['status'] : 'seed',
                        'sort'    => (int) ($arc['sort_order'] ?? $idx),
                    ]
                );
                $oldToNewArc[(int) ($arc['id'] ?? 0)] = $newId;
                $stats['arcs']++;

                foreach ((array) ($arc['entity_ids'] ?? []) as $oldEid) {
                    $newEid = $oldToNewEntity[(int) $oldEid] ?? null;
                    if (!$newEid) continue;
                    DB::execute(
                        'INSERT IGNORE INTO arc_entities (arc_id, entity_id, world_id) VALUES (:arc, :eid, :wid)',
                        ['arc' => $newId, 'eid' => $newEid, 'wid' => $wid]
                    );
                }
            }

            // ── Notes ─────────────────────────────────────────────────────────
            foreach ((array) ($data['notes'] ?? []) as $note) {
                $content = trim((string) ($note['content'] ?? ''));
                if ($content === '') continue;
                $entId = $oldToNewEntity[(int) ($note['entity_id'] ?? 0)] ?? null;
                DB::execute(
                    'INSERT INTO lore_notes (world_id, entity_id, created_by, content, is_canonical, ai_generated)
                     VALUES (:wid, :eid, :uid, :content, :canon, :ai)',
                    [
                        'wid'     => $wid,
                        'eid'     => $entId,
                        'uid'     => $userId,
                        'content' => $content,
                        'canon'   => (int) (bool) ($note['is_canonical'] ?? false),
                        'ai'      => (int) (bool) ($note['ai_generated'] ?? false),
                    ]
                );
                $stats['notes']++;
            }

            // ── Open Points ───────────────────────────────────────────────────
            $validOpStatuses   = ['open','in_progress','resolved','wont_fix'];
            $validOpPriorities = ['low','medium','high','critical'];
            foreach ((array) ($data['open_points'] ?? []) as $op) {
                $title = mb_substr(trim((string) ($op['title'] ?? '')), 0, 512);
                if ($title === '') continue;
                $entId = $oldToNewEntity[(int) ($op['entity_id'] ?? 0)] ?? null;
                DB::execute(
                    'INSERT INTO open_points (world_id, entity_id, created_by, title, description, status, priority)
                     VALUES (:wid, :eid, :uid, :title, :desc, :status, :priority)',
                    [
                        'wid'      => $wid,
                        'eid'      => $entId,
                        'uid'      => $userId,
                        'title'    => $title,
                        'desc'     => $op['description'] ?? null,
                        'status'   => in_array($op['status'] ?? '', $validOpStatuses, true) ? $op['status'] : 'open',
                        'priority' => in_array($op['priority'] ?? '', $validOpPriorities, true) ? $op['priority'] : 'medium',
                    ]
                );
                $stats['open_points']++;
            }
        });

        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, diff_json)
             VALUES (:wid, :uid, :action, :type, :tid, :ip, :diff)',
            [
                'wid'    => $wid,
                'uid'    => $userId,
                'action' => 'world.import',
                'type'   => 'world',
                'tid'    => $wid,
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'diff'   => json_encode(['after' => $stats]),
            ]
        );

        http_response_code(200);
        echo json_encode(['data' => array_merge(['imported' => true], $stats)]);
    }

    // ─── Markdown export helper ───────────────────────────────────────────────

    private static function sendMarkdown(
        array $world,
        array $entities,
        array $relationships,
        array $notes
    ): void {
        $relsByEntity = [];
        foreach ($relationships as $r) {
            $relsByEntity[(int) $r['from_entity_id']][] = $r;
            if ($r['bidirectional']) {
                $relsByEntity[(int) $r['to_entity_id']][] = $r;
            }
        }
        $notesByEntity = [];
        foreach ($notes as $n) {
            $notesByEntity[(int) ($n['entity_id'] ?? 0)][] = $n;
        }

        $md   = "# {$world['name']}\n\n";
        $md  .= "**Genre:** " . ($world['genre'] ?? 'unspecified') . "  \n";
        $md  .= "**Tone:** "  . ($world['tone']  ?? 'unspecified') . "  \n";
        if (!empty($world['description'])) {
            $md .= "\n" . $world['description'] . "\n";
        }
        $md .= "\n---\n\n";

        foreach ($entities as $e) {
            $eid = (int) $e['id'];
            $tags = implode(', ', $e['tags'] ?? []);

            $md .= "## {$e['name']}\n\n";
            $md .= "```yaml\n";
            $md .= "type: {$e['type']}\n";
            $md .= "status: {$e['status']}\n";
            if ($tags)              $md .= "tags: [{$tags}]\n";
            if ($e['short_summary']) $md .= "summary: " . str_replace("\n", ' ', $e['short_summary']) . "\n";
            $md .= "```\n\n";

            if (!empty($e['lore_body'])) {
                $md .= $e['lore_body'] . "\n\n";
            }

            if (!empty($e['attributes'])) {
                $md .= "### Attributes\n\n";
                foreach ($e['attributes'] as $attr) {
                    $md .= "- **{$attr['attr_key']}:** {$attr['attr_value']}\n";
                }
                $md .= "\n";
            }

            $rels = $relsByEntity[$eid] ?? [];
            if ($rels) {
                $md .= "### Relationships\n\n";
                foreach ($rels as $r) {
                    $other = ((int) $r['from_entity_id'] === $eid)
                        ? "entity #{$r['to_entity_id']}"
                        : "entity #{$r['from_entity_id']}";
                    $md .= "- **{$r['rel_type']}** → {$other}";
                    if ($r['notes']) $md .= ": " . str_replace("\n", ' ', $r['notes']);
                    $md .= "\n";
                }
                $md .= "\n";
            }

            $entityNotes = $notesByEntity[$eid] ?? [];
            if ($entityNotes) {
                $md .= "### Notes\n\n";
                foreach ($entityNotes as $n) {
                    $canonical = $n['is_canonical'] ? ' *(canonical)*' : '';
                    $md .= "> {$n['content']}{$canonical}\n\n";
                }
            }

            $md .= "---\n\n";
        }

        $filename = preg_replace('/[^a-z0-9_-]/', '-', strtolower($world['slug']));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '-export.md"');
        http_response_code(200);
        echo $md;
    }
}
