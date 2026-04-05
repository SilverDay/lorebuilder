# LoreBuilder — Security Architecture & Threat Model
# Version: 1.0 | SilverDay Media

---

## 1. Threat Model (STRIDE)

### 1.1 Assets to Protect
| Asset | Classification | Why It Matters |
|---|---|---|
| Anthropic API keys (user-provided) | CRITICAL | Direct financial impact on user |
| Platform operator API key | CRITICAL | Direct financial impact on operator |
| User passwords | HIGH | Account takeover |
| World lore data | HIGH | User's creative work; potential IP |
| User PII (email, name) | HIGH | GDPR obligations |
| Session tokens | HIGH | Session hijacking |
| TOTP secrets | HIGH | 2FA bypass |
| Audit logs | MEDIUM | Forensic evidence |
| App configuration | MEDIUM | Exposes infra details |

---

### 1.2 Threat Matrix

**S — Spoofing**
- T: Attacker forges session cookie → Control: HttpOnly+SameSite=Strict cookie, session_regenerate_id on login, CSRF double-submit
- T: Attacker enumerates users via login timing → Control: constant-time comparison, uniform error messages, artificial delay

**T — Tampering**
- T: SQL injection via entity name/notes → Control: PDO prepared statements, no dynamic SQL construction
- T: Mass assignment (inject world_id, user_id) → Control: explicit allowlist of accepted fields per endpoint, never pass $_POST directly to DB
- T: Modify another user's entity → Control: Guard::requireWorldAccess() on every write, world_id always scoped from session not request

**R — Repudiation**
- T: User denies creating/deleting content → Control: append-only audit_log with user_id, action, timestamp, IP, diff_json; cannot be modified by users

**I — Information Disclosure**
- T: API key exfiltration via response → Control: key never serialised; only fingerprint returned; code review checklist item
- T: API key in logs → Control: log scrubber regex applied to all outgoing log writes; keys excluded from error context
- T: Cross-tenant data access → Control: every query includes world_id scoped from session membership; Guard enforces this
- T: Stack traces in production → Control: display_errors=Off in php.ini; errors logged to file only
- T: Directory listing → Control: Apache Options -Indexes; DirectoryIndex disabled outside public/
- T: Sensitive files accessible via web → Control: only public/ is DocumentRoot; core/, config/, storage/ are above web root

**D — Denial of Service**
- T: AI endpoint abuse (cost amplification) → Control: per-user token bucket (20 req/hour), per-world monthly budget cap, per-IP rate limit
- T: Large payload attacks → Control: post_max_size=2M in php.ini for API; separate multipart limit for uploads
- T: Slow POST / slowloris → Control: Apache mod_reqtimeout; connection timeout settings

**E — Elevation of Privilege**
- T: Author sets own role to owner → Control: role changes only by owner/admin; Guard checks role from DB not request
- T: IDOR on world resources → Control: all IDs validated against world membership; sequential IDs replaced with UUIDs for public-facing resources (entity slugs)
- T: PHP file upload → Control: uploads stored outside web root; MIME type validated via finfo; no execution permitted in storage/uploads/

---

## 2. Security Controls Reference

### 2.1 Authentication (Auth.php)

```php
// Login flow
Auth::login($username, $password):
  1. Load user by username (timing-safe: always query even if user not found)
  2. password_verify() with bcrypt
  3. If TOTP enabled for world: require TOTP challenge before session creation
  4. session_regenerate_id(true)
  5. $_SESSION['user_id'] = $id; $_SESSION['csrf_token'] = bin2hex(random_bytes(32))
  6. Log to audit_log: action='login', ip=$_SERVER['REMOTE_ADDR']
  7. Increment failed_login_count on failure; lock after 10 (per user + per IP)

// CSRF validation
Auth::verifyCsrf():
  $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
      throw new ForbiddenException('CSRF_MISMATCH');
  }
  // Called automatically by Router for POST/PATCH/PUT/DELETE
```

### 2.2 Authorisation (Guard.php)

```php
Guard::requireWorldAccess(int $worldId, int $userId, string $minRole = 'viewer'): void
  // Queries world_members WHERE world_id=:wid AND user_id=:uid AND deleted_at IS NULL
  // Compares member.role against role hierarchy
  // Throws ForbiddenException (HTTP 403) on failure
  // NEVER derives worldId from request body — always from validated route parameter

Guard::requireAdmin(int $userId): void
  // Platform-level admin check (users.is_platform_admin)
  // Used only for /api/v1/admin/* routes

Guard::ownOrRole(int $entityOwnerId, int $userId, int $worldId, string $fallbackRole): void
  // Passes if userId == entityOwnerId OR has fallbackRole in world
  // Used for "edit own notes" type permissions
```

### 2.3 API Key Encryption (Crypto.php)

