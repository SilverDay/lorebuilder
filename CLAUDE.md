# LoreBuilder — Claude Code Project Constitution
# Version: 1.0 | SilverDay Media
# Stack: PHP 8.3 / MariaDB 10.11 / Apache 2.4 / Vue 3 + Vite

---

## 1. Project Identity

LoreBuilder is a **multi-tenant, multi-user, self-hostable world-building platform** for authors.
It manages fictional worlds as a relational graph (entities, relationships, timelines, story arcs)
and integrates Claude as an AI narrative assistant.

**Security and usability are co-equal first-class requirements.** Never sacrifice one for the other
without an explicit architect decision logged in DECISIONS.md.

---

## 2. Absolute Rules (Never Violate)

### 2.1 Security Non-Negotiables
- NEVER interpolate user input into SQL. PDO prepared statements ONLY.
- NEVER log, return, or expose API keys (Anthropic or otherwise) to the client.
- NEVER store plaintext passwords. bcrypt via password_hash(PASSWORD_BCRYPT, ['cost'=>12]).
- NEVER trust `$_SERVER['HTTP_HOST']` for security decisions. Use configured APP_URL.
- NEVER output user content without htmlspecialchars() or equivalent encoding.
- NEVER skip CSRF token verification on state-changing endpoints (POST/PATCH/DELETE/PUT).
- NEVER allow cross-tenant data access. Every query involving user data MUST include world_id
  scoping that is validated against the authenticated user's membership.
- NEVER store Anthropic API keys in plaintext. Encrypt with libsodium before DB write.
- ALL file uploads go to a directory outside web root. Validate MIME type server-side.
- Rate-limit ALL AI endpoints: per-user and per-IP.

### 2.2 Code Quality Non-Negotiables
- PHP: strict_types=1 on every file. No `@` error suppression.
- PHP: return types declared on all functions. No mixed unless unavoidable.
- Vue: Composition API + <script setup> only. No Options API.
- No framework on the PHP side. Custom router only (see core/Router.php pattern).
- All DB access through DB::query() wrapper — never raw PDO calls in controllers.
- Every public API endpoint must validate auth AND authorisation before touching data.

---

## 3. Architecture Overview

```
lorebuilder/
├── public/              # Apache DocumentRoot (ONLY this is web-accessible)
│   ├── index.php        # API bootstrap + SPA fallback
│   ├── assets/          # Vite-compiled JS/CSS (cache-busted)
│   └── .htaccess        # Routing rules
├── core/                # PHP framework (outside web root)
│   ├── Router.php       # Method+path dispatcher
│   ├── DB.php           # PDO wrapper, query logging
│   ├── Auth.php         # Session, CSRF, TOTP
│   ├── Guard.php        # Authorisation (world membership checks)
│   ├── Crypto.php       # libsodium key encryption/decryption
│   ├── RateLimit.php    # Token-bucket rate limiter (DB-backed)
│   └── Claude.php       # Anthropic API client + context assembler
├── api/                 # REST controllers (one file per resource group)
│   ├── AuthController.php
│   ├── WorldController.php
│   ├── EntityController.php
│   ├── RelationshipController.php
│   ├── TimelineController.php
│   ├── StoryArcController.php
│   ├── NoteController.php
│   ├── AiController.php
│   ├── UserController.php
│   └── ExportController.php
├── frontend/            # Vue 3 SPA source
│   ├── src/
│   │   ├── main.js
│   │   ├── router/index.js
│   │   ├── stores/        # Pinia stores
│   │   ├── views/         # Route-level components
│   │   ├── components/    # Reusable UI components
│   │   ├── composables/   # Shared Composition API logic
│   │   └── api/           # Typed API client (fetch wrappers)
│   ├── vite.config.js
│   └── package.json
├── migrations/          # Numbered SQL files (001_initial.sql, 002_*.sql …)
├── scripts/             # CLI tools (not web-accessible)
│   ├── migrate.php
│   ├── create-user.php
│   ├── export.php
│   └── consistency-check.php
├── config/
│   ├── config.example.php  # Committed template
│   └── config.php          # NEVER committed (in .gitignore)
├── storage/             # Runtime files (outside web root)
│   ├── uploads/         # User file attachments
│   ├── logs/            # App + audit logs
│   └── backups/         # mysqldump outputs
├── docs/                # Architecture docs (this package)
├── CLAUDE.md            # This file
├── DECISIONS.md         # Architecture decision log
└── SECURITY.md          # Threat model and controls
```

