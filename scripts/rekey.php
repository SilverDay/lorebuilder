#!/usr/bin/env php
<?php
/**
 * LoreBuilder CLI — API Key Re-encryption
 *
 * Re-encrypts all ai_key_enc values in the worlds table after APP_SECRET rotation.
 *
 * Usage:
 *   php scripts/rekey.php --old-secret=<hex> [--dry-run]
 *
 * The current APP_SECRET in config.php is the NEW secret.
 * Provide the OLD secret via --old-secret to decrypt existing values,
 * then re-encrypt with the new one.
 *
 * IMPORTANT: Run this immediately after rotating APP_SECRET in config.php.
 * Any world with ai_key_enc that is not re-keyed will fail to decrypt its key.
 *
 * Options:
 *   --old-secret=<hex>    Previous APP_SECRET value (hex-encoded)
 *   --dry-run             Report what would be done without writing to DB
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Crypto.php';

$opts   = getopt('', ['old-secret:', 'dry-run']);
$dryRun = isset($opts['dry-run']);

if (empty($opts['old-secret'])) {
    fwrite(STDERR, "Usage: php rekey.php --old-secret=<hex> [--dry-run]\n");
    fwrite(STDERR, "\nThe --old-secret is the APP_SECRET that was used to encrypt the stored keys.\n");
    fwrite(STDERR, "The new APP_SECRET should already be set in config/config.php.\n");
    exit(1);
}

$oldSecret = $opts['old-secret'];

// Validate old secret format
if (!ctype_xdigit($oldSecret) || strlen($oldSecret) < 64) {
    fwrite(STDERR, "Error: --old-secret must be a hex-encoded string of at least 32 bytes (64 hex chars).\n");
    exit(1);
}

if (!defined('APP_SECRET')) {
    fwrite(STDERR, "Error: APP_SECRET is not defined. Check config/config.php.\n");
    exit(1);
}

if ($dryRun) {
    echo "[DRY RUN] No changes will be written to the database.\n\n";
}

$worlds = DB::query(
    'SELECT id, name, slug, ai_key_enc FROM worlds WHERE ai_key_enc IS NOT NULL AND deleted_at IS NULL',
    []
);

if (empty($worlds)) {
    echo "No worlds have encrypted API keys. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($worlds) . " world(s) with encrypted API keys.\n\n";

$success = 0;
$failed  = 0;

foreach ($worlds as $world) {
    $wid  = (int) $world['id'];
    $name = $world['name'];

    try {
        // Decrypt with OLD secret
        $plaintext = Crypto::decryptApiKey($world['ai_key_enc'], $oldSecret);

        // Re-encrypt with NEW secret
        $newEnc         = Crypto::encryptApiKey($plaintext, APP_SECRET);
        $newFingerprint = Crypto::apiKeyFingerprint($plaintext);

        if (!$dryRun) {
            DB::execute(
                'UPDATE worlds SET ai_key_enc = :enc, ai_key_fingerprint = :fp WHERE id = :id',
                ['enc' => $newEnc, 'fp' => $newFingerprint, 'id' => $wid]
            );
        }

        echo "[OK] World #{$wid} '{$name}' — re-keyed (fingerprint: {$newFingerprint})\n";
        $success++;

    } catch (CryptoException $e) {
        fwrite(STDERR, "[FAIL] World #{$wid} '{$name}' — decryption failed: {$e->getMessage()}\n");
        $failed++;
    }
}

echo "\n--- Summary ---\n";
echo "Success: {$success}\n";
echo "Failed:  {$failed}\n";

if ($dryRun && $success > 0) {
    echo "\nRe-run without --dry-run to apply changes.\n";
}

if ($failed > 0) {
    fwrite(STDERR, "\nWARNING: {$failed} world(s) could not be re-keyed. Those worlds will not be able to use AI features until their keys are re-entered.\n");
    exit(1);
}
