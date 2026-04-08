# Feature Document — Missing Features Audit

**Date:** 2026-04-07  
**Status:** Proposed  
**Priority labels:** 🔴 HIGH · 🟠 MEDIUM · 🟡 LOW

---

## Overview

Systematic audit of LoreBuilder identified three categories of missing features:
user-facing usability gaps, discoverability improvements, and polish items.
Each feature below specifies what exists, what's missing, the implementation
approach, and the backend/frontend coupling points.

---

## Feature 1 — World Settings View

**Priority:** 🔴 HIGH  
**Effort:** ~2 hours  
**Why:** Genre, tone, era system, and content warnings can only be set during
world creation. After that they are invisible and uneditable from the UI, even
though the backend PATCH endpoint already accepts all of them.

### Current state

| Layer | Status |
|-------|--------|
| DB schema | `worlds` table has `genre`, `tone`, `era_system`, `content_warnings`, `status` columns |
| Backend | `WorldController::update()` accepts all via `PATCH /api/v1/worlds/:wid` (requires admin role) |
| Frontend route | **Missing** — no `/worlds/:wid/settings/general` route |
| Frontend view | **Missing** — no `WorldSettingsView.vue` |
| Dashboard link | **Missing** — no "World Settings" card or header link |

### What was set at creation (WorldCreateView.vue)

- `name` (required)
- `slug` (required, immutable after creation)
- `description` (optional)
- `genre` (optional)
- `tone` (optional)

### What is NOT settable anywhere in UI

- `era_system` — no input in create or edit
- `content_warnings` — no input in create or edit
- `status` — no archive/activate toggle
- `description` — no edit after creation (only create form has it)
- `name` — no rename after creation

### Implementation plan

1. **Create `WorldSettingsView.vue`** at `frontend/src/views/WorldSettingsView.vue`
   - Load world via `GET /api/v1/worlds/:wid`
   - Form fields: name, description, genre, tone, era_system, content_warnings
   - Status toggle: active / archived (dropdown or toggle, admin only)
   - Display slug (read-only, shown for reference)
   - Submit via `PATCH /api/v1/worlds/:wid`
   - Show success toast on save
   - Use existing `.settings-section` / `.settings-form` CSS classes

2. **Register route** in `frontend/src/router/index.js`:
   ```js
   { path: '/worlds/:wid/settings/general', component: WorldSettingsView }
   ```

3. **Add dashboard link** — two options:
   - Add "World Settings" header button alongside AI Settings, Members, Export
   - Alternatively, add nav card with ⚙ icon

4. **No backend changes needed** — PATCH endpoint already validates and accepts
   all fields. Guard requires `admin` role.

### Security checklist
- [x] Auth checked (inherited from PATCH endpoint)
- [x] World access checked (Guard: admin)
- [x] Input validated (Validator::parseJson in WorldController)
- [x] Prepared statements (DB::execute)
- [x] CSRF verified (PATCH route has csrf: true)
- [x] Audit log written (WorldController::audit)

---

## Feature 2 — User Profile / Account Settings

**Priority:** 🔴 HIGH  
**Effort:** ~4 hours  
**Why:** Users cannot change their display name or email. No central "My Account"
page exists — password change and 2FA setup are orphaned at `/account/password`
and `/account/2fa` with no parent view linking them together.

### Current state

| Layer | Status |
|-------|--------|
| DB schema | `users` table has `display_name`, `email`, `email_verified`, `email_verify_token` |
| Backend controller | **Missing** — no `UserController.php` |
| Backend routes | Only auth-related: `/auth/password/change`, `/auth/totp/*` |
| Frontend route | `/account/password` → ChangePasswordView, `/account/2fa` → TwoFactorView |
| Frontend view | **Missing** — no `AccountSettingsView.vue` |
| Nav link | **Missing** — no "My Account" or user avatar/menu anywhere |

### Implementation plan

#### Backend: `api/UserController.php`

1. **`GET /api/v1/users/me`** — return profile
   - Returns: `id`, `username`, `display_name`, `email`, `email_verified`,
     `totp_enabled`, `created_at`
   - Never return: `password_hash`, `totp_secret_enc`, tokens

