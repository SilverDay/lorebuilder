<?php
/**
 * LoreBuilder — Cryptographic Utilities
 *
 * Provides symmetric encryption/decryption for sensitive values stored in the
 * database: Anthropic API keys and TOTP secrets.
 *
 * Algorithm: libsodium XSalsa20-Poly1305 (sodium_crypto_secretbox)
 *   - 256-bit key (SODIUM_CRYPTO_SECRETBOX_KEYBYTES = 32 bytes)
 *   - 192-bit random nonce per encryption (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES = 24 bytes)
 *   - Authenticated encryption: decryption fails loudly on tampering
 *
 * Storage format (base64url-encoded):  nonce(24) || ciphertext
 *
 * APP_SECRET in config.php is a standard base64-encoded 32-byte key.
 * Generate once:
 *   php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
 *
 * Security invariants enforced here:
 *   - Plaintext is NEVER returned by any public method that returns a response
 *     (that is the caller's responsibility — see CLAUDE.md §6.2)
 *   - The raw key bytes are zeroed from memory after use via sodium_memzero()
 *   - CryptoException is thrown on any decryption failure — callers must not
 *     silently swallow it
 */

declare(strict_types=1);

class CryptoException extends \RuntimeException {}

class Crypto
{
    // ─── Encryption ───────────────────────────────────────────────────────────

    /**
     * Encrypt a plaintext string and return a base64-encoded blob for DB storage.
     *
     * The blob contains a random nonce prepended to the ciphertext:
     *   base64( nonce[24] || ciphertext )
     *
     * A fresh nonce is generated on every call, so encrypting the same plaintext
     * twice produces different ciphertext — this is expected and correct.
     *
     * @param  string $plaintext  Value to encrypt (e.g. an Anthropic API key)
     * @param  string $appSecret  Base64-encoded APP_SECRET from config.php
     * @return string             base64-encoded storage blob
     * @throws CryptoException    If the key is invalid or sodium is unavailable
     */
    public static function encrypt(string $plaintext, string $appSecret): string
    {
        $key = self::decodeKey($appSecret);

        try {
            $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            $blob       = base64_encode($nonce . $ciphertext);
        } finally {
            sodium_memzero($key);
        }

        return $blob;
    }

    /**
     * Decrypt a blob produced by encrypt() and return the plaintext.
     *
     * @param  string $blob       base64-encoded storage blob (nonce || ciphertext)
     * @param  string $appSecret  Base64-encoded APP_SECRET from config.php
     * @return string             Decrypted plaintext
     * @throws CryptoException    If decryption fails (wrong key, corrupted data,
     *                            or authentication tag mismatch)
     */
    public static function decrypt(string $blob, string $appSecret): string
    {
        $key = self::decodeKey($appSecret);

        try {
            $raw = base64_decode($blob, strict: true);
            if ($raw === false) {
                throw new CryptoException('Encrypted blob is not valid base64.');
            }

            $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) <= $nonceLen) {
                throw new CryptoException('Encrypted blob is too short to contain a nonce.');
            }

            $nonce      = substr($raw, 0, $nonceLen);
            $ciphertext = substr($raw, $nonceLen);

            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            if ($plaintext === false) {
                // Authentication failed — key mismatch or data corruption.
                // Never reveal why; just fail loudly so callers can't probe.
                throw new CryptoException('Decryption failed: authentication error.');
            }
        } finally {
            sodium_memzero($key);
        }

        return $plaintext;
    }

    // ─── Convenience Aliases (named for the primary use-case) ─────────────────

    /**
     * Encrypt an Anthropic API key before writing to worlds.ai_key_enc.
     * Alias for encrypt() — identical behaviour, clearer call site.
     */
    public static function encryptApiKey(string $plaintextKey, string $appSecret): string
    {
        return self::encrypt($plaintextKey, $appSecret);
    }

    /**
     * Decrypt an Anthropic API key retrieved from worlds.ai_key_enc.
     * Alias for decrypt() — identical behaviour, clearer call site.
     *
     * WARNING: The returned string is a live API key.
     * - Never log it.
     * - Never return it in any HTTP response.
     * - Use it immediately and discard.
     */
    public static function decryptApiKey(string $blob, string $appSecret): string
    {
        return self::decrypt($blob, $appSecret);
    }

    // ─── Key Fingerprint ──────────────────────────────────────────────────────

    /**
     * Derive a display-safe fingerprint from a plaintext API key.
     * Stores the first 12 chars + "…" + last 4 chars so users can
     * visually identify which key they saved without exposing it.
     *
     * Example: "sk-ant-…4xKm"  (prefix truncated so it does not trigger secret scanners)
     *
     * Never pass this to decrypt() — it is one-way and lossy.
     *
     * @param string $plaintextKey  The raw Anthropic API key
     */
    public static function apiKeyFingerprint(string $plaintextKey): string
    {
        $len = strlen($plaintextKey);
        if ($len <= 16) {
            // Key too short to safely truncate — show only first 4 chars
            return substr($plaintextKey, 0, 4) . '…';
        }
        return substr($plaintextKey, 0, 12) . '…' . substr($plaintextKey, -4);
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Decode and validate the APP_SECRET into raw key bytes.
     * The returned string must be sodium_memzero()'d by the caller after use.
     *
     * @throws CryptoException If the decoded key is not exactly 32 bytes
     */
    private static function decodeKey(string $appSecret): string
    {
        $key = base64_decode($appSecret, strict: true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new CryptoException(
                'APP_SECRET must be a base64-encoded ' .
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES .
                '-byte key. Regenerate with: ' .
                'php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"'
            );
        }

        return $key;
    }
}
