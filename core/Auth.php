<?php
/**
 * LoreBuilder — Authentication & Session Management
 *
 * Responsibilities:
 * - PHP session lifecycle (start, configure, regenerate, destroy)
 * - CSRF double-submit token (stored in session, sent as X-CSRF-Token header)
 * - Password hashing / verification (bcrypt cost 12)
 * - Login, logout, requireSession()
 * - Secure token generation (email verification, password reset, remember-me)
 * - TOTP (RFC 6238) verify + secret generation
 *   Note: TOTP secrets must be decrypted by the caller (Crypto::decryptApiKey)
 *   before passing to verifyTotp(); Auth.php does not decrypt anything.
 *
 * Dependency: Crypto.php is NOT required here. TOTP callers are responsible for
 * decrypting the stored secret before passing it to Auth::verifyTotp().
 *
 * Session structure:
 *   $_SESSION['user']  = ['id' => int, 'username' => string, 'email' => string,
 *                         'display_name' => string, 'is_platform_admin' => bool,
 *                         'totp_enabled' => bool, 'totp_verified' => bool]
 *   $_SESSION['csrf']  = string (64 hex chars)
 *   $_SESSION['_ip']   = string (IP at login time; checked on each request)
 *   $_SESSION['_ua']   = string (User-Agent hash at login time)
 */

declare(strict_types=1);

class AuthException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        int $httpStatus = 401,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}

class Auth
{
    // Roles in ascending privilege order (used for role comparison)
    private const ROLE_ORDER = ['viewer', 'reviewer', 'author', 'admin', 'owner'];

    // TOTP window tolerance (number of 30-second steps before/after current)
    private const TOTP_WINDOW = 1;

    // ─── Session Lifecycle ────────────────────────────────────────────────────