2. **`PATCH /api/v1/users/me`** — update profile
   - Accepts: `display_name` (string, max 128)
   - Rate limited: 10 per 15 min
   - Audit log: `user.profile.update`

3. **`POST /api/v1/users/me/email`** — initiate email change
   - Accepts: `new_email` (valid email, unique), `password` (current password)
   - Generates verification token, sends email to NEW address
   - Does NOT change email until verified
   - Audit log: `user.email.change_requested`

4. **`POST /api/v1/users/me/email/verify`** — confirm email change
   - Accepts: `token`
   - Updates `email`, sets `email_verified = 1`, clears token
   - Audit log: `user.email.verified`

5. **`DELETE /api/v1/users/me`** — account deletion
   - Requires: `password` (current password confirmation)
   - Soft deletes user (`deleted_at = NOW()`)
   - Removes from all world memberships
   - Destroys session
   - Audit log: `user.delete`

#### Frontend: `AccountSettingsView.vue`

- Route: `/account/settings`
- Sections:
  1. **Profile** — display name edit, email display + change button
  2. **Security** — links to Change Password and 2FA Setup (existing views)
  3. **Danger Zone** — account deletion with confirmation modal
- Load profile via `GET /api/v1/users/me`
- Nav link: add to each page header or create a persistent user menu

#### Routes to add

```
GET    /api/v1/users/me              → UserController::profile
PATCH  /api/v1/users/me              → UserController::updateProfile
POST   /api/v1/users/me/email        → UserController::changeEmail
POST   /api/v1/users/me/email/verify → UserController::verifyEmail
DELETE /api/v1/users/me              → UserController::deleteAccount
```

### Security checklist
- [ ] Auth checked on all endpoints
- [ ] Password required for email change and account deletion
- [ ] Email uniqueness enforced
- [ ] Rate limiting on profile updates and email changes
- [ ] Verification token: 128-bit entropy, hashed in DB, expiry enforced
- [ ] Never return password_hash or totp_secret_enc
- [ ] CSRF verified on all state-changing endpoints
- [ ] Audit log written

### DB migration needed
- `email_change_token VARCHAR(64)` — or reuse `email_verify_token`
- `email_change_pending VARCHAR(254)` — holds new email until verified
- Optional: can reuse existing `email_verify_token` column if only one
  verification flow runs at a time

---

## Feature 3 — Trash / Restore Deleted Items

**Priority:** 🟠 MEDIUM-HIGH  
**Effort:** ~3 hours  
**Why:** Soft delete is implemented everywhere (entities, relationships, notes,
arcs, timelines). But deleted items are invisible — no way to view or recover
them. A single accidental delete is currently permanent from the user's
perspective.

### Current state

| Layer | Status |
|-------|--------|
| DB schema | All content tables have `deleted_at DATETIME NULL DEFAULT NULL` |
| Backend queries | All filter `WHERE deleted_at IS NULL` — correct |
| Restore API | **Missing** — no endpoint to set `deleted_at = NULL` |
| Trash view | **Missing** — no frontend view to browse deleted items |
| Undo UX | **Missing** — deletes happen with confirm dialog but no undo |

### Scope

Phase 1: entity trash only (most valuable). Relationships, notes, arcs, and
timelines cascade or are secondary — entity restore is the critical path.

### Implementation plan

#### Backend: `api/EntityController.php`

1. **`GET /api/v1/worlds/:wid/entities/trash`** — list soft-deleted entities
   - Guard: admin role (trash is an admin concern)
   - Query: `SELECT ... FROM entities WHERE world_id = :wid AND deleted_at IS NOT NULL`
   - Paginated, sortable by `deleted_at DESC`
   - Return: `id`, `name`, `type`, `status`, `deleted_at`

2. **`POST /api/v1/worlds/:wid/entities/:id/restore`** — restore entity
   - Guard: admin role
   - Sets `deleted_at = NULL`
   - Audit log: `entity.restore`
   - Optionally restore cascade: notes, relationships where both endpoints alive

3. **`DELETE /api/v1/worlds/:wid/entities/:id/permanent`** — hard delete (future)
   - Guard: owner role only
   - Actually removes row + cascaded data
   - Deferred — soft delete + restore is sufficient for now

#### Frontend: `EntityTrashView.vue`

