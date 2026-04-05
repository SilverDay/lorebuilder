# LoreBuilder — Security Findings Log
# Append-only. Claude Code must log ALL findings here, even if immediately fixed.
# Format: see template below.

---

## Template

[FINDING-XXX]
Date: YYYY-MM-DD
Severity: CRITICAL | HIGH | MEDIUM | LOW
File: path/to/file.php
Line: N
Description: What the issue is
Fix: What was done or what needs to be done
Status: OPEN | RESOLVED
Resolved-by: Name or "Claude Code"
Resolved-date: YYYY-MM-DD

---

## Findings

[FINDING-001]
Date: 2026-04-05
Severity: MEDIUM
File: api/EntityController.php
Line: 377–385
Description: replaceEntityTags() mixes named (:wid) and positional (?) PDO placeholders in a
  single query. With ATTR_EMULATE_PREPARES=false (set in DB.php), PDO throws
  "Invalid parameter number: mixed named and positional parameters" whenever the
  endpoint is called with a non-empty tag_ids array. The endpoint is completely
  broken in production. Data itself is safe (tagIds are integer-cast), but the
  world-scoping check cannot execute, meaning tag validation silently fails via 500.
Fix: Replaced positional ? placeholders with named placeholders (:tag0, :tag1, …)
  generated dynamically from the array index so only named bindings are used.
Status: RESOLVED
Resolved-by: Claude Code
Resolved-date: 2026-04-05

---

[FINDING-002]
Date: 2026-04-05
Severity: MEDIUM
File: api/AuthController.php
Line: 194 (totpVerify), 261 (totpConfirm)
Description: Neither totpVerify() nor totpConfirm() apply rate limiting before calling
  Auth::verifyTotp(). TOTP codes are 6-digit numeric (1,000,000 combinations) with a
  30-second validity window. An attacker with a valid session (e.g. stolen cookie) can
  brute-force the remaining TOTP codes without throttling. The login endpoint is
  rate-limited, but once a session is established the TOTP check is unguarded.
Fix: Added RateLimit::check('totp:' . $user['id'], 5, 300) as the first statement in
  both totpVerify() and totpConfirm() (5 attempts per 5 minutes per user).
Status: RESOLVED
Resolved-by: Claude Code
Resolved-date: 2026-04-05

---

[FINDING-003]
Date: 2026-04-05
Severity: LOW
File: api/WorldController.php
Line: 491
Description: World name (user-supplied, validated only as string|max:255) is interpolated
  directly into the $subject argument of mail(). A name containing CRLF sequences
  (\r\n) could inject additional mail headers depending on PHP version and MTA
  configuration. Modern PHP (8.x) mitigates this in some configurations, but not all.
Fix: Applied str_replace(["\r", "\n"], ' ', ...) to $name before use in subject.
Status: RESOLVED
Resolved-by: Claude Code
Resolved-date: 2026-04-05

---

[FINDING-004]
Date: 2026-04-05
Severity: LOW
File: core/DB.php
Line: 183
Description: When APP_DEBUG is true, DB::log() serialises all query parameters to JSON
  and appends them to LOG_PATH. This includes values bound to queries that handle
  password reset tokens, TOTP codes, and session-adjacent data. Violates the
  "never log sensitive data" principle (CLAUDE.md). Risk is limited to environments
  where APP_DEBUG=true (should never be production per config.example.php), but a
  misconfigured staging or developer machine could leak tokens to log files.
Fix: Added a $sensitive key list; any param whose key matches is replaced with
  '[REDACTED]' before JSON-encoding for the log line.
Status: RESOLVED
Resolved-by: Claude Code
Resolved-date: 2026-04-05

---

[FINDING-005]
Date: 2026-04-05
Severity: LOW
File: api/EntityController.php
Line: 355–400 (replaceEntityTags)
Description: The PUT /worlds/:wid/entities/:id/tags endpoint replaces all tag assignments
  for an entity but writes no audit_log entry. Tag changes are not auditable.
  All other entity mutations (create, update, delete, attributes) do write audit entries.
Fix: Added self::audit($wid, $userId, 'entity.tags.replace', 'entity', $id) after
  the DB::transaction() call in replaceEntityTags().
Status: RESOLVED
Resolved-by: Claude Code
Resolved-date: 2026-04-05
