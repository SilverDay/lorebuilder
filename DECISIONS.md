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

---

## ADR-008: Story Board — Milkdown WYSIWYG Editor
Date: 2026-04-10
Status: Accepted
Context: Story Board needs a Markdown editor. Options: CodeMirror 6 (source editing),
         Milkdown (WYSIWYG on ProseMirror), Tiptap (WYSIWYG on ProseMirror).
Decision: Use Milkdown for WYSIWYG Markdown editing. Authors see formatted output while
          editing, but the stored format remains Markdown (LONGTEXT in DB).
Reasons: (1) Better writing experience for authors — no raw Markdown syntax required.
         (2) Milkdown stores Markdown natively, so content is portable and indexable.
         (3) Vue integration via @milkdown/vue.
         (4) Plugin system supports custom extensions for entity mention detection.
Consequences: Different dependency set than CodeMirror. Slightly heavier than source-mode
              editing, but acceptable for a writing-focused feature. Toolbar is handled by
              Milkdown's command system rather than custom Markdown insertion helpers.

---

## ADR-009: Story Board — Route-Level Views
Date: 2026-04-10
Status: Accepted
Context: Story Board could be a modal overlay or a dedicated route.
Decision: Route-level at /worlds/:wid/stories (list) and /worlds/:wid/stories/:sid (editor).
Reasons: (1) Deep-linking — direct URLs to specific stories.
         (2) Full-screen editing without modal constraints.
         (3) Consistent with existing entity/arc patterns.
Consequences: Standard Vue Router integration. Nav item added to AppNav.

---

## ADR-010: Story Board — Chapters as Story Records
Date: 2026-04-10
Status: Accepted
Context: Stories can be novel-length. Auto-save sends full content. Need a practical
         content size constraint.
Decision: Each story record represents a chapter or scene. A story arc groups chapters
          via arc_id + sort_order. Content limit: 500,000 characters (~100K words) per
          record — far beyond any realistic single chapter, but prevents unbounded growth.
          The UI encourages splitting into chapters naturally via the story list.
Reasons: (1) Chapters are the natural unit of writing.
         (2) Arc grouping already exists in the data model (arc_id + sort_order).
         (3) Auto-save remains fast — a single chapter is typically 5-30KB.
         (4) AI context window only needs cursor-adjacent text from one chapter.
Consequences: Very long stories are a set of chapter records under one arc. No parent-child
              table needed. sort_order within an arc orders chapters. Standalone stories
              (no arc) remain possible.

---

## ADR-011: Story Board — No Server-Side Versioning
Date: 2026-04-10
Status: Accepted
Context: Option to track version history of story content (git-like diffs in DB).
Decision: Deferred. Auto-save overwrites content in place. No version table.
          Future option: GitHub integration for versioned prose (external VCS).
Reasons: (1) Version history in DB adds significant storage and complexity.
         (2) Authors already use external tools (Scrivener, Git) for versioning.
         (3) GitHub integration is a cleaner long-term path — stores prose diffs properly.
Consequences: Lost content between auto-saves is not recoverable. Acceptable given
              30-second auto-save interval. beforeunload warning prevents tab-close loss.

---

## ADR-012: Story Board — No Collaborative Editing
Date: 2026-04-10
Status: Accepted
Context: Multiple authors editing the same story simultaneously.
Decision: Explicitly out of scope. Single-author editing only. Conflict detection via
          updated_at (409 on stale writes) handles multi-tab scenarios.
Reasons: (1) Real-time collab requires CRDT/OT infrastructure — massive scope increase.
         (2) LoreBuilder's primary use case is solo or small-team world-building.
         (3) Multi-user worlds already support role-based access — just not simultaneous editing.
Consequences: If two authors open the same story, last save wins with a conflict warning.
              Acceptable for the target audience.
