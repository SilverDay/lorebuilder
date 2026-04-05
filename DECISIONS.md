# LoreBuilder — Architecture Decision Log
# Append-only. Never modify existing entries.

---

## ADR-001: No PHP Framework
Date: 2026-04-05
Status: Accepted
Context: LoreBuilder needs a lean, auditable PHP backend with no dependency surprises.
Decision: Custom router + DB wrapper (same pattern as OSGridManager, VendorShield).
          No Composer dependencies except libsodium (bundled in PHP 8.x).
Consequences: More boilerplate per controller; full visibility of every code path;
              zero framework CVE exposure; easier security audits.

---

## ADR-002: Multi-Tenancy via World Isolation
Date: 2026-04-05
Status: Accepted
Context: LoreBuilder must support multiple authors running independent projects.
Decision: Isolation unit is a "World". Every lore table has world_id FK.
          Guard::requireWorldAccess() enforced on every world-scoped endpoint.
          Role hierarchy: owner > admin > author > reviewer > viewer.
Consequences: Every query must include world_id scoping. Slightly more complex
              than single-tenant but enables SaaS use and collab features.

---

## ADR-003: API Key Encryption with libsodium
Date: 2026-04-05
Status: Accepted
Context: User-provided Anthropic API keys must be stored server-side for proxy pattern.
Decision: Encrypt with sodium_crypto_secretbox (XSalsa20-Poly1305).
          APP_SECRET stored in config.php (never in DB, never committed).
          Keys decrypted in memory only at call time; zeroed with sodium_memzero after.
          Only key fingerprint (first 10 + last 4 chars) returned to/stored for client.
Consequences: Key compromise requires both DB access AND config.php.
              APP_SECRET rotation requires re-encrypting all stored keys (script provided).

---

## ADR-004: AI Calls Server-Side Only (No Browser-Direct)
Date: 2026-04-05
Status: Accepted
Context: Option considered to call Anthropic API directly from browser (key in localStorage).
Decision: Rejected. All AI calls proxied through PHP backend.
Reasons: (1) Key never touches browser. (2) Enables audit logging.
         (3) Enables rate limiting and budget enforcement.
         (4) Enables context assembly (server has DB access). (5) CORS avoided.
Consequences: Slightly higher server load; acceptable for expected usage scale.

---

## ADR-005: OAuth Placeholder for Anthropic (Phase 2)
Date: 2026-04-05
Status: Deferred
Context: Users ideally link their Claude.ai account rather than manage raw API keys.
Decision: Schema reserves oauth_providers table and users.oauth_anthropic_token column.
          Phase 1 endpoints return 501 Not Implemented.
          No implementation until Anthropic publishes OAuth specification.
Consequences: Zero Phase 1 cost. Schema migration not required in Phase 2.

---

## ADR-006: Timeline Scale Modes (Era + Numeric + Date)
Date: 2026-04-05
Status: Accepted
Context: WCSOZ uses an in-world era system, not real-world dates. Other projects may use
         real dates or purely ordinal numbering.
Decision: timeline.scale_mode ENUM('era','numeric','date').
          Era mode: position_era stores era name string; position_order for sort.
          Numeric mode: position_value (DECIMAL) for ordinal or fractional positions.
          Date mode: position_value stores UNIX timestamp; position_label for display.
Consequences: Slightly complex rendering logic in frontend; covers all known use cases
              without forcing real-world date semantics on fantasy projects.

---

## ADR-007: Soft Deletes Throughout
Date: 2026-04-05
Status: Accepted
Context: Authors delete content accidentally; lore is often valuable to recover.
Decision: All entity tables use deleted_at DATETIME NULL. DELETE endpoints set deleted_at,
          never issue SQL DELETE. Queries always WHERE deleted_at IS NULL.
          Hard delete available only via CLI scripts for GDPR compliance.
Consequences: Storage grows over time; acceptable. Indexes include deleted_at.
              GDPR right-to-erasure handled by CLI hard-delete script.
