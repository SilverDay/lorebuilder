<?php
/**
 * LoreBuilder — HTTP Router
 *
 * Method + path dispatcher with:
 * - Named path parameters:  /worlds/:wid/entities/:id
 * - Per-route middleware flags: auth, csrf
 * - Global exception handling → structured JSON error responses
 * - SPA fallback: unmatched non-API paths serve public/index.html
 *
 * Usage (in index.php):
 *   Router::get('/api/v1/auth/me', [AuthController::class, 'me']);
 *   Router::post('/api/v1/auth/login', [AuthController::class, 'login'], csrf: false);
 *   Router::post('/api/v1/worlds/:wid/entities', [EntityController::class, 'create']);
 *   Router::dispatch();
 *
 * Middleware applied per request (controlled by $auth and $csrf flags):
 *   $auth = true  → calls Auth::requireSession() before handler
 *   $csrf = true  → calls Auth::verifyCsrf() before handler (only if $auth is also true)
 *
 * The CSRF check is only meaningful for authenticated state-changing requests.
 * Login and register set $auth=false (and implicitly $csrf=false) because there
 * is no session yet to hold the token.
 *
 * Handler signature:
 *   function(array $params): void
 *   Where $params contains named path captures + 'user' (session user, if auth=true)
 *
 * Error response format (per CLAUDE.md §9):
 *   { "error": "Human message", "code": "MACHINE_CODE", "field": "optional" }
 *
 * Dependencies: Auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, keys: string[], handler: callable, auth: bool, csrf: bool}> */
    private static array $routes = [];

    // ─── Route Registration ───────────────────────────────────────────────────

    public static function get(string $pattern, callable $handler, bool $auth = true, bool $csrf = false): void
    {
        self::add('GET', $pattern, $handler, $auth, $csrf);
    }

    public static function post(string $pattern, callable $handler, bool $auth = true, bool $csrf = true): void
    {
        self::add('POST', $pattern, $handler, $auth, $csrf);
    }

    public static function patch(string $pattern, callable $handler, bool $auth = true, bool $csrf = true): void
    {
        self::add('PATCH', $pattern, $handler, $auth, $csrf);
    }

    public static function put(string $pattern, callable $handler, bool $auth = true, bool $csrf = true): void
    {
        self::add('PUT', $pattern, $handler, $auth, $csrf);
    }

    public static function delete(string $pattern, callable $handler, bool $auth = true, bool $csrf = true): void
    {
        self::add('DELETE', $pattern, $handler, $auth, $csrf);
    }

    // ─── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Match the current request against registered routes and invoke the handler.
     * Must be called once, after all routes are registered.
     */
    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri    = '/' . trim((string) $uri, '/');

        // Handle preflight OPTIONS for CORS (not used in same-origin SPA, but
        // avoids mystery 404s if browser sends one during development)
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Try to match a registered route
        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $uri, $matches)) {
                continue;
            }

            // Extract named captures
            $params = [];
            foreach ($route['keys'] as $key) {
                $params[$key] = $matches[$key] ?? null;
            }

            self::invokeHandler($route, $params);
            return;
        }

        // No API route matched
        if (str_starts_with($uri, '/api/')) {
            self::jsonError(404, 'NOT_FOUND', 'The requested endpoint does not exist.');
            return;
        }

        // SPA fallback — serve the Vue app for any non-API path
        self::serveSpa();
    }

    // ─── Middleware + Invocation ───────────────────────────────────────────────

    /**
     * @param array{method: string, pattern: string, regex: string, keys: string[], handler: callable, auth: bool, csrf: bool} $route
     * @param array<string, mixed> $params
     */
    private static function invokeHandler(array $route, array $params): void
    {
        try {
            if ($route['auth']) {
                $user = Auth::requireSession();
                $params['user'] = $user;

                if ($route['csrf']) {
                    Auth::verifyCsrf();
                }
            }

            ($route['handler'])($params);

        } catch (AuthException $e) {
            $body = ['error' => $e->getMessage(), 'code' => $e->errorCode];

            // Attach optional extra fields set by Rate limiter / Validator
            if (isset($e->retryAfter)) {
                $body['retry_after'] = (int) ceil($e->retryAfter);
                header('Retry-After: ' . (int) ceil($e->retryAfter));
            }
            if (isset($e->field)) {
                $body['field'] = $e->field;
            }

            http_response_code($e->getCode());
            echo json_encode($body, JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            self::handleUnexpected($e);
        }
    }

    // ─── Route Registration Helper ────────────────────────────────────────────

    private static function add(
        string   $method,
        string   $pattern,
        callable $handler,
        bool     $auth,
        bool     $csrf
    ): void {
        [$regex, $keys] = self::compilePattern($pattern);

        self::$routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => $regex,
            'keys'    => $keys,
            'handler' => $handler,
            'auth'    => $auth,
            'csrf'    => $csrf,
        ];
    }

    /**
     * Convert a pattern like /worlds/:wid/entities/:id into a named-capture regex.
     *
     * @return array{string, string[]}  [regex, list of capture key names]
     */
    private static function compilePattern(string $pattern): array
    {
        $keys  = [];
        $regex = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );

        return ['#^' . $regex . '$#', $keys];
    }

    // ─── Error Helpers ────────────────────────────────────────────────────────

    /**
     * Emit a JSON error response and exit.
     */
    public static function jsonError(int $status, string $code, string $message): void
    {
        http_response_code($status);
        echo json_encode(
            ['error' => $message, 'code' => $code],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Emit a JSON success response and exit.
     *
     * @param mixed                     $data
     * @param array<string, mixed>|null $meta  Pagination etc.
     */
    public static function json(mixed $data, int $status = 200, ?array $meta = null): void
    {
        http_response_code($status);
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle an unexpected Throwable — log internally, return a safe 500.
     */
    private static function handleUnexpected(\Throwable $e): void
    {
        // Log internally (file, not response)
        $stamp   = date('Y-m-d H:i:s');
        $message = "[{$stamp}] UNHANDLED " . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;

        if (defined('LOG_PATH') && LOG_PATH !== '' && is_dir(dirname(LOG_PATH))) {
            file_put_contents(LOG_PATH, $message, FILE_APPEND | LOCK_EX);
        } else {
            error_log($message);
        }

        // Never expose internal details to the client
        http_response_code(500);
        echo json_encode(
            ['error' => 'An internal error occurred.', 'code' => 'INTERNAL_ERROR'],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Serve the compiled Vue SPA entry point for client-side routing.
     */
    private static function serveSpa(): void
    {
        $index = __DIR__ . '/../public/index.html';

        if (!file_exists($index)) {
            // SPA not yet built — helpful message in development
            http_response_code(503);
            header('Content-Type: text/plain');
            echo "Frontend not built yet. Run: cd frontend && npm run build\n";
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
    }
}
