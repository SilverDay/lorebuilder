<?php
// LoreBuilder — Configuration Template
// Copy to config/config.php and fill in your values.
// config.php is in .gitignore and must NEVER be committed.
// chmod 640 config/config.php after filling in.

// ─── Application ──────────────────────────────────────────────────────────────
define('APP_URL',   'https://lorebuilder.yourdomain.com');  // No trailing slash
define('APP_DEBUG', false);  // NEVER true in production — exposes stack traces

// ─── Encryption Key ───────────────────────────────────────────────────────────
// Generate once: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
// Changing this requires re-encrypting ALL stored API keys (php scripts/rekey.php)
define('APP_SECRET', 'REPLACE_WITH_BASE64_ENCODED_32_BYTE_KEY');

// ─── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    3306);
define('DB_NAME',    'lorebuilder');
define('DB_USER',    'lorebuilder_user');
define('DB_PASS',    'REPLACE_WITH_STRONG_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// ─── Platform AI Keys (Optional) ──────────────────────────────────────────────
// Only needed if you offer a hosted platform-key tier.
// Users with ai_key_mode='platform' will use the relevant provider key.
// Leave empty string to disable platform-key mode for that provider.
define('PLATFORM_ANTHROPIC_KEY', '');
define('PLATFORM_OPENAI_KEY',    '');
define('PLATFORM_GEMINI_KEY',    '');

// ─── Session ──────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME',      28800);   // 8 hours idle timeout (seconds)
define('SESSION_REMEMBER_DAYS', 30);      // "Remember me" token lifetime

// ─── Rate Limits (requests per window) ────────────────────────────────────────
define('RATE_AI_USER_LIMIT',    20);      // per user per hour
define('RATE_AI_WORLD_LIMIT',   100);     // per world per hour
define('RATE_LOGIN_LIMIT',      10);      // per username per 15 min before lockout
define('RATE_LOGIN_IP_LIMIT',   30);      // per IP per 15 min before lockout
define('RATE_REGISTER_LIMIT',   5);       // per IP per hour

// ─── Storage Paths (outside web root) ─────────────────────────────────────────
define('STORAGE_PATH',  '/var/www/lorebuilder/storage');
define('UPLOAD_PATH',   STORAGE_PATH . '/uploads');
define('LOG_PATH',      STORAGE_PATH . '/logs/app.log');
define('AUDIT_LOG_PATH', STORAGE_PATH . '/logs/audit.log');
define('BACKUP_PATH',   STORAGE_PATH . '/backups');

// ─── Mail ─────────────────────────────────────────────────────────────────────
define('MAIL_FROM',         'noreply@yourdomain.com');
define('MAIL_FROM_NAME',    'LoreBuilder');
define('MAIL_DRIVER',       'smtp');      // 'mail' | 'smtp'
define('SMTP_HOST',         'localhost');
define('SMTP_PORT',         587);
define('SMTP_USER',         '');
define('SMTP_PASS',         '');
define('SMTP_ENCRYPTION',   'tls');       // 'tls' | 'ssl' | ''

// ─── Registration ─────────────────────────────────────────────────────────────
define('REGISTRATION_OPEN',         true);   // false = invite-only
define('REQUIRE_EMAIL_VERIFICATION', true);
