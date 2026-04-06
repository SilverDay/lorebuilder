# LoreBuilder — Implementation Plan
**Last updated:** 2026-04-06 (session 5)  
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

- [x] `POST /api/v1/auth/register` — create user (REGISTRATION_OPEN gate, email uniqueness, bcrypt, send verify email)
- [x] `POST /api/v1/auth/login` — RateLimit::checkLogin, verify password, Auth::login, TOTP step if enabled
- [x] `POST /api/v1/auth/totp/verify` — verify code, Auth::markTotpVerified
- [x] `POST /api/v1/auth/logout` — Auth::logout
- [x] `GET  /api/v1/auth/me` — return session user (id, username, display_name, totp_enabled)
- [x] `POST /api/v1/auth/password/reset-request` — generate token, store hash, send email
- [x] `POST /api/v1/auth/password/reset` — consume token, hash new password
- [x] `POST /api/v1/auth/totp/setup` — generate secret, return URI for QR; not active until confirmed
- [x] `POST /api/v1/auth/totp/confirm` — verify first code, write encrypted secret to DB, enable TOTP
- [x] `DELETE /api/v1/auth/totp` — disable TOTP (requires current password + valid TOTP code)

### 1C · World & Membership API (`api/WorldController.php`)

- [x] `GET  /api/v1/worlds` — list worlds the user is a member of
- [x] `POST /api/v1/worlds` — create world (user becomes owner, auto-joined as owner in world_members)
- [x] `GET  /api/v1/worlds/:wid` — world detail (Guard: viewer)
- [x] `PATCH /api/v1/worlds/:wid` — update world metadata (Guard: admin)
- [x] `DELETE /api/v1/worlds/:wid` — soft delete (Guard: owner only)
- [x] `GET  /api/v1/worlds/:wid/members` — list members (Guard: viewer)
- [x] `PATCH /api/v1/worlds/:wid/members/:uid` — change role (Guard: owner for admin promotions; admin for others)
- [x] `DELETE /api/v1/worlds/:wid/members/:uid` — remove member (Guard: admin; owner cannot be removed)

### 1D · Entity CRUD API (`api/EntityController.php`)

- [x] `GET    /api/v1/worlds/:wid/entities` — paginated list; filter by type, status, tag
- [x] `POST   /api/v1/worlds/:wid/entities` — create entity (Guard: author); write audit_log
- [x] `GET    /api/v1/worlds/:wid/entities/:id` — entity detail with attributes + relationships + notes
- [x] `PATCH  /api/v1/worlds/:wid/entities/:id` — update (Guard: author + requireOwnerOrRole); write audit_log diff
- [x] `DELETE /api/v1/worlds/:wid/entities/:id` — soft delete (Guard: author + requireOwnerOrRole)
- [x] `GET    /api/v1/worlds/:wid/entities/:id/attributes` — list typed attributes
- [x] `PUT    /api/v1/worlds/:wid/entities/:id/attributes` — replace full attribute set (Guard: author)
- [x] `GET    /api/v1/worlds/:wid/entities/:id/tags` — list tags on entity
- [x] `PUT    /api/v1/worlds/:wid/entities/:id/tags` — replace tag set (Guard: author)

### 1E · Tag API (`api/EntityController.php`, tag sub-resource)

- [x] `GET    /api/v1/worlds/:wid/tags` — list all tags in world
- [x] `POST   /api/v1/worlds/:wid/tags` — create tag (Guard: author)
- [x] `PATCH  /api/v1/worlds/:wid/tags/:tid` — rename / recolour (Guard: admin)
- [x] `DELETE /api/v1/worlds/:wid/tags/:tid` — delete tag (Guard: admin; cascades via FK)

### 1F · Relationship API (`api/RelationshipController.php`)

- [x] `GET    /api/v1/worlds/:wid/relationships` — all relationships; filter by from_entity, to_entity, rel_type
- [x] `POST   /api/v1/worlds/:wid/relationships` — create relationship (Guard: author; both entities must belong to world)
- [x] `PATCH  /api/v1/worlds/:wid/relationships/:id` — update rel_type, strength, notes, bidirectional
- [x] `DELETE /api/v1/worlds/:wid/relationships/:id` — soft delete

### 1G · World AI Key Settings (`api/WorldController.php`)

- [x] `GET  /api/v1/worlds/:wid/settings/ai` — return ai_key_mode, fingerprint, budget stats (Guard: owner)
- [x] `PUT  /api/v1/worlds/:wid/settings/ai/key` — accept plaintext key, encrypt, store fingerprint (Guard: owner)
- [x] `DELETE /api/v1/worlds/:wid/settings/ai/key` — remove key (Guard: owner)