    /**
     * Configure and start the PHP session.
     * Must be called once at bootstrap (index.php) before any output.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure   = !defined('APP_DEBUG') || !APP_DEBUG;  // Secure flag only outside debug mode
        $lifetime = defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 28800;

        session_set_cookie_params([
            'lifetime' => 0,           // Session cookie (expires on browser close)
            'path'     => '/',
            'domain'   => '',          // Current domain only
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('lb_session');
        session_start();

        // Enforce idle timeout server-side
        if (isset($_SESSION['_last_active'])) {
            if (time() - (int) $_SESSION['_last_active'] > $lifetime) {
                self::destroy();
                return;
            }
        }

        $_SESSION['_last_active'] = time();

        // Bind session to IP + UA to detect session theft
        if (isset($_SESSION['user'])) {
            $currentIp = self::clientIp();
            $currentUa = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

            if (
                (isset($_SESSION['_ip']) && $_SESSION['_ip'] !== $currentIp) ||
                (isset($_SESSION['_ua']) && $_SESSION['_ua'] !== $currentUa)
            ) {
                self::destroy();
            }
        }
    }

    /**
     * Create a session for the given user row (from the users table).
     * Regenerates session ID to prevent session fixation.
     *
     * @param array<string, mixed> $user   Row from users table
     * @param bool                 $remember  Not yet implemented (requires migration)
     */
    public static function login(array $user, bool $remember = false): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'                => (int) $user['id'],
            'username'          => $user['username'],
            'email'             => $user['email'],
            'display_name'      => $user['display_name'],
            'is_platform_admin' => (bool) $user['is_platform_admin'],
            'totp_enabled'      => (bool) $user['totp_enabled'],
            'totp_verified'     => false,   // must be flipped after TOTP code confirmed
        ];

        $_SESSION['_ip']          = self::clientIp();
        $_SESSION['_ua']          = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['_last_active'] = time();

        // Rotate CSRF token on login
        unset($_SESSION['csrf']);
        self::csrfToken();

        // TODO: remember-me requires a remember_tokens table (future migration).
        // When implemented: generate 32-byte token, store hash in DB, set
        // a separate HttpOnly cookie with SESSION_REMEMBER_DAYS lifetime.
    }

    /**
     * Destroy the current session completely.
     */
    public static function logout(): void
    {
        self::destroy();
    }

    /**
     * Return the current authenticated user array, or null if not logged in.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Assert that a valid session exists. Throws AuthException (401) if not.
     * For TOTP-enabled accounts, also checks that TOTP was verified this session.
     *
     * @return array<string, mixed>  The session user array
     * @throws AuthException
     */
    public static function requireSession(): array
    {
        $user = self::user();

        if ($user === null) {
            throw new AuthException(
                'Authentication required.',
                'AUTH_REQUIRED',
                401
            );
        }

        if ($user['totp_enabled'] && !$user['totp_verified']) {
            throw new AuthException(
                'Two-factor authentication required.',
                'AUTH_TOTP_REQUIRED',
                401
            );
        }

        return $user;
    }

    /**
     * Mark TOTP as verified for the current session.
     * Call this after a successful Auth::verifyTotp() check at login.
     */
    public static function markTotpVerified(): void
    {
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['totp_verified'] = true;
        }
    }

    // ─── CSRF Protection ──────────────────────────────────────────────────────

    /**
     * Return the session's CSRF token, generating one if it doesn't exist yet.
     * The frontend must send this as the X-CSRF-Token header on all
     * state-changing requests (POST, PATCH, PUT, DELETE).
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /**
     * Validate the X-CSRF-Token header against the session token.
     * Throws AuthException (403) on mismatch.
     *
     * Call this on every state-changing endpoint before touching data.
     *
     * @throws AuthException
     */
    public static function verifyCsrf(): void
    {
        $expected = $_SESSION['csrf'] ?? null;
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (
            $expected === null ||
            $received === null ||
            !hash_equals($expected, $received)
        ) {
            throw new AuthException(
                'Invalid or missing CSRF token.',
                'AUTH_INVALID',
                403
            );
        }
    }

    // ─── Password Hashing ─────────────────────────────────────────────────────

    /**
     * Hash a plaintext password with bcrypt at cost 12.
     * Never store or log $password after calling this.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify a plaintext password against a stored bcrypt hash.
     * Uses timing-safe comparison internally via password_verify().
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Returns true if the stored hash should be rehashed (e.g. cost was raised).
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ─── Secure Token Generation ──────────────────────────────────────────────

    /**
     * Generate a cryptographically secure random hex token.
     * Default 32 bytes = 64 hex chars. Suitable for email verification,
     * password reset links, and remember-me tokens.
     */
    public static function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    // ─── TOTP (RFC 6238) ──────────────────────────────────────────────────────

    /**
     * Generate a random TOTP secret as a base32-encoded string.
     * Store this encrypted (via Crypto::encryptApiKey) in users.totp_secret_enc.
     * Length: 20 bytes = 160 bits, encoded to 32 base32 chars.
     */
    public static function generateTotpSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Build an otpauth:// URI for QR code generation.
     *
     * @param string $plainSecret  Plaintext base32 TOTP secret (before encryption)
     * @param string $username     User's username or email (shown in authenticator app)
     */
    public static function totpUri(string $plainSecret, string $username): string
    {
        $issuer  = 'LoreBuilder';
        $account = rawurlencode($username);
        $label   = rawurlencode("{$issuer}:{$username}");
        return "otpauth://totp/{$label}?secret={$plainSecret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Verify a 6-digit TOTP code against a plaintext base32 secret.
     * Checks the current 30-second window ±TOTP_WINDOW steps for clock drift.
     *
     * The caller MUST decrypt the stored encrypted secret before passing it here.
     * Example:
     *   $plain = Crypto::decryptApiKey($user['totp_secret_enc'], APP_SECRET);
     *   if (Auth::verifyTotp($plain, $code)) { ... }
     *
     * @param string $plainSecret  Plaintext base32 TOTP secret
     * @param string $code         6-digit code from authenticator app
     */
    public static function verifyTotp(string $plainSecret, string $code): bool
    {
        // Normalise: strip spaces, ensure 6 digits
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $keyBytes = self::base32Decode($plainSecret);
        if ($keyBytes === false) {
            return false;
        }

        $now = (int) floor(time() / 30);

        for ($step = -self::TOTP_WINDOW; $step <= self::TOTP_WINDOW; $step++) {
            if (self::totpCode($keyBytes, $now + $step) === $code) {
                return true;
            }
        }

        return false;
    }

    // ─── Private TOTP Helpers ─────────────────────────────────────────────────

    /**
     * Compute the 6-digit TOTP code for a given key and time counter.
     *
     * @param  string $keyBytes  Raw binary key (decoded from base32)
     * @param  int    $counter   Unix time / 30 (optionally offset by window)
     */
    private static function totpCode(string $keyBytes, int $counter): string
    {
        // RFC 6238: counter as 8-byte big-endian
        $msg  = pack('N', 0) . pack('N', $counter);
        $hmac = hash_hmac('sha1', $msg, $keyBytes, true);

        // Dynamic truncation (RFC 4226 §5.3)
        $offset = ord($hmac[19]) & 0x0F;
        $otp    =
            ((ord($hmac[$offset])     & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) <<  8) |
            ((ord($hmac[$offset + 3]) & 0xFF));

        return str_pad((string) ($otp % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    // ─── Base32 Encoding / Decoding ───────────────────────────────────────────

    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Encode(string $bytes): string
    {
        $output  = '';
        $len     = strlen($bytes);
        $bits    = 0;
        $acc     = 0;

        for ($i = 0; $i < $len; $i++) {
            $acc  = ($acc << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits  -= 5;
                $output .= self::BASE32_CHARS[($acc >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $output .= self::BASE32_CHARS[($acc << (5 - $bits)) & 0x1F];
        }

        return $output;
    }

    /**
     * @return string|false  Raw binary string, or false on invalid input
     */
    private static function base32Decode(string $input): string|false
    {
        $input  = strtoupper(trim($input));
        $map    = array_flip(str_split(self::BASE32_CHARS));
        $output = '';
        $bits   = 0;
        $acc    = 0;

        foreach (str_split($input) as $char) {
            if ($char === '=') {
                break;
            }
            if (!isset($map[$char])) {
                return false;
            }
            $acc   = ($acc << 5) | $map[$char];
            $bits += 5;
            if ($bits >= 8) {
                $bits  -= 8;
                $output .= chr(($acc >> $bits) & 0xFF);
            }
        }

        return $output;
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    /**
     * Return the client IP address.
     * Does NOT trust X-Forwarded-For unless you configure a trusted proxy.
     * Adjust for your deployment if behind a load balancer.
     */
    private static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Destroy the session cleanly: unset data, invalidate cookie, delete server session.
     */
    private static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
