#!/usr/bin/env php
<?php
/**
 * LoreBuilder — Database Migration Runner
 * Usage: php scripts/migrate.php [--dry-run] [--status]
 *
 * --dry-run   Show pending migrations without applying them
 * --status    Show all migrations and their applied state
 */

declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    die("Error: config/config.php not found. Copy config/config.example.php and fill in your values.\n");
}
require $configPath;

$dryRun = in_array('--dry-run', $argv, true);
$status = in_array('--status', $argv, true);

// ─── DB Connection ────────────────────────────────────────────────────────────

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

// ─── Ensure tracking table exists ─────────────────────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(255)    NOT NULL UNIQUE,
    applied_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// ─── Load applied migrations ───────────────────────────────────────────────────

$applied = $pdo->query("SELECT filename FROM schema_migrations ORDER BY filename")
               ->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// ─── Load migration files ─────────────────────────────────────────────────────

$migrationDir = __DIR__ . '/../migrations';
$files = glob($migrationDir . '/*.sql');
sort($files);

if (empty($files)) {
    die("No migration files found in migrations/\n");
}

// ─── Status mode ──────────────────────────────────────────────────────────────

if ($status) {
    echo "\nMigration Status\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($files as $file) {
        $name = basename($file);
        $state = isset($applied[$name]) ? '✓ applied' : '○ pending';
        printf("  %s  %s\n", $state, $name);
    }
    echo str_repeat('─', 60) . "\n\n";
    exit(0);
}

// ─── Find pending migrations ───────────────────────────────────────────────────

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!isset($applied[$name])) {
        $pending[] = $file;
    }
}

if (empty($pending)) {
    echo "Database is up to date. No pending migrations.\n";
    exit(0);
}

echo "\nPending migrations: " . count($pending) . "\n";
echo str_repeat('─', 60) . "\n";

foreach ($pending as $file) {
    $name = basename($file);
    echo "  → $name\n";
}
echo str_repeat('─', 60) . "\n";

if ($dryRun) {
    echo "Dry run — no changes applied.\n\n";
    exit(0);
}

// ─── Apply pending migrations ─────────────────────────────────────────────────

$applied_count = 0;
foreach ($pending as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);

    echo "\nApplying: $name ... ";

    try {
        $pdo->exec($sql);
        // Record as applied if the migration didn't already insert itself
        $check = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE filename = ?");
        $check->execute([$name]);
        if ((int) $check->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")->execute([$name]);
        }
        echo "OK\n";
        $applied_count++;
    } catch (PDOException $e) {
        echo "FAILED\n";
        echo "\nError in $name:\n  " . $e->getMessage() . "\n";
        echo "\nMigration halted. Fix the error and re-run.\n\n";
        exit(1);
    }
}

echo str_repeat('─', 60) . "\n";
echo "Applied $applied_count migration(s) successfully.\n\n";