```php
// Uses libsodium secretbox (XSalsa20-Poly1305)
// APP_SECRET is a 32-byte key from config.php (never in DB, never committed)
// APP_SECRET generated at install: sodium_crypto_secretbox_keygen()

Crypto::encryptApiKey(string $plaintext, string $appSecret): string
  $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = sodium_crypto_secretbox($plaintext, $nonce, $appSecret);
  sodium_memzero($plaintext);
  return base64_encode($nonce . $cipher);

Crypto::decryptApiKey(string $encoded, string $appSecret): string
  $decoded = base64_decode($encoded);
  $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $plain = sodium_crypto_secretbox_open($cipher, $nonce, $appSecret);
  if ($plain === false) throw new RuntimeException('Key decryption failed');
  return $plain;

Crypto::keyFingerprint(string $plaintext): string
  return substr($plaintext, 0, 10) . '…' . substr($plaintext, -4);
  // e.g. "sk-ant-api…X4aB"
  // ONLY this is stored/returned to client, never the full key
```

### 2.4 Rate Limiting (RateLimit.php)

Token-bucket implementation backed by rate_limit_buckets table.
Checked before any AI call. Also applied to login, registration, and export.

```
Limits (defaults, configurable per world):
  AI requests:      20 per user per hour
  AI requests:      100 per world per hour  
  Login attempts:   10 per username per 15 min → lockout
  Login attempts:   30 per IP per 15 min → lockout
  Registration:     5 per IP per hour
  Export:           3 per user per hour
```

Response on limit hit:
```json
HTTP 429
{ "error": "Rate limit exceeded", "code": "RATE_LIMITED", "retry_after": 1847 }
```

### 2.5 Input Validation Pattern

```php
// All controllers use this pattern. Never pass $_POST/$_GET directly to DB.
$data = Validator::parse($_POST, [
    'name'   => ['required', 'string', 'max:255'],
    'type'   => ['required', 'in:Character,Location,Event,Faction,Artefact,Creature,Concept'],
    'status' => ['in:draft,published,archived', 'default:draft'],
    'notes'  => ['string', 'max:50000'],
]);
// Validator throws ValidationException (HTTP 422) on failure
// Validated $data contains ONLY declared keys — no mass assignment possible
```

### 2.6 Output Encoding

- PHP API responses: json_encode() — safe for JSON context
- Any HTML rendering (emails, exports): htmlspecialchars($val, ENT_QUOTES, 'UTF-8')
- Vue templates: auto-escaped ({{ }}) — use v-html ONLY for trusted Markdown output
- Markdown rendering: Marked.js with sanitize: true + DOMPurify post-processing
- AI responses displayed via Markdown renderer, never via v-html with raw API text

### 2.7 Security Headers (Apache .htaccess / VirtualHost)

```apache
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://api.anthropic.com; frame-ancestors 'none'"
Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
```

Note: connect-src includes api.anthropic.com only if frontend ever calls directly (it does NOT in our architecture — all AI calls are server-proxied). Included as defence-in-depth.

---

## 3. GDPR & Data Minimisation

- Users provide: email, display name, password. No phone, no real name required.
- Lore data belongs to the user. Export is always available (GET /api/v1/export).
- Account deletion: cascading soft-delete of world memberships; world data retained if other members exist (owner must explicitly delete world).
- AI sessions: stored for audit and cost tracking. User can request deletion of their AI session history.
- Logs: IP addresses are pseudonymised after 30 days (last octet zeroed).
- No analytics, no tracking pixels, no third-party scripts loaded by default.

---

## 4. Dependency Management

- PHP: no Composer dependencies except libsodium (bundled with PHP 8.x). Zero external packages.
- Frontend: minimal. Allowed: Vue 3, Pinia, Vue Router, Vite, vis-network, vis-timeline, Marked.js, DOMPurify.
- Any new dependency requires an entry in DECISIONS.md with justification and security review note.
- NPM packages: lock file committed. `npm audit` run in CI before any merge.

---

## 5. Secrets Management

| Secret | Storage | Rotation |
|---|---|---|
| APP_SECRET (libsodium key) | config.php (600, outside web root) | Requires re-encryption of all API keys |
| DB password | config.php | Quarterly or on compromise |
| Platform Anthropic key | config.php (NEVER in DB) | On compromise or quarterly |
| User Anthropic keys | DB (encrypted with APP_SECRET) | User-managed |
| TOTP secrets | DB (encrypted with APP_SECRET) | On user request |
| Session keys | PHP session storage | Per session |

config.php MUST be in .gitignore. A pre-commit hook enforces this (see hooks/).

---

## 6. Incident Response Notes

If an API key compromise is suspected:
1. Revoke key at Anthropic console immediately.
2. Run `php scripts/invalidate-ai-keys.php --world=N` to zero out stored key.
3. Notify affected world owner via email.
4. Check ai_sessions for anomalous usage in the preceding 24h.
5. Log incident in SECURITY_FINDINGS.md with CRITICAL severity.

If a session token is compromised:
1. Run `php scripts/invalidate-sessions.php --user=N` to regenerate session secret.
2. Force re-login for all sessions of that user.