---

## Phase 2 — Narrative Structure

Goal: timelines, story arcs, lore notes, full-text search, tags filterable in UI.

### 2A · Core layer additions

- [x] `core/Claude.php` — context assembler + Anthropic API client  
  (Moved here from Phase 4 because context assembly is shared infrastructure)
  - `Claude::buildContext(int $entityId, int $worldId, string $mode): array`
  - `Claude::callApi(array $context, string $userPrompt, string $apiKey): array`
  - `Claude::renderTemplate(string $tpl, array $vars): string`
  - `Claude::loadTemplate(string $mode, int $worldId): ?array`
  - `Claude::resolveApiKey(int $worldId): string`
  - Context budget logic per design-document §7.4
  - Never logs apiKey; logs only session metadata

### 2B · Timeline API (`api/TimelineController.php`)

- [x] `GET    /api/v1/worlds/:wid/timelines` — list timelines
- [x] `POST   /api/v1/worlds/:wid/timelines` — create timeline (Guard: author)
- [x] `GET    /api/v1/worlds/:wid/timelines/:tid` — timeline + events
- [x] `PATCH  /api/v1/worlds/:wid/timelines/:tid` — update metadata
- [x] `DELETE /api/v1/worlds/:wid/timelines/:tid` — soft delete
- [x] `GET    /api/v1/worlds/:wid/timelines/:tid/events` — list events (ordered by position_order)
- [x] `POST   /api/v1/worlds/:wid/timelines/:tid/events` — create event
- [x] `PATCH  /api/v1/worlds/:wid/timelines/:tid/events/:eid` — update event
- [x] `DELETE /api/v1/worlds/:wid/timelines/:tid/events/:eid` — soft delete
- [x] `PUT    /api/v1/worlds/:wid/timelines/:tid/events/reorder` — bulk position_order update

### 2C · Story Arc API (`api/StoryArcController.php`)

- [x] `GET    /api/v1/worlds/:wid/story-arcs` — list arcs; filter by status
- [x] `POST   /api/v1/worlds/:wid/story-arcs` — create arc
- [x] `GET    /api/v1/worlds/:wid/story-arcs/:aid` — arc detail + entities
- [x] `PATCH  /api/v1/worlds/:wid/story-arcs/:aid` — update (status, logline, theme, sort_order)
- [x] `DELETE /api/v1/worlds/:wid/story-arcs/:aid` — soft delete
- [x] `PUT    /api/v1/worlds/:wid/story-arcs/:aid/entities` — replace entity list in arc (Guard: author)

### 2D · Lore Notes API (`api/NoteController.php`)

- [x] `GET    /api/v1/worlds/:wid/notes` — world-level notes
- [x] `GET    /api/v1/worlds/:wid/entities/:id/notes` — entity notes (canonical + general)
- [x] `POST   /api/v1/worlds/:wid/entities/:id/notes` — create note (Guard: author)
- [x] `PATCH  /api/v1/worlds/:wid/notes/:nid` — edit note content
- [x] `DELETE /api/v1/worlds/:wid/notes/:nid` — soft delete
- [x] `POST   /api/v1/worlds/:wid/notes/:nid/promote` — mark is_canonical=1 (Guard: admin)

### 2E · Search API (`api/EntityController.php`)

- [x] `GET /api/v1/worlds/:wid/search?q=&type=&tag=` — MariaDB FULLTEXT search on entities
  - MATCH AGAINST with boolean mode
  - Filter by entity type and/or tag (joined)
  - Returns entity rows with relevance score

### 2F · Invitation API (`api/WorldController.php`)

- [x] `POST   /api/v1/worlds/:wid/invitations` — send email invite with token (Guard: admin)
- [x] `GET    /api/v1/invitations/:token` — validate invite (public; checks expiry)
- [x] `POST   /api/v1/invitations/:token/accept` — consume invite, create membership (authed user)

### 2G · Vue 3 SPA — Phase 2 scope

