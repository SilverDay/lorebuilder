#!/usr/bin/env php
<?php
/**
 * LoreBuilder CLI — World Export
 *
 * Usage:
 *   php scripts/export.php --world=<id|slug> [--format=json|markdown] [--output=<path>]
 *
 * Exports a world to JSON or Markdown and writes to stdout or a file.
 * Identical data as GET /api/v1/worlds/:wid/export — no HTTP layer.
 *
 * Options:
 *   --world=<id|slug>     World ID or slug (required)
 *   --format=json         Output format: json (default) or markdown
 *   --output=<path>       Write to file instead of stdout
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/DB.php';

$opts = getopt('', ['world:', 'format::', 'output::']);

if (empty($opts['world'])) {
    fwrite(STDERR, "Usage: php export.php --world=<id|slug> [--format=json|markdown] [--output=<path>]\n");
    exit(1);
}

$worldRef = $opts['world'];
$format   = $opts['format'] ?? 'json';
$outPath  = $opts['output'] ?? null;

if (!in_array($format, ['json', 'markdown'], true)) {
    fwrite(STDERR, "Invalid format '{$format}'. Use json or markdown.\n");
    exit(1);
}

// Resolve world by ID or slug
$worldSql = 'SELECT id, slug, name, description, genre, tone, era_system,
                    content_warnings, ai_provider, ai_model, ai_endpoint_url, created_at
               FROM worlds WHERE deleted_at IS NULL';
$world = is_numeric($worldRef)
    ? DB::queryOne($worldSql . ' AND id = :v', ['v' => (int) $worldRef])
    : DB::queryOne($worldSql . ' AND slug = :v', ['v' => $worldRef]);

if (!$world) {
    fwrite(STDERR, "World '{$worldRef}' not found.\n");
    exit(1);
}

$wid = (int) $world['id'];

// Fetch all data
$entities = DB::query(
    'SELECT id, type, name, slug, short_summary, status, lore_body, attributes_json, created_at
       FROM entities WHERE world_id = :wid AND deleted_at IS NULL ORDER BY type, name',
    ['wid' => $wid]
);
$attributes = DB::query(
    'SELECT entity_id, attr_key, attr_value, data_type, sort_order
       FROM entity_attributes WHERE world_id = :wid ORDER BY entity_id, sort_order',
    ['wid' => $wid]
);
$tags = DB::query('SELECT id, name, color FROM tags WHERE world_id = :wid ORDER BY name', ['wid' => $wid]);
$entityTags = DB::query(
    'SELECT et.entity_id, t.name AS tag_name FROM entity_tags et JOIN tags t ON t.id = et.tag_id WHERE t.world_id = :wid',
    ['wid' => $wid]
);
$relationships = DB::query(
    'SELECT id, from_entity_id, to_entity_id, rel_type, strength, notes, is_bidirectional AS bidirectional
       FROM entity_relationships WHERE world_id = :wid AND deleted_at IS NULL',
    ['wid' => $wid]
);
$timelines = DB::query(
    'SELECT id, name, description, scale_mode FROM timelines WHERE world_id = :wid AND deleted_at IS NULL',
    ['wid' => $wid]
);
$events = DB::query(
    'SELECT id, timeline_id, entity_id, label, description, position_order, position_label, position_era
       FROM timeline_events WHERE world_id = :wid AND deleted_at IS NULL ORDER BY timeline_id, position_order',
    ['wid' => $wid]
);
$arcs = DB::query(
    'SELECT id, name, logline, theme, status, sort_order FROM story_arcs WHERE world_id = :wid AND deleted_at IS NULL ORDER BY sort_order',
    ['wid' => $wid]
);
$arcEntities = DB::query('SELECT arc_id, entity_id FROM arc_entities WHERE world_id = :wid', ['wid' => $wid]);
$notes = DB::query(
    'SELECT id, entity_id, content, is_canonical, ai_generated, created_at
       FROM lore_notes WHERE world_id = :wid AND deleted_at IS NULL ORDER BY entity_id, created_at',
    ['wid' => $wid]
);

// Index
$attrByEnt  = [];
foreach ($attributes as $a) { $attrByEnt[(int)$a['entity_id']][] = $a; }
$tagByEnt   = [];
foreach ($entityTags as $et) { $tagByEnt[(int)$et['entity_id']][] = $et['tag_name']; }
$arcEntByArc = [];
foreach ($arcEntities as $ae) { $arcEntByArc[(int)$ae['arc_id']][] = (int)$ae['entity_id']; }

foreach ($entities as &$e) {
    $e['attributes'] = $attrByEnt[(int)$e['id']] ?? [];
    $e['tags']       = $tagByEnt[(int)$e['id']]  ?? [];
}
unset($e);
foreach ($arcs as &$arc) { $arc['entity_ids'] = $arcEntByArc[(int)$arc['id']] ?? []; }
unset($arc);

// World query now uses explicit columns — no sensitive fields to strip

if ($format === 'json') {
    $output = json_encode([
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
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    // Markdown
    $output  = "# {$world['name']}\n\n";
    $output .= "**Genre:** " . ($world['genre'] ?? 'unspecified') . "\n";
    $output .= "**Tone:** "  . ($world['tone']  ?? 'unspecified') . "\n\n---\n\n";
    $relByEnt = [];
    foreach ($relationships as $r) {
        $relByEnt[(int)$r['from_entity_id']][] = $r;
        if ($r['bidirectional']) $relByEnt[(int)$r['to_entity_id']][] = $r;
    }
    $noteByEnt = [];
    foreach ($notes as $n) { $noteByEnt[(int)($n['entity_id'] ?? 0)][] = $n; }

    foreach ($entities as $e) {
        $eid  = (int) $e['id'];
        $tags = implode(', ', $e['tags'] ?? []);
        $output .= "## {$e['name']}\n\n```yaml\ntype: {$e['type']}\nstatus: {$e['status']}\n";
        if ($tags) $output .= "tags: [{$tags}]\n";
        $output .= "```\n\n";
        if ($e['lore_body']) $output .= $e['lore_body'] . "\n\n";
        if (!empty($e['attributes'])) {
            $output .= "### Attributes\n\n";
            foreach ($e['attributes'] as $a) $output .= "- **{$a['attr_key']}:** {$a['attr_value']}\n";
            $output .= "\n";
        }
        foreach ($noteByEnt[$eid] ?? [] as $n) {
            $output .= "> " . str_replace("\n", "\n> ", $n['content']) . "\n\n";
        }
        $output .= "---\n\n";
    }
}

if ($outPath) {
    file_put_contents($outPath, $output);
    echo "Exported world '{$world['name']}' to {$outPath}\n";
} else {
    echo $output;
}
