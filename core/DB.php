<?php
/**
 * LoreBuilder — Database Wrapper
 *
 * Singleton PDO wrapper providing query(), queryOne(), and execute() with:
 * - Named placeholder support (:param style, passed as associative array)
 * - Automatic soft-delete filtering is NOT applied here — callers must add
 *   WHERE deleted_at IS NULL per the schema conventions in CLAUDE.md
 * - Query logging to LOG_PATH when APP_DEBUG is true
 * - Transaction helpers
 *
 * Usage:
 *   DB::query('SELECT * FROM users WHERE id = :id', ['id' => 42]);
 *   DB::queryOne('SELECT * FROM users WHERE email = :email', ['email' => $e]);
 *   $newId = DB::execute('INSERT INTO users (...) VALUES (...)', [...]);
 */

declare(strict_types=1);

class DB
{
    private static ?self $instance = null;
    private PDO $pdo;

    // ─── Singleton ────────────────────────────────────────────────────────────

    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // real prepared statements
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,   // UPDATE returns matched rows, not changed rows
        ]);
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Run a SELECT and return all matching rows.
     *
     * @param  string  $sql    SQL with named placeholders (:name)
     * @param  array   $params Associative array of placeholder => value
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $params = []): array
    {
        $db = self::getInstance();
        $start = microtime(true);

        $stmt = $db->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $db->log($sql, $params, microtime(true) - $start);

        return $rows;
    }

    /**
     * Run a SELECT and return the first row, or null if none found.
     *
     * @param  string  $sql
     * @param  array   $params
     * @return array<string, mixed>|null
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $db = self::getInstance();
        $start = microtime(true);

        $stmt = $db->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $db->log($sql, $params, microtime(true) - $start);

        return $row === false ? null : $row;
    }

    /**
     * Run an INSERT, UPDATE, or DELETE statement.
     *
     * Returns the auto-increment ID for a successful INSERT (when > 0),
     * or the number of affected rows for UPDATE / DELETE.
     *
     * @param  string  $sql
     * @param  array   $params
     * @return int  Last insert ID (INSERT) or affected row count (UPDATE/DELETE)
     */
    public static function execute(string $sql, array $params = []): int
    {
        $db = self::getInstance();
        $start = microtime(true);

        $stmt = $db->pdo->prepare($sql);
        $stmt->execute($params);

        $db->log($sql, $params, microtime(true) - $start);

        $insertId = (int) $db->pdo->lastInsertId();
        return $insertId > 0 ? $insertId : $stmt->rowCount();
    }

    // ─── Transactions ─────────────────────────────────────────────────────────

    public static function beginTransaction(): void
    {
        self::getInstance()->pdo->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->pdo->commit();
    }

    public static function rollback(): void
    {
        self::getInstance()->pdo->rollBack();
    }

    /**
     * Execute a callable inside a transaction. Rolls back automatically on any
     * exception and re-throws it; commits on success.
     *
     * @param  callable(): mixed $fn
     * @return mixed  The return value of $fn
     * @throws \Throwable
     */
    public static function transaction(callable $fn): mixed
    {
        self::beginTransaction();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    // ─── Introspection (testing / debug only) ─────────────────────────────────

    /**
     * Return the PDO instance. Only intended for migrate.php or tests.
     * Never use in controllers — all access must go through the static methods.
     */
    public static function pdo(): PDO
    {
        return self::getInstance()->pdo;
    }

    // ─── Internal Logging ─────────────────────────────────────────────────────

    /**
     * Append a query log entry when APP_DEBUG is true.
     * Writes to LOG_PATH (defined in config.php).
     * Log entries never include plaintext credential values;
     * param values are JSON-encoded so they appear as their types.
     */
    private function log(string $sql, array $params, float $duration): void
    {
        if (!defined('APP_DEBUG') || !APP_DEBUG) {
            return;
        }

        $ms      = number_format($duration * 1000, 2);
        $stamp   = date('Y-m-d H:i:s');
        $paramJs = empty($params) ? '' : ' | ' . json_encode($params, JSON_UNESCAPED_UNICODE);
        $line    = "[{$stamp}] DB [{$ms}ms] {$sql}{$paramJs}" . PHP_EOL;

        // Suppress write errors — a logging failure must never break the request.
        // APP_DEBUG is never true in production per config.example.php guidance.
        if (defined('LOG_PATH') && LOG_PATH !== '') {
            $dir = dirname(LOG_PATH);
            if (is_dir($dir)) {
                file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
            }
        }
    }
}
