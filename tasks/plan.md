# LoreBuilder — Implementation Plan
**Last updated:** 2026-04-05 (session 2)  
**Derived from:** docs/design-document.md §10 (Phased Roadmap)  
**Status markers:** `[x]` done · `[ ]` open · `[!]` blocked · `[-]` deferred

---

## Current State Snapshot

### Completed infrastructure
- [x] `migrations/001_initial.sql` — full schema (all 16 tables)
- [x] `scripts/migrate.php` — CLI migration runner with --dry-run / --status
- [x] `config/config.php` + `config.example.php`
- [x] `core/DB.php` — PDO singleton, query / queryOne / execute / transaction
- [x] `core/Auth.php` — session, CSRF, bcrypt, TOTP (RFC 6238), AuthException
- [x] `core/Guard.php` — requireWorldAccess, role hierarchy, requireOwnerOrRole
- [x] `core/Crypto.php` — libsodium secretbox, encryptApiKey / decryptApiKey, fingerprint

### Completed this session
- [x] `core/RateLimit.php` — token-bucket limiter, checkLogin, checkRegistration
- [x] `core/Validator.php` — allowlist parser, type casters, constraint rules
- [x] `core/Router.php` — method+path dispatcher, AuthException → JSON, SPA fallback
- [x] `public/index.php` — bootstrap: config, core, all controllers, security headers, full route table
- [x] `public/.htaccess` — rewrite rules, block sensitive paths, caching, PHP hardening

---

## Phase 1 — Foundation (MVP)

Goal: a working API that can create and retrieve entities, with auth enforced end-to-end.  
Completion gate: `curl` can register, log in, create a world, add an entity, and fetch it back.

### 1A · Core PHP layer (remaining)

- [x] **`core/RateLimit.php`** — token-bucket limiter backed by `rate_limit_buckets` table
- [x] **`core/Validator.php`** — input allowlist parser used by every controller
- [x] **`core/Router.php`** — method + path dispatcher
- [x] **`public/index.php`** — bootstrap with full route table
- [x] **`public/.htaccess`** — Apache routing rules

### 1B · Authentication API (`api/AuthController.php`)

- [ ] `POST /api/v1/auth/register` — create user (REGISTRATION_OPEN gate, email uniqueness, bcrypt, send verify email)
- [ ] `POST /api/v1/auth/login` — RateLimit::checkLogin, verify password, Auth::login, TOTP step if enabled
- [ ] `POST /api/v1/auth/totp/verify` — verify code, Auth::markTotpVerified
- [ ] `POST /api/v1/auth/logout` — Auth::logout
- [ ] `GET  /api/v1/auth/me` — return session user (id, username, display_name, totp_enabled)
- [ ] `POST /api/v1/auth/password/reset-request` — generate token, store hash, send email
- [ ] `POST /api/v1/auth/password/reset` — consume token, hash new password
- [ ] `POST /api/v1/auth/totp/setup` — generate secret, return URI for QR; not active until confirmed
- [ ] `POST /api/v1/auth/totp/confirm` — verify first code, write encrypted secret to DB, enable TOTP
- [ ] `DELETE /api/v1/auth/totp` — disable TOTP (requires current password + valid TOTP code)

Security checklist per endpoint: Auth ✓ · CSRF ✓ · Validation ✓ · Rate limit ✓ · No stack trace ✓

### 1C · World & Membership API (`api/WorldController.php`)

- [ ] `GET  /api/v1/worlds` — list worlds the user is a member of
- [ ] `POST /api/v1/worlds` — create world (user becomes owner, auto-joined as owner in world_members)
- [ ] `GET  /api/v1/worlds/:wid` — world detail (Guard: viewer)
- [ ] `PATCH /api/v1/worlds/:wid` — update world metadata (Guard: admin)
- [ ] `DELETE /api/v1/worlds/:wid` — soft delete (Guard: owner only)
- [ ] `GET  /api/v1/worlds/:wid/members` — list members (Guard: viewer)
- [ ] `PATCH /api/v1/worlds/:wid/members/:uid` — change role (Guard: owner for admin promotions; admin for others)
- [ ] `DELETE /api/v1/worlds/:wid/members/:uid` — remove member (Guard: admin; owner cannot be removed)

### 1D · Entity CRUD API (`api/EntityController.php`)

