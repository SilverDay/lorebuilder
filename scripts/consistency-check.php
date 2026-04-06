#!/usr/bin/env php
<?php
/**
 * LoreBuilder CLI — AI Consistency Check
 *
 * Runs a consistency check for a world using Claude and writes the findings
 * to storage/logs/consistency-<world-slug>-<date>.md
 *
 * Usage:
 *   php scripts/consistency-check.php --world=<id|slug> [--output=<path>]
 *
 * Requires: AI key configured for the world (user or platform mode).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Crypto.php';
require_once __DIR__ . '/../core/AiProvider.php';
require_once __DIR__ . '/../core/providers/AnthropicProvider.php';
require_once __DIR__ . '/../core/providers/OpenAiProvider.php';
require_once __DIR__ . '/../core/providers/GeminiProvider.php';
require_once __DIR__ . '/../core/AiEngine.php';

$opts = getopt('', ['world:', 'output::']);

if (empty($opts['world'])) {
    fwrite(STDERR, "Usage: php consistency-check.php --world=<id|slug> [--output=<path>]\n");
    exit(1);
}

$worldRef = $opts['world'];

$world = is_numeric($worldRef)
    ? DB::queryOne('SELECT * FROM worlds WHERE id = :v AND deleted_at IS NULL', ['v' => (int) $worldRef])
    : DB::queryOne('SELECT * FROM worlds WHERE slug = :v AND deleted_at IS NULL', ['v' => $worldRef]);

if (!$world) {
    fwrite(STDERR, "World '{$worldRef}' not found.\n");
    exit(1);
}

$wid = (int) $world['id'];
$providerId = $world['ai_provider'] ?? 'anthropic';

echo "Running consistency check for world '{$world['name']}' (provider: {$providerId})…\n";

try {
    $apiKey  = AiEngine::resolveApiKey($wid, $providerId);
    $context = AiEngine::buildContext(0, $wid, 'consistency_check');
    $prompt  = 'Analyse this world for narrative inconsistencies, contradictions, '
             . 'unresolved plot threads, and logical gaps. Provide a structured report '
             . 'with severity ratings (Critical / High / Medium / Low) for each issue.';
    $result  = AiEngine::callApi($context, $prompt, $apiKey, $world['ai_model'], 4096, $providerId);
} catch (AiEngineException $e) {
    fwrite(STDERR, "AI error: {$e->getMessage()}\n");
    exit(1);
}

$date     = date('Y-m-d');
$slug     = preg_replace('/[^a-z0-9-]/', '-', strtolower($world['slug']));
$outPath  = $opts['output'] ?? (__DIR__ . "/../storage/logs/consistency-{$slug}-{$date}.md");

$report  = "# Consistency Check — {$world['name']}\n";
$report .= "**Date:** {$date}  \n";
$report .= "**Model:** {$result['model']}  \n";
$report .= "**Tokens:** {$result['total_tokens']}  \n\n---\n\n";
$report .= $result['text'];

// Ensure log directory exists
$logDir = dirname($outPath);
if (!is_dir($logDir)) {
    mkdir($logDir, 0750, true);
}

file_put_contents($outPath, $report);

echo "Report written to {$outPath}\n";
echo "Tokens used: {$result['total_tokens']}\n";
