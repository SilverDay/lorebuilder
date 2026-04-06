<?php
/**
 * LoreBuilder — CLI User Creation Script
 *
 * Usage:
 *   php scripts/create-user.php --username=<name> --email=<email> --password=<pass>
 *                               [--display-name=<name>] [--admin]
 *
 * Options:
 *   --username      Required. Unique login name (3–64 chars, alphanumeric + _ -)
 *   --email         Required. User's email address
 *   --password      Required. Plaintext password (min 12 chars; bcrypt cost 12)
 *   --display-name  Optional. Shown in UI. Defaults to username.
 *   --admin         Optional flag. Grants is_platform_admin = 1.
 *
 * The script marks the account as email_verified = 1 so the user can log in
 * immediately without needing the email verification flow.
 *
 * Run from the project root:
 *   php scripts/create-user.php --username=alice --email=alice@example.com --password=hunter2hunter2
 */

declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$root = dirname(__DIR__);
$cfg  = $root . '/config/config.php';

if (!file_exists($cfg)) {
    fwrite(STDERR, "ERROR: config/config.php not found. Copy config/config.example.php and fill it in.\n");
    exit(1);
}

require_once $cfg;
require_once $root . '/core/DB.php';

// ─── Parse Arguments ──────────────────────────────────────────────────────────

$opts = getopt('', ['username:', 'email:', 'password:', 'display-name:', 'admin']);

$username    = trim($opts['username']    ?? '');
$email       = trim($opts['email']       ?? '');
$password    = $opts['password']         ?? '';
$displayName = trim($opts['display-name'] ?? $username);
$isAdmin     = isset($opts['admin']);

// ─── Validate ─────────────────────────────────────────────────────────────────

$errors = [];

if ($username === '') {
    $errors[] = '--username is required.';
} elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,64}$/', $username)) {
    $errors[] = '--username must be 3–64 chars: letters, digits, _ or -.';
}

if ($email === '') {
    $errors[] = '--email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = '--email is not a valid email address.';
}

if ($password === '') {
    $errors[] = '--password is required.';
} elseif (strlen($password) < 12) {
    $errors[] = '--password must be at least 12 characters.';
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        fwrite(STDERR, "ERROR: {$e}\n");
    }
    fwrite(STDERR, "\nUsage: php scripts/create-user.php --username=<name> --email=<email> --password=<pass> [--display-name=<name>] [--admin]\n");
    exit(1);
}

// ─── Check for Duplicates ─────────────────────────────────────────────────────

$existing = DB::queryOne(
    'SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1',
    ['u' => $username, 'e' => $email]
);

if ($existing !== null) {
    fwrite(STDERR, "ERROR: A user with that username or email already exists (id={$existing['id']}).\n");
    exit(1);
}

// ─── Create User ──────────────────────────────────────────────────────────────

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$id = DB::execute(
    'INSERT INTO users
        (username, email, display_name, password_hash, is_platform_admin, is_active, email_verified)
     VALUES
        (:username, :email, :display_name, :hash, :admin, 1, 1)',
    [
        'username'     => $username,
        'email'        => $email,
        'display_name' => $displayName ?: $username,
        'hash'         => $hash,
        'admin'        => (int) $isAdmin,
    ]
);

// ─── Done ─────────────────────────────────────────────────────────────────────

echo "✓ User created successfully.\n";
echo "  ID:       {$id}\n";
echo "  Username: {$username}\n";
echo "  Email:    {$email}\n";
echo "  Admin:    " . ($isAdmin ? 'yes' : 'no') . "\n";
echo "\nYou can now log in at " . (defined('APP_URL') ? APP_URL : 'your LoreBuilder URL') . "/login\n";