---

## 4. Multi-Tenancy Model

### 4.1 Isolation Unit
The isolation unit is a **World**. Every user creates or joins one or more worlds.
All lore data (entities, notes, timelines, arcs) belongs to a world.

### 4.2 World Membership Roles
| Role        | Permissions                                              |
|-------------|----------------------------------------------------------|
| owner       | Full CRUD, user management, billing/key settings         |
| admin       | Full CRUD, no user management or billing                 |
| author      | Create/edit own entities and notes; read all             |
| reviewer    | Read all, comment only                                   |
| viewer      | Read-only                                                |

### 4.3 Authorisation Pattern (MANDATORY)
Every controller that touches world-scoped data MUST call:
```php
Guard::requireWorldAccess($worldId, $userId, minRole: 'author');
```
This throws a 403 if the user is not a member of that world with the required role.
Guard checks both world_members and the user's active session. No exceptions.

---

## 5. Authentication & Session Management

- Sessions: PHP sessions with session_regenerate_id() on login. HttpOnly, SameSite=Strict cookie.
- CSRF: Double-submit token stored in session, validated on all state-changing requests.
  Frontend must send X-CSRF-Token header. Backend rejects without it.
- TOTP: Optional per-user 2FA using TOTP (libsodium HMAC). Enforced if world owner enables it.
- Password policy: min 12 chars, complexity enforced server-side. bcrypt cost 12.
- Failed login: exponential backoff + lockout after 10 attempts (per IP + per username).
- Session lifetime: configurable; default 8h idle, 30d with "remember me" (separate long-lived token).

---

## 6. Anthropic API Key Handling

### 6.1 Three Modes (configured per world)

**Mode A — User-Provided Key (Phase 1)**
User enters their Anthropic API key in world settings.
Key is encrypted with libsodium secretbox using APP_SECRET before storage in DB.
Key is decrypted server-side only at the moment of API call.
Key is NEVER returned to the client in any response.
Key is NEVER logged. Only key fingerprint (first 8 chars + "…") stored for display.

**Mode B — Platform Key with Budget (Phase 1, optional)**
World owner allocates a token budget from the platform operator's key.
Operator configures PLATFORM_ANTHROPIC_KEY in config.php (not in DB).
Per-world budgets enforced via token counters in world_ai_budgets table.
Suitable for offering a hosted "try it" tier.

**Mode C — OAuth / Claude.ai Account Link (Phase 2 placeholder)**
Schema includes oauth_providers table and users.oauth_anthropic_token column.
No implementation in Phase 1; placeholder endpoints return 501 Not Implemented.
Design reserves the slot so Phase 2 addition requires no schema migration.

### 6.2 Key Encryption (Crypto.php)
```php
// Encrypt before DB write
$encrypted = Crypto::encryptApiKey($plaintextKey, APP_SECRET);

// Decrypt at call time only
$key = Crypto::decryptApiKey($encryptedKey, APP_SECRET);

// Never do this:
// return json_encode(['key' => $key]); // FORBIDDEN
```

---

## 7. AI Integration Architecture

### 7.1 Request Flow
```
User action in Vue SPA
  → POST /api/v1/worlds/{wid}/ai/assist
  → Auth::requireSession()
  → Guard::requireWorldAccess($wid, $userId, 'author')
  → RateLimit::check('ai', $userId, limit: 20, window: 3600)
  → AiController::assist()
  → Claude::buildContext($entityId, $worldId, $mode)  // assembles prompt
  → Claude::callApi($context, $userPrompt, $apiKey)   // decrypts key, calls Anthropic
  → Save to ai_sessions (prompt_tokens, completion_tokens, model, created_at)
  → Save response to lore_notes (ai_generated=1)
  → Return response to client (NEVER return api_key)
```

### 7.2 Context Assembly Priority (token budget management)
When context exceeds token limit, drop in this order (last dropped first):
1. World config (genre, tone, era system, content warnings) — NEVER drop
2. Target entity (name, type, attributes, status) — NEVER drop
3. First-degree relationships (summarised) — drop after 80% budget
4. Story arc membership + arc logline — drop after 85% budget
5. Timeline position (before/after events) — drop after 88% budget
6. Lore notes for entity (newest first, oldest dropped) — trim from 60% budget
7. Related entity attributes (summaries) — drop after 92% budget