- [ ] `GET    /api/v1/worlds/:wid/entities` — paginated list; filter by type, status, tag
- [ ] `POST   /api/v1/worlds/:wid/entities` — create entity (Guard: author); write audit_log
- [ ] `GET    /api/v1/worlds/:wid/entities/:id` — entity detail with attributes + relationships + notes
- [ ] `PATCH  /api/v1/worlds/:wid/entities/:id` — update (Guard: author + requireOwnerOrRole); write audit_log diff
- [ ] `DELETE /api/v1/worlds/:wid/entities/:id` — soft delete (Guard: author + requireOwnerOrRole)
- [ ] `GET    /api/v1/worlds/:wid/entities/:id/attributes` — list typed attributes
- [ ] `PUT    /api/v1/worlds/:wid/entities/:id/attributes` — replace full attribute set (Guard: author)
- [ ] `GET    /api/v1/worlds/:wid/entities/:id/tags` — list tags on entity
- [ ] `PUT    /api/v1/worlds/:wid/entities/:id/tags` — replace tag set (Guard: author)

### 1E · Tag API (`api/EntityController.php`, tag sub-resource)

- [ ] `GET    /api/v1/worlds/:wid/tags` — list all tags in world
- [ ] `POST   /api/v1/worlds/:wid/tags` — create tag (Guard: author)
- [ ] `PATCH  /api/v1/worlds/:wid/tags/:tid` — rename / recolour (Guard: admin)
- [ ] `DELETE /api/v1/worlds/:wid/tags/:tid` — delete tag (Guard: admin; cascades via FK)

### 1F · Relationship API (`api/RelationshipController.php`)

- [ ] `GET    /api/v1/worlds/:wid/relationships` — all relationships; filter by from_entity, to_entity, rel_type
- [ ] `POST   /api/v1/worlds/:wid/relationships` — create relationship (Guard: author; both entities must belong to world)
- [ ] `PATCH  /api/v1/worlds/:wid/relationships/:id` — update rel_type, strength, notes, bidirectional
- [ ] `DELETE /api/v1/worlds/:wid/relationships/:id` — soft delete

### 1G · World AI Key Settings (`api/WorldController.php` or `api/AiController.php`)

- [ ] `GET  /api/v1/worlds/:wid/settings/ai` — return ai_key_mode, fingerprint, budget stats (Guard: owner)
- [ ] `PUT  /api/v1/worlds/:wid/settings/ai/key` — accept plaintext key, encrypt, store fingerprint (Guard: owner)
  - Key NEVER returned to client. Response: `{"saved": true, "fingerprint": "sk-ant-…4xKm"}`
- [ ] `DELETE /api/v1/worlds/:wid/settings/ai/key` — remove key (Guard: owner)

---

## Phase 2 — Narrative Structure

Goal: timelines, story arcs, lore notes, full-text search, tags filterable in UI.

### 2A · Core layer additions

- [ ] `core/Claude.php` — context assembler + Anthropic API client  
  (Moved here from Phase 4 because context assembly is shared infrastructure)
  - `Claude::buildContext(int $entityId, int $worldId, string $mode): array`
  - `Claude::callApi(array $context, string $userPrompt, string $apiKey): array`
  - Context budget logic per design-document §7.4
  - Never logs apiKey; logs only session metadata

### 2B · Timeline API (`api/TimelineController.php`)

- [ ] `GET    /api/v1/worlds/:wid/timelines` — list timelines
- [ ] `POST   /api/v1/worlds/:wid/timelines` — create timeline (Guard: author)
- [ ] `GET    /api/v1/worlds/:wid/timelines/:tid` — timeline + events
- [ ] `PATCH  /api/v1/worlds/:wid/timelines/:tid` — update metadata
- [ ] `DELETE /api/v1/worlds/:wid/timelines/:tid` — soft delete
- [ ] `GET    /api/v1/worlds/:wid/timelines/:tid/events` — list events (ordered by position_order)
- [ ] `POST   /api/v1/worlds/:wid/timelines/:tid/events` — create event
- [ ] `PATCH  /api/v1/worlds/:wid/timelines/:tid/events/:eid` — update event
- [ ] `DELETE /api/v1/worlds/:wid/timelines/:tid/events/:eid` — soft delete
- [ ] `PUT    /api/v1/worlds/:wid/timelines/:tid/events/reorder` — bulk position_order update

### 2C · Story Arc API (`api/StoryArcController.php`)

- [ ] `GET    /api/v1/worlds/:wid/story-arcs` — list arcs; filter by status
- [ ] `POST   /api/v1/worlds/:wid/story-arcs` — create arc
- [ ] `GET    /api/v1/worlds/:wid/story-arcs/:aid` — arc detail + entities
- [ ] `PATCH  /api/v1/worlds/:wid/story-arcs/:aid` — update (status, logline, theme, sort_order)
- [ ] `DELETE /api/v1/worlds/:wid/story-arcs/:aid` — soft delete
- [ ] `PUT    /api/v1/worlds/:wid/story-arcs/:aid/entities` — replace entity list in arc (Guard: author)