- [x] `frontend/` scaffold — Vite 7 config, `src/main.js`, Vue Router, Pinia stores
- [x] `src/api/client.js` — fetch wrapper (CSRF header auto-attach, 401 redirect, 429 toast)
- [x] `src/router/index.js` — route definitions with auth guard
- [x] `src/stores/auth.js` — session user state
- [x] `src/stores/world.js` — current world + membership cache
- [x] `src/stores/toast.js` — rate-limit toast notifications
- [x] `src/views/LoginView.vue`
- [x] `src/views/RegisterView.vue`
- [x] `src/views/WorldListView.vue`
- [x] `src/views/WorldCreateView.vue`
- [x] `src/views/EntityListView.vue` — filterable grid (type, status, tag)
- [x] `src/views/EntityDetailView.vue` — three-panel layout (meta | notes | relationships)
- [x] `src/views/EntityCreateView.vue` / `EntityEditView.vue`
- [x] `src/views/AcceptInvitationView.vue`
- [x] `src/components/EntityMeta.vue` — type badge, status, attributes table, tags
- [x] `src/components/NotesList.vue` — chronological Markdown notes (Marked.js + DOMPurify)
- [x] `src/components/RelationshipList.vue` — grouped by type, linked to counterpart entities
- [x] `src/components/ToastContainer.vue` — rate-limit + notification toasts
- [x] `GET /api/v1/auth/csrf` endpoint added to AuthController + router
- [x] `core/Router.php` `serveSpa()` updated to read Vite manifest for cache-busted asset paths
- [x] `npm audit` clean (upgraded to Vite 7 to resolve esbuild dev-server vuln)

---

## Phase 3 — Visualisation

Goal: graph view, dashboard, audit log viewer.

- [x] `GET /api/v1/worlds/:wid/graph` — nodes + edges JSON optimised for vis-network
  - Nodes: `{id, label, type, status}` — all non-deleted entities
  - Edges: `{from, to, label, strength}` — all non-deleted relationships
- [x] `GET /api/v1/worlds/:wid/audit-log` — paginated audit_log entries (Guard: admin)
- [x] `GET /api/v1/worlds/:wid/stats` — entity counts + arc summary + recent activity (Guard: viewer)
- [x] `src/views/GraphView.vue` — vis-network wrapper
  - Node colour by entity type; edge width by strength
  - Physics toggle; click node → EntityDetailView
  - Bidirectional edges with arrows on both ends
- [x] `src/views/TimelineView.vue` — vis-timeline wrapper
  - Timeline selector; loads events ordered by position_order
  - Groups by era when scale_mode = 'era'
  - Drag to reorder → PUT reorder endpoint
- [x] `src/views/StoryArcKanban.vue` — Kanban: seed → rising_action → climax → resolution → complete → abandoned
  - Drag arc cards between columns → PATCH status (native HTML5 DnD)
  - Optimistic update with revert on failure
- [x] `src/views/DashboardView.vue`
  - Entity counts by type (stat cards)
  - Arc status summary
  - Recent activity from audit_log (10 entries)
  - Quick-nav to graph, timeline, arcs, entity list
- [x] `src/views/AuditLogView.vue` — paginated change history table with expandable diff viewer
- [x] Also fixed: table name bugs in core/Claude.php (entity_relationships, arc_entities, position_label)
- [x] Security review passed — no issues found

---

## Phase 4 — Claude Integration

Goal: AI assistant callable from any entity; all invocation modes; budget enforcement.

### 4A · Backend

- [x] Complete `core/Claude.php`
- [x] `POST /api/v1/worlds/:wid/ai/assist` — dual rate limits, buildContext → callApi, writes ai_sessions + lore_notes, returns note_id
- [x] `POST /api/v1/worlds/:wid/ai/consistency-check`
- [x] `GET  /api/v1/worlds/:wid/ai/sessions` — paginated, filterable by entity_id
- [x] `GET  /api/v1/worlds/:wid/settings/ai/budget` — tokens used / limit / daily breakdown
- [x] `GET    /api/v1/worlds/:wid/prompt-templates`
- [x] `POST   /api/v1/worlds/:wid/prompt-templates`
- [x] `PATCH  /api/v1/worlds/:wid/prompt-templates/:id`
- [x] `DELETE /api/v1/worlds/:wid/prompt-templates/:id`

### 4B · Frontend

- [x] `src/components/ai/AiPanel.vue` — floating FAB + sliding drawer, mode selector, Ctrl+Enter send
- [x] `src/components/ai/AiResponseCard.vue` — Markdown via marked+DOMPurify, promote-to-canonical
- [x] `src/views/AiHistoryView.vue` — paginated session history table with expandable detail
- [x] `src/views/WorldAiSettingsView.vue` — key entry → PUT saveAiKey, fingerprint display, budget gauge + daily usage
- [x] `src/stores/ai.js` — Pinia store wrapping assist + consistencyCheck
- [x] AiPanel wired into EntityDetailView; routes added for AI settings + history
- [-] `src/components/ai/AiPromptEditor.vue` — deferred; AiPanel covers the use case

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
