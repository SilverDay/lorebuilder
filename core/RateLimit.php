<?php
/**
 * LoreBuilder — Token-Bucket Rate Limiter
 *
 * Backed by the `rate_limit_buckets` table. Each bucket is identified by a
 * string key (e.g. 'ai:user:42', 'login:ip:1.2.3.x', 'login:user:alice').
 *
 * Algorithm — continuous token bucket:
 *   On each check:
 *     1. Lock the bucket row (SELECT … FOR UPDATE inside a transaction)
 *     2. Refill: tokens += (elapsed_seconds / window_seconds) * limit — capped at limit
 *     3. If tokens >= 1.0 → consume one token, allow the request
 *     4. If tokens < 1.0 → deny with 429; include retry_after (seconds until next token)
 *
 * The SELECT FOR UPDATE prevents race conditions under concurrent requests —
 * two requests hitting the same bucket at the same instant cannot both consume
 * the same token.
 *
 * Usage:
 *   // AI endpoint — 20 requests per user per hour
 *   RateLimit::check("ai:user:{$userId}", limit: 20, windowSec: 3600);
 *
 *   // Login — 10 per username per 15 min AND 30 per IP per 15 min
 *   RateLimit::checkLogin($username, $clientIp);
 *
 * Dependencies: DB.php, Auth.php (for AuthException)
 */

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

class RateLimit
{
    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Check a named rate-limit bucket. Throws on violation; returns on pass.
     *
     * @param  string $key       Bucket identifier, e.g. 'ai:user:42'
     * @param  int    $limit     Maximum requests allowed per window
     * @param  int    $windowSec Window size in seconds
     * @throws AuthException     HTTP 429 + RATE_LIMITED + retry_after on violation
     */
    public static function check(string $key, int $limit, int $windowSec): void
    {
        $retryAfter = self::consume($key, $limit, $windowSec);

        if ($retryAfter !== null) {
            $ex = new AuthException(
                'Rate limit exceeded. Please try again later.',
                'RATE_LIMITED',
                429
            );
            // Attach retry_after so Router can emit it in the response header / body
            /** @noinspection PhpDynamicFieldDeclarationInspection */
            $ex->retryAfter = $retryAfter;
            throw $ex;
        }
    }

    /**
     * Dual-key login rate limit.
     * Checks both the per-username bucket AND the per-IP bucket.
     * Either bucket can trigger a lockout.
     *
     * Limits are sourced from config constants (defined in config.php):
     *   RATE_LOGIN_LIMIT     — per-username, per RATE_LOGIN_WINDOW seconds
     *   RATE_LOGIN_IP_LIMIT  — per-IP, per RATE_LOGIN_WINDOW seconds
     *
     * @param  string $username  Username or email being attempted
     * @param  string $ip        Client IP address
     * @throws AuthException
     */
    public static function checkLogin(string $username, string $ip): void
    {
        $window      = 900;   // 15 minutes
        $userLimit   = defined('RATE_LOGIN_LIMIT')    ? (int) RATE_LOGIN_LIMIT    : 10;
        $ipLimit     = defined('RATE_LOGIN_IP_LIMIT') ? (int) RATE_LOGIN_IP_LIMIT : 30;

        // Normalise keys — hash username to avoid storing PII in the bucket key
        $userKey = 'login:user:' . hash('sha256', strtolower(trim($username)));
        $ipKey   = 'login:ip:'   . self::anonymiseIp($ip);

        $retryUser = self::consume($userKey, $userLimit, $window);
        $retryIp   = self::consume($ipKey,   $ipLimit,   $window);

        $retryAfter = max($retryUser ?? 0, $retryIp ?? 0);

        if ($retryUser !== null || $retryIp !== null) {
            $ex = new AuthException(
                'Too many login attempts. Please try again later.',
                'RATE_LIMITED',
                429
            );
            /** @noinspection PhpDynamicFieldDeclarationInspection */
            $ex->retryAfter = (int) ceil($retryAfter);
            throw $ex;
        }
    }

