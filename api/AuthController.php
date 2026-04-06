<?php
/**
 * LoreBuilder — Authentication Controller
 *
 * Handles all /api/v1/auth/* endpoints.
 * Routes are registered in public/index.php.
 *
 * Security checklist (applied to every endpoint):
 *   [x] Rate limiting where relevant
 *   [x] Input validated via Validator::parse*()
 *   [x] PDO prepared statements (via DB::*)
 *   [x] Passwords hashed with bcrypt cost 12
 *   [x] CSRF enforced on session-mutating endpoints (via Router middleware)
 *   [x] No stack traces in responses
 *   [x] Sensitive fields (password, tokens) never returned
 *   [x] Audit log written for login, logout, register, password reset
 */

declare(strict_types=1);

class AuthController
{
    // Minimum password length (also enforced client-side)
    private const MIN_PASSWORD_LEN = 12;

    // ─── POST /api/v1/auth/register ───────────────────────────────────────────

    public static function register(array $p): void
    {
        if (!defined('REGISTRATION_OPEN') || !REGISTRATION_OPEN) {
            Router::jsonError(403, 'FORBIDDEN', 'Registration is currently invite-only.');
            return;
        }

        RateLimit::checkRegistration($_SERVER['REMOTE_ADDR'] ?? '');

        $data = Validator::parseJson([
            'username'     => 'required|string|min:3|max:64',
            'email'        => 'required|email',
            'display_name' => 'required|string|min:1|max:128',
            'password'     => 'required|string|min:' . self::MIN_PASSWORD_LEN . '|max:1024',
        ]);

        // Uniqueness checks
        if (DB::queryOne('SELECT id FROM users WHERE username = :u AND deleted_at IS NULL', ['u' => $data['username']])) {
            Router::jsonError(409, 'CONFLICT', 'That username is already taken.');
            return;
        }
        if (DB::queryOne('SELECT id FROM users WHERE email = :e AND deleted_at IS NULL', ['e' => $data['email']])) {
            Router::jsonError(409, 'CONFLICT', 'An account with that email already exists.');
            return;
        }

        $verifyToken = Auth::generateToken(32);
        $needsVerify = defined('REQUIRE_EMAIL_VERIFICATION') && REQUIRE_EMAIL_VERIFICATION;

        $userId = DB::execute(
            'INSERT INTO users
                (username, email, display_name, password_hash, email_verified, email_verify_token)
             VALUES
                (:username, :email, :display_name, :password_hash, :verified, :token)',
            [
                'username'      => $data['username'],
                'email'         => $data['email'],
                'display_name'  => $data['display_name'],
                'password_hash' => Auth::hashPassword($data['password']),
                'verified'      => $needsVerify ? 0 : 1,
                'token'         => $needsVerify ? $verifyToken : null,
            ]
        );

        self::audit(null, $userId, 'user.register', 'user', $userId);

        if ($needsVerify) {
            self::sendVerificationEmail($data['email'], $data['display_name'], $verifyToken);
        }

        http_response_code(201);
        echo json_encode([
            'data' => [
                'id'                    => $userId,
                'username'              => $data['username'],
                'email_verification'    => $needsVerify ? 'required' : 'not_required',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/login ──────────────────────────────────────────────

    public static function login(array $p): void
    {
        $data = Validator::parseJson([
            'login'    => 'required|string|max:254',   // username or email
            'password' => 'required|string|max:1024',
        ]);

        // Rate-limit before any DB work to prevent enumeration timing attacks
        RateLimit::checkLogin($data['login'], $_SERVER['REMOTE_ADDR'] ?? '');

        // Lookup by username OR email.
        // PDO with ATTR_EMULATE_PREPARES=false requires unique placeholder names,
        // so we bind the same value under two names.
        $user = DB::queryOne(
            'SELECT * FROM users
              WHERE (username = :lu OR email = :le)
                AND deleted_at IS NULL
              LIMIT 1',
            ['lu' => $data['login'], 'le' => $data['login']]
        );

        // Account lockout check (before password verify to prevent timing leaks)
        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $wait = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            Router::jsonError(429, 'RATE_LIMITED', "Account is locked. Try again in {$wait} minute(s).");
            return;
        }

        // Constant-time failure path — always verify even when user not found
        $dummyHash = '$2y$12$invaliddummyhashfortimingequalit';
        $hash      = $user['password_hash'] ?? $dummyHash;
        $valid     = Auth::verifyPassword($data['password'], $hash);

        if (!$user || !$valid || !$user['is_active']) {
            if ($user) {
                self::recordFailedLogin($user);
            }
            Router::jsonError(401, 'AUTH_INVALID', 'Invalid username or password.');
            return;
        }

        if (!$user['email_verified']) {
            Router::jsonError(403, 'AUTH_INVALID', 'Please verify your email address before logging in.');
            return;
        }

        // Reset failed login counter on success
        DB::execute(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL,
                              last_login_at = NOW(), last_login_ip = :ip
              WHERE id = :id',
            ['ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'id' => $user['id']]
        );

        // Rehash if bcrypt cost has been upgraded
        if (Auth::needsRehash($user['password_hash'])) {
            DB::execute(
                'UPDATE users SET password_hash = :h WHERE id = :id',
                ['h' => Auth::hashPassword($data['password']), 'id' => $user['id']]
            );
        }

        Auth::login($user);
        self::audit(null, (int) $user['id'], 'user.login', 'user', (int) $user['id']);

        // TOTP required — return partial state so frontend can prompt for code
        if ($user['totp_enabled']) {
            http_response_code(200);
            echo json_encode(['data' => ['totp_required' => true]], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['data' => self::sessionPayload($user)], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/logout ─────────────────────────────────────────────

    public static function logout(array $p): void
    {
        $user = $p['user'];
        self::audit(null, $user['id'], 'user.logout', 'user', $user['id']);
        Auth::logout();
        echo json_encode(['data' => ['logged_out' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── GET /api/v1/auth/me ──────────────────────────────────────────────────

    public static function me(array $p): void
    {
        $user = DB::queryOne(
            'SELECT id, username, email, display_name, totp_enabled,
                    is_platform_admin, email_verified, created_at
               FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $p['user']['id']]
        );

        if (!$user) {
            Router::jsonError(401, 'AUTH_REQUIRED', 'Session user no longer exists.');
            return;
        }

        echo json_encode(['data' => $user], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/totp/verify ───────────────────────────────────────

    public static function totpVerify(array $p): void
    {
        $data = Validator::parseJson(['code' => 'required|string|max:8']);
        $user = $p['user'];

        RateLimit::check('totp:' . $user['id'], 5, 300);

        if (!$user['totp_enabled']) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'TOTP is not enabled for this account.');
            return;
        }

        $row = DB::queryOne(
            'SELECT totp_secret_enc FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $user['id']]
        );

        if (!$row || !$row['totp_secret_enc']) {
            Router::jsonError(500, 'INTERNAL_ERROR', 'An internal error occurred.');
            return;
        }

        try {
            $plain = Crypto::decrypt($row['totp_secret_enc'], APP_SECRET);
        } catch (CryptoException) {
            Router::jsonError(500, 'INTERNAL_ERROR', 'An internal error occurred.');
            return;
        }

        if (!Auth::verifyTotp($plain, $data['code'])) {
            Router::jsonError(401, 'AUTH_INVALID', 'Invalid two-factor code.');
            return;
        }

        Auth::markTotpVerified();
        echo json_encode(['data' => ['verified' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/totp/setup ────────────────────────────────────────

    public static function totpSetup(array $p): void
    {
        $user = $p['user'];

        if ($user['totp_enabled']) {
            Router::jsonError(409, 'CONFLICT', 'TOTP is already enabled. Disable it first.');
            return;
        }

        $secret    = Auth::generateTotpSecret();
        $encrypted = Crypto::encrypt($secret, APP_SECRET);
        $uri       = Auth::totpUri($secret, $user['username']);

        // Store encrypted secret as pending (totp_enabled still 0 until confirmed)
        DB::execute(
            'UPDATE users SET totp_secret_enc = :enc WHERE id = :id',
            ['enc' => $encrypted, 'id' => $user['id']]
        );

        // Return the URI for QR code rendering; secret itself returned only here, once
        echo json_encode([
            'data' => [
                'uri'    => $uri,
                'secret' => $secret,   // shown once so user can manually enter it
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/totp/confirm ──────────────────────────────────────

    public static function totpConfirm(array $p): void
    {
        $data = Validator::parseJson(['code' => 'required|string|max:8']);
        $user = $p['user'];

        RateLimit::check('totp:' . $user['id'], 5, 300);

        if ($user['totp_enabled']) {
            Router::jsonError(409, 'CONFLICT', 'TOTP is already enabled.');
            return;
        }

        $row = DB::queryOne(
            'SELECT totp_secret_enc FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $user['id']]
        );

        if (!$row || !$row['totp_secret_enc']) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'Run /auth/totp/setup first.');
            return;
        }

        try {
            $plain = Crypto::decrypt($row['totp_secret_enc'], APP_SECRET);
        } catch (CryptoException) {
            Router::jsonError(500, 'INTERNAL_ERROR', 'An internal error occurred.');
            return;
        }

        if (!Auth::verifyTotp($plain, $data['code'])) {
            Router::jsonError(401, 'AUTH_INVALID', 'Invalid code. Please try again.');
            return;
        }

        DB::execute(
            'UPDATE users SET totp_enabled = 1 WHERE id = :id',
            ['id' => $user['id']]
        );

        Auth::markTotpVerified();
        self::audit(null, $user['id'], 'user.totp.enable', 'user', $user['id']);

        echo json_encode(['data' => ['totp_enabled' => true]], JSON_UNESCAPED_UNICODE);
    }

    // ─── DELETE /api/v1/auth/totp ─────────────────────────────────────────────

    public static function totpDisable(array $p): void
    {
        $data = Validator::parseJson([
            'password' => 'required|string|max:1024',
            'code'     => 'required|string|max:8',
        ]);
        $user = $p['user'];

        $row = DB::queryOne(
            'SELECT password_hash, totp_secret_enc, totp_enabled
               FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $user['id']]
        );

        if (!$row || !$row['totp_enabled']) {
            Router::jsonError(400, 'VALIDATION_ERROR', 'TOTP is not currently enabled.');
            return;
        }

        if (!Auth::verifyPassword($data['password'], $row['password_hash'])) {
            Router::jsonError(401, 'AUTH_INVALID', 'Current password is incorrect.');
            return;
        }

        try {
            $plain = Crypto::decrypt($row['totp_secret_enc'], APP_SECRET);
        } catch (CryptoException) {
            Router::jsonError(500, 'INTERNAL_ERROR', 'An internal error occurred.');
            return;
        }

        if (!Auth::verifyTotp($plain, $data['code'])) {
            Router::jsonError(401, 'AUTH_INVALID', 'Invalid two-factor code.');
            return;
        }

        DB::execute(
            'UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL WHERE id = :id',
            ['id' => $user['id']]
        );

        self::audit(null, $user['id'], 'user.totp.disable', 'user', $user['id']);
        echo json_encode(['data' => ['totp_enabled' => false]], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/password/reset-request ─────────────────────────────

    public static function passwordResetRequest(array $p): void
    {
        // Rate-limit using the submitted email as key
        $data = Validator::parseJson(['email' => 'required|email']);

        RateLimit::check('pwreset:' . hash('sha256', $data['email']), 3, 3600);

        $user = DB::queryOne(
            'SELECT id, email, display_name FROM users
              WHERE email = :e AND deleted_at IS NULL AND is_active = 1',
            ['e' => $data['email']]
        );

        // Always respond the same way — don't reveal whether email exists
        if ($user) {
            $token   = Auth::generateToken(32);
            $expires = date('Y-m-d H:i:s', time() + 3600);

            DB::execute(
                'UPDATE users
                    SET password_reset_token = :t, password_reset_expires = :exp
                  WHERE id = :id',
                ['t' => hash('sha256', $token), 'exp' => $expires, 'id' => $user['id']]
            );

            self::sendPasswordResetEmail($user['email'], $user['display_name'], $token);
            self::audit(null, (int) $user['id'], 'user.password.reset_request', 'user', (int) $user['id']);
        }

        echo json_encode([
            'data' => ['message' => 'If that email is registered, a reset link has been sent.'],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ─── POST /api/v1/auth/password/reset ─────────────────────────────────────

    public static function passwordReset(array $p): void
    {
        $data = Validator::parseJson([
            'token'    => 'required|string|max:128',
            'password' => 'required|string|min:' . self::MIN_PASSWORD_LEN . '|max:1024',
        ]);

        $tokenHash = hash('sha256', $data['token']);

        $user = DB::queryOne(
            'SELECT id FROM users
              WHERE password_reset_token = :t
                AND password_reset_expires > NOW()
                AND deleted_at IS NULL
                AND is_active = 1',
            ['t' => $tokenHash]
        );

        if (!$user) {
            Router::jsonError(400, 'AUTH_INVALID', 'This reset link is invalid or has expired.');
            return;
        }

        DB::execute(
            'UPDATE users
                SET password_hash = :h,
                    password_reset_token = NULL,
                    password_reset_expires = NULL,
                    failed_login_count = 0,
                    locked_until = NULL
              WHERE id = :id',
            ['h' => Auth::hashPassword($data['password']), 'id' => $user['id']]
        );

        self::audit(null, (int) $user['id'], 'user.password.reset', 'user', (int) $user['id']);
        echo json_encode(['data' => ['message' => 'Password updated. You can now log in.']], JSON_UNESCAPED_UNICODE);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Increment failed login count; lock account after 10 failures.
     */
    private static function recordFailedLogin(array $user): void
    {
        $newCount = (int) $user['failed_login_count'] + 1;
        $lockUntil = null;

        if ($newCount >= 10) {
            // Exponential backoff: 2^(count-10) minutes, capped at 60 minutes
            $minutes   = min(60, 2 ** max(0, $newCount - 10));
            $lockUntil = date('Y-m-d H:i:s', time() + $minutes * 60);
        }

        DB::execute(
            'UPDATE users SET failed_login_count = :c, locked_until = :l WHERE id = :id',
            ['c' => $newCount, 'l' => $lockUntil, 'id' => $user['id']]
        );
    }

    /**
     * Build the session data payload returned on successful login.
     *
     * @param  array<string, mixed> $user  users table row
     * @return array<string, mixed>
     */
    // ─── GET /api/v1/auth/csrf ────────────────────────────────────────────────

    /**
     * Return a fresh CSRF token for the current session.
     * Public endpoint (no auth required) — the SPA calls this on first load
     * to obtain the token before making state-changing requests.
     */
    public static function csrf(array $p): void
    {
        http_response_code(200);
        echo json_encode(['data' => ['token' => Auth::csrfToken()]]);
    }

    private static function sessionPayload(array $user): array
    {
        return [
            'id'               => (int) $user['id'],
            'username'         => $user['username'],
            'display_name'     => $user['display_name'],
            'email'            => $user['email'],
            'totp_enabled'     => (bool) $user['totp_enabled'],
            'is_platform_admin'=> (bool) $user['is_platform_admin'],
            'csrf_token'       => Auth::csrfToken(),
        ];
    }

    /**
     * Write an entry to the audit_log table.
     */
    private static function audit(
        ?int $worldId,
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        DB::execute(
            'INSERT INTO audit_log (world_id, user_id, action, target_type, target_id, ip_address, user_agent)
             VALUES (:wid, :uid, :action, :ttype, :tid, :ip, :ua)',
            [
                'wid'    => $worldId,
                'uid'    => $userId,
                'action' => $action,
                'ttype'  => $targetType,
                'tid'    => $targetId,
                'ip'     => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            ]
        );
    }

    /**
     * Send email verification link.
     * Logs failure internally; never throws (email failure must not block registration).
     */
    private static function sendVerificationEmail(string $email, string $name, string $token): void
    {
        $url     = rtrim(APP_URL, '/') . '/verify-email?token=' . urlencode($token);
        $subject = 'Verify your LoreBuilder account';
        $body    = "Hi {$name},\n\nPlease verify your email address:\n{$url}\n\n"
                 . "This link expires in 48 hours.\n\nLoreBuilder";

        self::sendMail($email, $subject, $body);
    }

    /**
     * Send password reset link.
     */
    private static function sendPasswordResetEmail(string $email, string $name, string $token): void
    {
        $url     = rtrim(APP_URL, '/') . '/reset-password?token=' . urlencode($token);
        $subject = 'Reset your LoreBuilder password';
        $body    = "Hi {$name},\n\nClick the link below to reset your password:\n{$url}\n\n"
                 . "This link expires in 1 hour. If you did not request a reset, ignore this email.\n\nLoreBuilder";

        self::sendMail($email, $subject, $body);
    }

    /**
     * Minimal mail dispatcher. Supports 'mail' and 'smtp' drivers from config.
     * For production, replace with a proper mailer (PHPMailer, Symfony Mailer, etc.)
     */
    private static function sendMail(string $to, string $subject, string $body): void
    {
        $from     = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@localhost';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'LoreBuilder';
        $driver   = defined('MAIL_DRIVER')    ? MAIL_DRIVER    : 'mail';
        $headers  = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        if ($driver === 'smtp') {
            // SMTP sending via PHP streams — no external dependency
            self::sendSmtp($to, $subject, $body, $from, $headers);
        } else {
            // Fallback: php mail() — adequate for local/dev environments
            mail($to, $subject, $body, $headers);
        }
    }

    /**
     * Minimal SMTP client using PHP streams.
     * Supports STARTTLS (port 587) and SSL (port 465).
     */
    private static function sendSmtp(
        string $to,
        string $subject,
        string $body,
        string $from,
        string $headers
    ): void {
        $host       = defined('SMTP_HOST')       ? SMTP_HOST       : 'localhost';
        $port       = defined('SMTP_PORT')        ? (int) SMTP_PORT : 587;
        $user       = defined('SMTP_USER')        ? SMTP_USER       : '';
        $pass       = defined('SMTP_PASS')        ? SMTP_PASS       : '';
        $encryption = defined('SMTP_ENCRYPTION')  ? SMTP_ENCRYPTION : 'tls';

        $address = ($encryption === 'ssl' ? 'ssl://' : '') . $host;

        $socket = @fsockopen($address, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("LoreBuilder SMTP: could not connect to {$host}:{$port} — {$errstr}");
            return;
        }

        $read = static fn() => fgets($socket, 512);
        $send = static function (string $cmd) use ($socket): void { fwrite($socket, $cmd . "\r\n"); };

        $read(); // banner
        $send('EHLO ' . (defined('APP_URL') ? parse_url(APP_URL, PHP_URL_HOST) : 'localhost'));

        // Collect EHLO response lines
        $ehlo = '';
        while ($line = $read()) {
            $ehlo .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }

        if ($encryption === 'tls') {
            $send('STARTTLS');
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send('EHLO ' . (defined('APP_URL') ? parse_url(APP_URL, PHP_URL_HOST) : 'localhost'));
            while ($line = $read()) {
                if (substr($line, 3, 1) === ' ') break;
            }
        }

        if ($user !== '') {
            $send('AUTH LOGIN');
            $read();
            $send(base64_encode($user));
            $read();
            $send(base64_encode($pass));
            $read();
        }

        $send('MAIL FROM:<' . $from . '>');
        $read();
        $send('RCPT TO:<' . $to . '>');
        $read();
        $send('DATA');
        $read();
        $send("To: {$to}\r\nSubject: {$subject}\r\n{$headers}\r\n{$body}");
        $send('.');
        $read();
        $send('QUIT');
        fclose($socket);
    }
}