### 2D · Lore Notes API (`api/NoteController.php`)

- [ ] `GET    /api/v1/worlds/:wid/notes` — world-level notes
- [ ] `GET    /api/v1/worlds/:wid/entities/:id/notes` — entity notes (canonical + general)
- [ ] `POST   /api/v1/worlds/:wid/entities/:id/notes` — create note (Guard: author)
- [ ] `PATCH  /api/v1/worlds/:wid/notes/:nid` — edit note content
- [ ] `DELETE /api/v1/worlds/:wid/notes/:nid` — soft delete
- [ ] `POST   /api/v1/worlds/:wid/notes/:nid/promote` — mark is_canonical=1 (Guard: admin)

### 2E · Search API (`api/EntityController.php`)

- [ ] `GET /api/v1/worlds/:wid/search?q=&type=&tag=` — MariaDB FULLTEXT search on entities + lore_notes
  - MATCH AGAINST with boolean mode
  - Filter by entity type and/or tag (joined)
  - Returns entity rows with relevance score, paginated

### 2F · Invitation API (`api/WorldController.php`)

- [ ] `POST   /api/v1/worlds/:wid/invitations` — send email invite with token (Guard: admin)
- [ ] `GET    /api/v1/invitations/:token` — validate invite (public; checks expiry)
- [ ] `POST   /api/v1/invitations/:token/accept` — consume invite, create membership (authed user)

### 2G · Vue 3 SPA — Phase 2 scope

- [ ] `frontend/` scaffold — Vite config, `src/main.js`, Vue Router, Pinia stores
- [ ] `src/api/client.js` — fetch wrapper (CSRF header auto-attach, 401 redirect, 429 toast)
- [ ] `src/router/index.js` — route definitions with auth guard
- [ ] `src/stores/auth.js` — session user state
- [ ] `src/stores/world.js` — current world + membership cache
- [ ] `src/views/LoginView.vue`
- [ ] `src/views/RegisterView.vue`
- [ ] `src/views/WorldListView.vue`
- [ ] `src/views/WorldCreateView.vue`
- [ ] `src/views/EntityListView.vue` — filterable grid (type, status, tag)
- [ ] `src/views/EntityDetailView.vue` — three-panel layout (meta | notes | relationships)
- [ ] `src/views/EntityCreateView.vue` / `EntityEditView.vue`
- [ ] `src/components/EntityMeta.vue` — type badge, status, attributes table, tags
- [ ] `src/components/NotesList.vue` — chronological Markdown notes (Marked.js + DOMPurify)
- [ ] `src/components/RelationshipList.vue` — grouped by type, linked to counterpart entities

---

## Phase 3 — Visualisation

Goal: graph view, dashboard, audit log viewer.

- [ ] `GET /api/v1/worlds/:wid/graph` — nodes + edges JSON optimised for vis-network
  - Nodes: `{id, label, type, status}` — all non-deleted entities
  - Edges: `{from, to, label, strength}` — all non-deleted relationships
- [ ] `src/views/GraphView.vue` — vis-network wrapper
  - Node colour by entity type
  - Edge label = rel_type
  - Physics toggle; click node → navigate to EntityDetailView
- [ ] `src/views/TimelineView.vue` — vis-timeline wrapper
  - Loads timeline + events; groups by era if scale_mode = 'era'
  - Drag to reorder → PUT reorder endpoint
- [ ] `src/views/StoryArcKanban.vue` — Kanban: Seed → Rising Action → Climax → Resolution
  - Drag arc cards between columns → PATCH status
- [ ] `src/views/DashboardView.vue`
  - Entity counts by type (chart or stat cards)
  - Recent activity from audit_log
  - Arc health summary
  - Quick-access to AI assistant
- [ ] `GET /api/v1/worlds/:wid/audit-log` — paginated audit_log entries (Guard: admin)
- [ ] `src/views/AuditLogView.vue` — paginated change history table

---

## Phase 4 — Claude Integration

Goal: AI assistant callable from any entity; all invocation modes; budget enforcement.

### 4A · Backend

- [ ] Complete `core/Claude.php`
  - Context assembly per design-document §7.4 token budget priority
  - `Claude::renderTemplate(string $tpl, array $vars): string` — `{{variable}}` substitution
  - HTTP client: native PHP streams (no cURL dependency); 60-second timeout
  - Error handling: API errors surfaced as structured error response; never expose key

