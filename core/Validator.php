<?php
/**
 * LoreBuilder — Input Validator
 *
 * Provides an allowlist-based input parser for all controllers. Fields not
 * listed in the rule set are silently stripped — no mass-assignment possible.
 *
 * Usage:
 *   $data = Validator::parse($_POST, [
 *       'name'   => 'required|string|max:255',
 *       'status' => 'required|in:draft,published,archived',
 *       'sort'   => 'int|min:0|max:32767',
 *       'tags'   => 'array',
 *       'meta'   => 'json',
 *   ]);
 *
 * Rule syntax (pipe-separated):
 *   required          — field must be present and non-empty
 *   string            — cast to string; trim whitespace
 *   int               — cast to int; reject non-numeric
 *   bool              — accept 1/0/true/false/"true"/"false"
 *   float             — cast to float; reject non-numeric
 *   email             — validate with FILTER_VALIDATE_EMAIL
 *   url               — validate with FILTER_VALIDATE_URL (https required)
 *   slug              — only a-z, 0-9, hyphens; no leading/trailing hyphen
 *   json              — valid JSON string; decoded value passed through
 *   array             — must be a PHP array (used when input is already parsed JSON)
 *   in:a,b,c          — value must be one of the listed options
 *   min:N             — int/float: >= N; string: length >= N
 *   max:N             — int/float: <= N; string: length (chars) <= N
 *   nullable          — allow null/empty; if absent, field is excluded from output
 *
 * On any failure: throws AuthException(400, 'VALIDATION_ERROR') with `field` set.
 *
 * Dependencies: Auth.php (for AuthException)
 */

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

class Validator
{
    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Validate and cast $input against $rules. Returns only declared fields.
     *
     * @param  array<string, mixed>  $input  Raw input (e.g. from json_decode or $_POST)
     * @param  array<string, string> $rules  Field → rule string
     * @return array<string, mixed>          Validated, cast, stripped output
     * @throws AuthException                 HTTP 400 + VALIDATION_ERROR on any failure
     */
    public static function parse(array $input, array $rules): array
    {
        $output = [];

        foreach ($rules as $field => $ruleStr) {
            $ruleset  = self::parseRules($ruleStr);
            $required = in_array('required', $ruleset, true);
            $nullable = in_array('nullable', $ruleset, true);

            $present  = array_key_exists($field, $input);
            $raw      = $present ? $input[$field] : null;

            // Empty string and null are treated equivalently for required/nullable checks
            $empty = ($raw === null || $raw === '');

            if (!$present || $empty) {
                if ($required) {
                    self::fail($field, "The {$field} field is required.");
                }
                if ($nullable && $present) {
                    $output[$field] = null;
                }
                // Field absent and not required → omit from output
                continue;
            }

            $output[$field] = self::applyRules($field, $raw, $ruleset);
        }

        return $output;
    }

    /**
     * Like parse(), but reads from php://input as JSON.
     * Returns an empty array (not an error) if the body is empty.
     *
     * @param  array<string, string> $rules
     * @return array<string, mixed>
     * @throws AuthException  If body is not valid JSON, or validation fails
     */
    public static function parseJson(array $rules): array
    {
        $body = file_get_contents('php://input');

        if ($body === '' || $body === false) {
            return self::parse([], $rules);
        }

        $decoded = json_decode($body, associative: true);
        if (!is_array($decoded)) {
            throw new AuthException(
                'Request body must be a valid JSON object.',
                'VALIDATION_ERROR',
                400
            );
        }

        return self::parse($decoded, $rules);
    }

    /**
     * Like parse(), but reads query string parameters ($_GET).
     *
     * @param  array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function parseQuery(array $rules): array
    {
        return self::parse($_GET, $rules);
    }

    // ─── Rule Parsing & Application ───────────────────────────────────────────

    /**
     * @return string[]
     */
    private static function parseRules(string $ruleStr): array
    {
        return array_map('trim', explode('|', $ruleStr));
    }