- Route: `/worlds/:wid/trash`
- Table: entity name, type, deleted date, "Restore" button per row
- Confirmation dialog on restore
- Empty state: "No deleted items."
- Dashboard link: add "Trash" card with 🗑 icon (only shown to admin+ roles)

#### Route registration

```
GET  /api/v1/worlds/:wid/entities/trash     → EntityController::trash
POST /api/v1/worlds/:wid/entities/:id/restore → EntityController::restore
```

**Important:** Register the `/trash` route BEFORE the `/:id` catch-all in
`index.php` to avoid "trash" being parsed as an entity ID.

### Security checklist
- [ ] Auth checked
- [ ] World access checked (admin role for trash operations)
- [ ] Entity belongs to world (verified in query)
- [ ] CSRF verified on restore (POST)
- [ ] Audit log: entity.restore event

---

## Feature 4 — Global Search (Ctrl+K)

**Priority:** 🟠 MEDIUM  
**Effort:** ~2 hours  
**Why:** Search API exists and works (`EntityController::search` with FULLTEXT),
but it's only accessible from the entity list filter. A keyboard-triggered search
modal is a standard UX pattern that would dramatically improve navigation.

### Current state

| Layer | Status |
|-------|--------|
| Backend | `GET /api/v1/worlds/:wid/search?q=&type=&tag=` — works, FULLTEXT boolean mode |
| Frontend | `EntityListView.vue` has `filter.q` input field — search works there |
| Global shortcut | **Missing** |
| Search modal | **Missing** |

### Implementation plan

1. **Create `SearchModal.vue`** in `frontend/src/components/SearchModal.vue`
   - Overlay modal (z-index above everything)
   - Text input with debounced API call (300ms)
   - Results list: entity name + type badge + short_summary excerpt
   - Click result → navigate to `/worlds/:wid/entities/:id`
   - Esc or click backdrop → close
   - Show "No results" empty state

2. **Wire global keyboard shortcut in `App.vue`**
   ```js
   window.addEventListener('keydown', (e) => {
     if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
       e.preventDefault()
       showSearch.value = true
     }
   })
   ```
   - Only active when user is authenticated and has a current world
   - Pass current world ID to SearchModal

3. **Search scope**: current world only (matches existing API)

4. **No backend changes needed** — existing search endpoint is sufficient

### Technical notes
- Debounce input to avoid excessive API calls
- Minimum 2 characters before searching
- Cache most recent results briefly to avoid re-fetching on typo correction
- Consider `<Teleport to="body">` for portal rendering of the modal

---

## Feature 5 — Light Mode / Theme Toggle

**Priority:** 🟡 LOW  
**Effort:** ~1.5 hours  
**Why:** The app is currently dark-mode only. CSS variables are already used
throughout (`--color-bg`, `--color-surface`, etc.), making a light theme a
straightforward variable swap. The dark palette is the default and should remain
the primary theme.

### Current state

| Layer | Status |
|-------|--------|
| CSS variables | Complete set defined in `:root` — 14 colour tokens + shadows + radius |
| Media query | **Missing** — no `prefers-color-scheme` support |
| Theme toggle | **Missing** — no UI control |
| Persistence | **Missing** — no localStorage theme setting |

### Implementation plan

1. **Add light theme variables** in `style.css`:
   ```css
   :root[data-theme="light"] {
     --color-bg: #ffffff;
     --color-surface: #f6f8fa;
     --color-surface2: #eaeef2;
     --color-border: #d0d7de;
     --color-text: #1f2328;
     --color-muted: #656d76;
     /* accent, cta, danger, success, warning stay the same or darken slightly */
   }
   ```

2. **Theme toggle composable** — `frontend/src/composables/useTheme.js`
   - Read from `localStorage.getItem('theme')` on init
   - Options: `'light'`, `'dark'`, `'system'` (default: `'system'`)
   - System follows `prefers-color-scheme` media query
   - Sets `document.documentElement.dataset.theme` attribute

3. **Toggle button** — small icon button in DashboardView header or a
   persistent element. Sun/moon icon swap.

4. **No backend changes needed**

---

## Feature 6 — Accessibility Improvements