- [ ] `POST /api/v1/worlds/:wid/ai/assist` — entity or world-level assist
  - Auth → Guard (author) → RateLimit (20/user/hour, 100/world/hour)
  - Validate: entity_id (optional), mode, user_prompt
  - Decrypt API key (Crypto::decryptApiKey)
  - Claude::buildContext → Claude::callApi
  - Write ai_sessions row (tokens, model, status)
  - Write lore_notes row (ai_generated=1, ai_session_id)
  - Return response text + session_id + token counts
  - NEVER return api_key in any response field

- [ ] `POST /api/v1/worlds/:wid/ai/consistency-check`
  - Assembles full world snapshot → consistency_check mode
  - Same auth/rate-limit/logging pipeline as assist

- [ ] `GET  /api/v1/worlds/:wid/ai/sessions` — paginated AI session history (Guard: author)
- [ ] `GET  /api/v1/worlds/:wid/settings/ai/budget` — tokens used / limit / reset date (Guard: owner)

- [ ] `GET    /api/v1/worlds/:wid/prompt-templates` — list world + platform templates (Guard: author)
- [ ] `POST   /api/v1/worlds/:wid/prompt-templates` — create custom template (Guard: admin)
- [ ] `PATCH  /api/v1/worlds/:wid/prompt-templates/:id` — edit template (Guard: admin)
- [ ] `DELETE /api/v1/worlds/:wid/prompt-templates/:id` — delete (Guard: admin; cannot delete platform defaults)

### 4B · Frontend

- [ ] `src/components/ai/AiPanel.vue` — floating drawer; mode selector; prompt textarea; response card
- [ ] `src/components/ai/AiResponseCard.vue` — rendered Markdown + Accept / Edit / Discard actions
- [ ] `src/components/ai/AiPromptEditor.vue` — template variable preview + send button
- [ ] `src/views/AiHistoryView.vue` — session history table with token counts
- [ ] `src/views/WorldAiSettingsView.vue` — key entry form, fingerprint display, budget gauge
- [ ] `src/stores/ai.js` — current session, loading state, response cache

---

## Phase 5 — Power Features

Goal: export/import, multi-user invitation UI, WCSOZ sync, timeline overlays.

- [ ] `GET  /api/v1/worlds/:wid/export?format=json|markdown` — full world export (Guard: author)
  - JSON: all entities, relationships, timelines, arcs, notes in LoreBuilder schema
  - Markdown: one file per entity, front-matter metadata, linked relationships
- [ ] `POST /api/v1/worlds/:wid/import` — import JSON snapshot (Guard: owner; conflict resolution: skip | overwrite)
- [ ] `src/views/ExportView.vue` — format picker, download button, import dropzone

- [ ] `src/views/WorldMembersView.vue` — member table, role dropdowns, remove button, invite form
- [ ] `src/views/WorldInvitationsView.vue` — pending invites, resend, revoke

- [ ] Multi-timeline overlay in `TimelineView.vue` — toggle individual timelines, overlay rendering

- [ ] `scripts/wcsoz-sync.php` — CLI: map LoreBuilder entity schema → WCSOZ GDD schema; output JSON
- [ ] `scripts/export.php` — CLI wrapper around the export logic (same as API endpoint but for cron/backup)
- [ ] `scripts/consistency-check.php` — CLI: run consistency check and write findings to `storage/logs/`
- [ ] `scripts/rekey.php` — re-encrypt all ai_key_enc values after APP_SECRET rotation

- [ ] `GET /api/v1/auth/totp/oauth-placeholder` → `501 Not Implemented` (Anthropic OAuth mode C)

---

## Cross-Phase: Security & Quality Gates

These apply throughout all phases and must pass before any phase is considered done.

- [ ] `/project:security-review` run on every controller before merge
- [ ] All controllers pass checklist in CLAUDE.md §12:
  - Auth checked · World access checked · Input validated · Prepared statements · Output encoded · CSRF verified · Rate limiting · Audit log
- [ ] `SECURITY_FINDINGS.md` kept current — no unfixed HIGH or CRITICAL findings open
- [ ] `npm audit` clean on frontend before each phase ship
- [ ] Response schema consistent — all endpoints conform to `docs/api-contract.md`

---

## Dependency Order (critical path)

```
DB → Auth → Guard → Crypto → RateLimit → Validator → Router → index.php
     └── AuthController
         └── WorldController → EntityController → RelationshipController
                                └── NoteController
                                    └── AiController (needs Claude.php)
                                        └── ExportController

Frontend scaffold → auth views → entity views → AI panel → graph/timeline views
```

---

*Plan derived from docs/design-document.md v1.0 — update this file as phases complete.*