### 7.3 Prompt Template Variables
Templates use {{variable}} syntax, resolved by Claude::renderTemplate():
- {{world.name}}, {{world.genre}}, {{world.tone}}, {{world.era_system}}
- {{entity.name}}, {{entity.type}}, {{entity.status}}, {{entity.attributes}}
- {{entity.relationships}} — formatted list of typed relationships
- {{entity.notes}} — recent lore notes (token-budget-limited)
- {{entity.timeline_position}} — era/position label
- {{entity.arcs}} — arc names and roles
- {{user.name}} — for personalised prompts

---

## 8. Database Conventions

- All tables use snake_case.
- All primary keys: BIGINT UNSIGNED AUTO_INCREMENT, named `id`.
- All foreign keys: named `{table_singular}_id`.
- All timestamps: `created_at` and `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP.
- Soft deletes: `deleted_at` DATETIME NULL DEFAULT NULL. Queries MUST filter WHERE deleted_at IS NULL.
- All world-scoped tables have `world_id BIGINT UNSIGNED NOT NULL` with FK to worlds.id.
- No ORM. Raw SQL in DB::query() with named placeholders (:param style).
- Migrations are numbered (001_, 002_) and tracked in schema_migrations table.
- Never modify an existing migration. Add a new one.

---

## 9. API Response Conventions

```json
// Success
{ "data": { ... }, "meta": { "total": 42, "page": 1 } }

// Created
HTTP 201
{ "data": { "id": 123 } }

// Error
HTTP 4xx/5xx
{ "error": "Human-readable message", "code": "MACHINE_CODE", "field": "optional" }
```

Error codes (machine-readable):
- AUTH_REQUIRED, AUTH_INVALID, AUTH_TOTP_REQUIRED
- FORBIDDEN, NOT_FOUND, CONFLICT
- VALIDATION_ERROR (+ field)
- RATE_LIMITED (+ retry_after seconds)
- AI_KEY_MISSING, AI_KEY_INVALID, AI_BUDGET_EXCEEDED
- INTERNAL_ERROR (never expose stack traces)

---

## 10. Frontend Conventions

### 10.1 API Client
All backend calls go through `src/api/client.js` which:
- Attaches X-CSRF-Token header automatically
- Handles 401 → redirect to login
- Handles 429 → show rate limit toast with retry_after countdown
- Never stores API keys in localStorage or sessionStorage

### 10.2 Sensitive Data
- API keys are NEVER stored client-side. Not in Pinia, not in localStorage, not in cookies.
- After a user saves their API key, the server confirms with { "saved": true, "fingerprint": "sk-ant-…" }
- The frontend only ever sees the fingerprint, never the full key.

### 10.3 Component Structure
- Views (route-level): `src/views/` — one file per route
- Shared components: `src/components/` — PascalCase filenames
- AI-specific: `src/components/ai/` — AiPanel.vue, AiResponseCard.vue, AiPromptEditor.vue
- Graph: `src/components/graph/` — EntityGraph.vue (vis-network wrapper)
- Timeline: `src/components/timeline/` — WorldTimeline.vue (vis-timeline wrapper)

---

## 11. Security Findings Log

Security issues found during development are logged to `SECURITY_FINDINGS.md`:

```
[FINDING-001]
Date: YYYY-MM-DD
Severity: CRITICAL | HIGH | MEDIUM | LOW
File: path/to/file.php
Line: N
Description: What the issue is
Fix: What was done
Status: OPEN | RESOLVED
```

Claude Code must append to this file whenever a security issue is identified,
even if fixed immediately. Never silently fix without logging.

---

## 12. What Claude Code Should Always Do

1. Run the security checklist before completing any controller or API endpoint:
   - [ ] Auth checked?
   - [ ] World membership / role checked?
   - [ ] Input validated and sanitised?
   - [ ] Prepared statements used?
   - [ ] Output encoded?
   - [ ] CSRF token verified (if state-changing)?
   - [ ] Rate limiting applied (if AI endpoint)?
   - [ ] Audit log entry written?

2. After writing any code that handles API keys, confirm:
   - [ ] Key encrypted before storage?
   - [ ] Key never returned in any response?
   - [ ] Key never logged?

3. When uncertain about a design decision, append to DECISIONS.md and ask.

4. When adding a new DB table, add migration file before writing PHP code.

5. Use `/project:security-review` slash command before marking any task done.