    /**
     * Apply all rules to a non-empty raw value and return the processed result.
     *
     * @param  string   $field
     * @param  mixed    $raw
     * @param  string[] $ruleset
     * @return mixed
     */
    private static function applyRules(string $field, mixed $raw, array $ruleset): mixed
    {
        $value    = $raw;
        $typeRule = null;

        // Determine the type rule first (controls casting)
        foreach ($ruleset as $rule) {
            $base = explode(':', $rule)[0];
            if (in_array($base, ['string', 'int', 'float', 'bool', 'email', 'url', 'slug', 'json', 'array'], true)) {
                $typeRule = $base;
                break;
            }
        }

        // Cast / validate type
        $value = match ($typeRule) {
            'string' => self::castString($field, $value),
            'int'    => self::castInt($field, $value),
            'float'  => self::castFloat($field, $value),
            'bool'   => self::castBool($field, $value),
            'email'  => self::castEmail($field, $value),
            'url'    => self::castUrl($field, $value),
            'slug'   => self::castSlug($field, $value),
            'json'   => self::castJson($field, $value),
            'array'  => self::castArray($field, $value),
            default  => (is_string($value) ? trim($value) : $value),
        };

        // Apply constraint rules
        foreach ($ruleset as $rule) {
            if (str_starts_with($rule, 'in:')) {
                $allowed = explode(',', substr($rule, 3));
                if (!in_array((string) $value, $allowed, true)) {
                    self::fail($field, "The {$field} must be one of: " . implode(', ', $allowed) . '.');
                }
            }

            if (str_starts_with($rule, 'min:')) {
                $min = (float) substr($rule, 4);
                if (is_string($value) && mb_strlen($value) < $min) {
                    self::fail($field, "The {$field} must be at least {$min} characters.");
                }
                if (is_numeric($value) && $value < $min) {
                    self::fail($field, "The {$field} must be at least {$min}.");
                }
            }

            if (str_starts_with($rule, 'max:')) {
                $max = (float) substr($rule, 4);
                if (is_string($value) && mb_strlen($value) > $max) {
                    self::fail($field, "The {$field} may not exceed {$max} characters.");
                }
                if (is_numeric($value) && $value > $max) {
                    self::fail($field, "The {$field} may not exceed {$max}.");
                }
            }
        }

        return $value;
    }

    // ─── Type Casters ─────────────────────────────────────────────────────────

    private static function castString(string $field, mixed $value): string
    {
        if (!is_scalar($value)) {
            self::fail($field, "The {$field} must be a string.");
        }
        return trim((string) $value);
    }

    private static function castInt(string $field, mixed $value): int
    {
        if (is_bool($value) || (!is_numeric($value) && !is_int($value))) {
            self::fail($field, "The {$field} must be an integer.");
        }
        if (is_float($value + 0) && (int) $value !== ($value + 0)) {
            self::fail($field, "The {$field} must be an integer, not a decimal.");
        }
        return (int) $value;
    }

    private static function castFloat(string $field, mixed $value): float
    {
        if (is_bool($value) || !is_numeric($value)) {
            self::fail($field, "The {$field} must be a number.");
        }
        return (float) $value;
    }

    private static function castBool(string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalised = strtolower(trim((string) $value));
        if (in_array($normalised, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalised, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        self::fail($field, "The {$field} must be a boolean value.");
    }

    private static function castEmail(string $field, mixed $value): string
    {
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            self::fail($field, "The {$field} must be a valid email address.");
        }
        // Normalise to lowercase
        return strtolower($value);
    }

    private static function castUrl(string $field, mixed $value): string
    {
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            self::fail($field, "The {$field} must be a valid URL.");
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!in_array($scheme, ['https', 'http'], true)) {
            self::fail($field, "The {$field} URL must use http or https.");
        }
        return $value;
    }

    private static function castSlug(string $field, mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            self::fail($field, "The {$field} may only contain lowercase letters, numbers, and hyphens, and must not start or end with a hyphen.");
        }
        return $value;
    }

    private static function castJson(string $field, mixed $value): mixed
    {
        if (!is_string($value)) {
            self::fail($field, "The {$field} must be a JSON string.");
        }
        $decoded = json_decode($value, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::fail($field, "The {$field} contains invalid JSON: " . json_last_error_msg() . '.');
        }
        return $decoded;
    }

    private static function castArray(string $field, mixed $value): array
    {
        if (!is_array($value)) {
            self::fail($field, "The {$field} must be an array.");
        }
        return $value;
    }

    // ─── Error Helper ─────────────────────────────────────────────────────────

    /**
     * @throws AuthException
     * @return never
     */
    private static function fail(string $field, string $message): never
    {
        $ex = new AuthException($message, 'VALIDATION_ERROR', 400);
        /** @noinspection PhpDynamicFieldDeclarationInspection */
        $ex->field = $field;
        throw $ex;
    }
}