**Priority:** 🟡 LOW  
**Effort:** ~2 hours (spread across multiple files)  
**Why:** Core ARIA patterns are present (toasts, AI panel, error alerts) but
several interactive elements lack proper labels — particularly icon-only
buttons, filter selects, and the graph/timeline views.

### Areas to address

| Component | Issue | Fix |
|-----------|-------|-----|
| DashboardView nav cards | Icon-only labels (◈, ⬡, ◷) — screen readers read Unicode symbols | Add `aria-label` to each card |
| EntityListView filters | Type/status selects missing labels | Add `aria-label` to `<select>` |
| EntityDetailView | Form inputs in inline edit lack labels | Wrap in `<label>` or add `aria-label` |
| GraphView | Canvas-based vis-network has no ARIA | Add `role="img"` + `aria-label` to container |
| TimelineView | Same as GraphView | Add `role="img"` + `aria-label` |
| StoryArcKanban | Drag handles lack labels | Add `aria-label="Drag to change status"` |
| Icon-only buttons | Edit (✎), Delete (✕), Restore, etc. | Add `aria-label` to all |

### Implementation approach
- Single pass through all views adding `aria-label` attributes
- No structural changes — just attribute additions
- No backend changes

---

## Implementation Priority Order

| Order | Feature | Effort | Backend changes |
|-------|---------|--------|-----------------|
| 1 | World Settings View | ~2h | None (API exists) |
| 2 | Trash / Restore | ~3h | 2 new endpoints |
| 3 | User Profile / Account | ~4h | New controller + 5 endpoints + migration |
| 4 | Global Search | ~2h | None (API exists) |
| 5 | Accessibility | ~2h | None |
| 6 | Light Mode | ~1.5h | None |

Features 1, 4, 5, and 6 require zero backend changes — the APIs already exist
or are not needed. Feature 2 needs two new endpoints in an existing controller.
Feature 3 is the most complex (new controller + migration), but has the highest
user impact alongside Feature 1.

---

## Feature 7 — AI Image Prompt Generation for Entities

**Priority:** 🟠 MEDIUM  
**Effort:** TBD  
**Why:** Authors building worlds often need visual references for characters,
locations, creatures, and items. Instead of generating images directly (which
requires image-generation API keys and has cost/moderation concerns), LoreBuilder
can leverage the existing AI text engine to craft detailed image prompts that
users can paste into their preferred image generator (Midjourney, DALL-E,
Stable Diffusion, Flux, etc.).

### Concept

A new AI action mode — "Generate Image Prompt" — that takes an entity's
attributes, relationships, and lore notes and produces a richly detailed visual
description formatted as an image-generation prompt. The prompt should include:

- Physical appearance / visual characteristics drawn from entity attributes
- Setting/environment context from world genre, tone, and era
- Art style suggestions based on world tone (e.g. "oil painting" for epic fantasy,
  "concept art" for sci-fi)
- Negative prompt suggestions (common artefacts to exclude)

### What exists today

| Layer | Status |
|-------|--------|
| AI context assembly | `AiEngine::buildContext()` already gathers entity + world data |
| AI action modes | `assist` mode exists; new modes can be added |
| Prompt templates | `AiEngine::renderTemplate()` with `{{variable}}` syntax |
| Entity attributes | Full attribute JSON stored per entity |
| Frontend AI panel | `AiPanel.vue` sends requests to `/api/v1/worlds/:wid/ai/assist` |

### Implementation sketch

#### Backend
- Add a new prompt template for image prompt generation (or a `mode` parameter
  on the existing assist endpoint, e.g. `mode: 'image_prompt'`)
- Template emphasises visual/physical descriptors, art style, composition
- Context assembly prioritises: entity attributes, type, relationships that
  imply visual traits (e.g. "wears the Crown of Ashes"), world genre/tone

#### Frontend
- Add "Generate Image Prompt" button/option in entity detail or AI panel
- Display the generated prompt in a copyable text block
- Optional: style/format selector (Midjourney v6, DALL-E 3, SD XL, generic)

### Open questions
- Should the prompt be saved as a lore note (ai_generated=1, tagged "image_prompt")?
- Should there be a gallery view collecting all generated image prompts?
- Should we support multiple prompt styles per generator?
- Future: direct image generation via DALL-E / Stability API integration?