    /**
     * Check the per-IP registration rate limit.
     *
     * @param  string $ip
     * @throws AuthException
     */
    public static function checkRegistration(string $ip): void
    {
        $limit  = defined('RATE_REGISTER_LIMIT') ? (int) RATE_REGISTER_LIMIT : 5;
        $window = 3600;  // 1 hour
        self::check('register:ip:' . self::anonymiseIp($ip), $limit, $window);
    }

    // ─── Core Token-Bucket Logic ──────────────────────────────────────────────

    /**
     * Attempt to consume one token from the bucket.
     *
     * Returns null on success (token consumed).
     * Returns the number of seconds to wait (float) if the bucket is empty.
     *
     * All DB operations run inside a transaction with SELECT FOR UPDATE to
     * serialise concurrent access to the same bucket key.
     *
     * @return float|null  null = allowed; float = seconds until next available token
     */
    private static function consume(string $key, int $limit, int $windowSec): ?float
    {
        return DB::transaction(function () use ($key, $limit, $windowSec): ?float {
            $now = microtime(true);

            // Lock existing row, or note absence.
            // UNIX_TIMESTAMP() converts the stored DATETIME to a UTC epoch number
            // using MariaDB's own timezone context — avoids PHP/MariaDB TZ mismatch
            // when comparing against microtime(true) which is always UTC-based.
            $row = DB::queryOne(
                'SELECT id, tokens, UNIX_TIMESTAMP(last_refill) AS last_refill_ts
                   FROM rate_limit_buckets
                  WHERE bucket_key = :key
                  FOR UPDATE',
                ['key' => $key]
            );

            if ($row === null) {
                // First request for this bucket — start full minus one consumed token
                DB::execute(
                    'INSERT INTO rate_limit_buckets (bucket_key, tokens, last_refill)
                     VALUES (:key, :tokens, NOW())
                     ON DUPLICATE KEY UPDATE id = id',   // guard against race on INSERT
                    ['key' => $key, 'tokens' => $limit - 1]
                );
                return null;  // allowed
            }

            // Refill: add tokens proportional to elapsed time
            $lastRefill  = (float) $row['last_refill_ts'];
            $elapsed     = $now - $lastRefill;
            $refillRate  = $limit / $windowSec;           // tokens per second
            $newTokens   = min(
                (float) $limit,
                (float) $row['tokens'] + $elapsed * $refillRate
            );

            if ($newTokens >= 1.0) {
                // Consume one token
                DB::execute(
                    'UPDATE rate_limit_buckets
                        SET tokens = :tokens, last_refill = NOW()
                      WHERE id = :id',
                    ['tokens' => $newTokens - 1.0, 'id' => (int) $row['id']]
                );
                return null;  // allowed
            }

            // Bucket empty — update refill timestamp but don't consume
            DB::execute(
                'UPDATE rate_limit_buckets
                    SET tokens = :tokens, last_refill = NOW()
                  WHERE id = :id',
                ['tokens' => $newTokens, 'id' => (int) $row['id']]
            );

            // Calculate how many seconds until 1 token is available
            $waitSeconds = (1.0 - $newTokens) / $refillRate;
            return $waitSeconds;
        });
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    /**
     * Anonymise an IP address for use as a bucket key.
     * IPv4: zero the last octet  (1.2.3.4   → 1.2.3.x)
     * IPv6: zero the last 64 bits (2001:db8::1 → 2001:db8::)
     * This prevents rate-limit bucket keys from containing PII.
     */
    private static function anonymiseIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 0, strrpos($ip, '.') + 1) . 'x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed   = inet_pton($ip);
            $zeroed   = substr($packed, 0, 8) . str_repeat("\x00", 8);
            return inet_ntop($zeroed) . '/64';
        }

        // Unrecognised format — hash it so it can still be keyed
        return 'hash:' . hash('sha256', $ip);
    }
}
