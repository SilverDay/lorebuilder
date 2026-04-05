# LoreBuilder — Design & Architecture Document
**Version:** 1.0 — Draft  
**Author:** SilverDay Media  
**Stack:** LAMP (PHP 8.3 / MariaDB / Apache) + Vue 3  
**Status:** Design Phase  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Goals & Non-Goals](#2-goals--non-goals)
3. [System Architecture](#3-system-architecture)
4. [Data Model](#4-data-model)
5. [REST API Design](#5-rest-api-design)
6. [Frontend Architecture](#6-frontend-architecture-vue-3-spa)
7. [Claude AI Integration](#7-claude-ai-integration-crowning-feature)
8. [Feature Specifications](#8-feature-specifications)
9. [Security Design](#9-security-design)
10. [Phased Roadmap](#10-phased-roadmap)
11. [Technology Stack Summary](#11-technology-stack-summary)
12. [Constraints & Assumptions](#12-constraints--assumptions)

---

## 1. Executive Summary

LoreBuilder is a self-hosted, LAMP-based web application designed to serve as the single source of truth for complex world-building projects — starting with WCSOZ (Wizard's Castle: Shadows of Zot) and extensible to any narrative universe. It replaces fragmented notes, chat logs, and ad-hoc documents with a structured, relational lore database fronted by a clean Vue 3 SPA, enriched by AI-assisted narrative generation powered by Claude.

The core philosophy: every element of a fictional world is a node in a graph — characters, factions, locations, events, artefacts, lore entries, and timeline beats — connected by typed relationships. LoreBuilder makes those nodes and edges first-class citizens: queryable, visualisable, and narrated.

> **Crowning Feature:** Every entity in LoreBuilder can be sent to Claude with a contextual prompt. The AI can draft backstory, infer missing lore, suggest plot hooks, check consistency, generate NPC dialogue, or synthesise a story-arc overview — all inline in the UI. Results can be accepted, edited, or discarded without leaving the platform.

---

## 2. Goals & Non-Goals

### 2.1 Goals

- Provide a unified repository for all world-building artefacts of WCSOZ and future SilverDay projects.
- Model complex relationships between lore entities (characters know locations, factions control regions, events trigger consequences).
- Offer an interactive timeline view that places events in chronological and narrative order, supporting both in-world era systems and real-world dates.
- Surface consistency warnings when contradictory facts are detected.
- Integrate Claude as an in-context AI narrator and lore assistant, callable per-entity or globally.
- Support multiple authors collaborating on the same world with role-based access.
- Support multiple independent worlds per instance (multi-tenant).
- Remain fully self-hostable on a standard LAMP stack.
- Allow users to bring their own Anthropic API key or use a platform-provided budget.

### 2.2 Non-Goals (v1)

- Real-time collaborative editing (planned v2).
- Native mobile app — responsive web only.
- Full version control / git-style branching of lore (considered v2).
- Image generation — use externally generated assets as attachments.
- OAuth via Anthropic — schema placeholder reserved, implementation deferred to v2.

---

## 3. System Architecture

### 3.1 High-Level Overview

LoreBuilder uses a custom PHP 8.3 router (no framework) serving a REST API, consumed by a Vue 3 SPA, with MariaDB as the persistence layer. Vite is used locally for development; compiled assets are deployed statically to `public/assets/`.

| Layer | Technology | Responsibility |
|---|---|---|
| Presentation | Vue 3 + Vite + Pinia | SPA UI, routing, state management |
| API | PHP 8.3, custom router | REST endpoints, auth, Claude proxy |
| Persistence | MariaDB 10.11 | Relational lore graph, FTS |
| Auth | Session + TOTP (optional) | Per-user, role-based world access |
| AI Backend | Anthropic Claude API | Lore assistance, narration, consistency |
| Web Server | Apache 2.4 + mod_rewrite | Routing, TLS, static assets |

### 3.2 Module Map

```
api/          REST controllers, one file per resource group
core/         Router, DB wrapper, Auth, Guard, Crypto, RateLimit, Claude client
frontend/     Vue 3 SPA (src/), Vite config, compiled dist/
migrations/   Numbered SQL migration files
config/       config.php (gitignored), config.example.php
scripts/      CLI tools (migrate, export, consistency-check, rekey)
docs/         This document and companion specs
```

---

## 4. Data Model

### 4.1 Design Philosophy

The world is modelled as a labelled property graph stored relationally. The central pattern is **Entity → Relationship → Entity**. Every entity has a type, typed attributes, free-text lore fields, and zero-or-more typed relationships to other entities. All tables are scoped to a `world_id` for multi-tenant isolation.

### 4.2 Core Tables

| Table | Key Columns | Purpose |
|---|---|---|
| users | id, username, email, password_hash, totp_secret_enc | Platform user accounts |
| worlds | id, owner_id, slug, name, genre, tone, era_system, ai_key_mode, ai_key_enc | Tenant/project containers |
| world_members | world_id, user_id, role | Role-based world membership |
| entities | id, world_id, type, name, slug, status, lore_body | Master registry of all lore objects |
| entity_attributes | entity_id, attr_key, attr_value, data_type | Typed key-value bag per entity |
| entity_relationships | from_id, to_id, rel_type, strength, is_bidirectional | Directed typed edges |
| timelines | id, world_id, name, scale_mode, era_labels | Named chronological frameworks |
| timeline_events | timeline_id, entity_id, position_order, position_era, position_label | Places entities on a timeline |
| story_arcs | id, world_id, name, logline, theme, status, ai_synopsis | Narrative containers |
| arc_entities | arc_id, entity_id, role | Entity participation in an arc |
| lore_notes | id, entity_id, content, is_canonical, ai_generated, ai_session_id | Freeform + AI-generated notes |
| ai_sessions | id, world_id, user_id, mode, model, prompt_tokens, completion_tokens | Audit log of all Claude calls |
| prompt_templates | world_id, mode, system_tpl, user_tpl | Editable per-world AI prompt templates |
| audit_log | id, user_id, action, target_type, diff_json | Append-only change history |
| rate_limit_buckets | bucket_key, tokens, last_refill | Token-bucket rate limiter state |
| world_invitations | world_id, email, role, token, expires_at | Invite-by-email flow |
| oauth_providers | user_id, provider, access_token | Phase 2 placeholder (Anthropic OAuth) |

### 4.3 Entity Types

`entities.type` is an ENUM:

- **Character** — protagonists, NPCs, deities, historical figures
- **Location** — regions, dungeons, cities, planes of existence
- **Event** — battles, discoveries, prophecies, plot moments
- **Faction** — guilds, kingdoms, cults, organisations
- **Artefact** — items with narrative significance
- **Creature** — monster types, species, bestiaries
- **Concept** — magic systems, religions, languages, laws
- **StoryArc** — high-level narrative containers
- **Timeline** — chronological frameworks

### 4.4 Relationship Types (Seeded Vocabulary)

```
knows / allied_with / opposes / fears / loves / betrayed
rules / serves / member_of / founded / destroyed
located_in / guards / discovered / created / wields
caused / preceded / triggered / concurrent_with
protagonist_of / antagonist_of / mentor_of / foil_of
```

Custom types are also permitted (stored as free strings).

### 4.5 Timeline Scale Modes

`timelines.scale_mode` supports three modes to accommodate different project needs:

| Mode | Use Case | Position Storage |
|---|---|---|
| `era` | In-world era system (e.g. WCSOZ: Age of Zot / The Sundering) | `position_era` string + `position_order` integer |
| `numeric` | Ordinal or year-number systems | `position_value` DECIMAL |
| `date` | Real-world or ISO date systems | `position_value` as UNIX timestamp |

Multiple timelines can be overlaid in the UI to show parallel event threads.

---

## 5. REST API Design

### 5.1 Conventions

- Base path: `/api/v1/`
- Auth: session cookie (HttpOnly, SameSite=Strict) + X-CSRF-Token header
- Responses: JSON throughout
- Success: `{ "data": {...}, "meta": {"total": N, "page": N} }`
- Error: `{ "error": "Human message", "code": "MACHINE_CODE", "field": "optional" }`

### 5.2 Key Endpoint Groups

See `docs/api-contract.md` for the full endpoint list. Summary:

- `/auth/*` — register, login, logout, password reset, TOTP
- `/worlds/*` — world CRUD, membership, invitations, AI key settings
- `/worlds/{wid}/entities/*` — entity CRUD, tags, relationships
- `/worlds/{wid}/timelines/*` — timeline + event management, reorder
- `/worlds/{wid}/story-arcs/*` — arc CRUD, entity participation
- `/worlds/{wid}/notes/*` — lore notes, canonical promotion
- `/worlds/{wid}/ai/*` — assist, consistency check, session history
- `/worlds/{wid}/graph` — nodes + edges JSON for vis-network
- `/worlds/{wid}/search` — full-text search
- `/worlds/{wid}/export` — JSON or Markdown world export

---

## 6. Frontend Architecture (Vue 3 SPA)

### 6.1 Technology Choices

| Package | Purpose |
|---|---|
| Vue 3 (Composition API, script setup) | Reactive UI |
| Pinia | Global state (entity cache, AI state, UI prefs) |
| Vue Router | Client-side routing with auth guard |
| Vite | Dev server + production build |
| vis-network | Interactive relationship graph |
| vis-timeline | Chronological timeline component |
| Marked.js + DOMPurify | Markdown rendering (AI responses sanitised) |

No CSS framework — bespoke styles using CSS custom properties.

### 6.2 View Structure

| Route | Purpose |
|---|---|
| `/dashboard` | Stats, recent changes, AI suggestions, arc health |
| `/entities` | Filterable, searchable entity grid |
| `/entities/:id` | Three-panel detail: meta \| notes+AI \| relationships |
| `/graph` | Interactive relationship graph |
| `/timelines/:id` | Horizontal scrollable timeline; drag to reorder |
| `/story-arcs` | Kanban board: Seed → Rising Action → Climax → Resolution |
| `/search` | Full-text search results |
| `/ai/history` | Claude session audit with token counts |
| `/worlds/:slug/settings/ai` | API key management, budget dashboard |

### 6.3 Entity Detail Layout (Three-Panel)

- **Left** — Entity meta: type badge, status, tags, attributes table
- **Centre** — Lore notes (Markdown, chronological) with inline AI response cards
- **Right** — Relationship list (grouped by type), mini-graph preview, timeline position

A floating AI Assistant button opens a drawer at any time, pre-populated with the current entity context.

---

## 7. Claude AI Integration (Crowning Feature)

### 7.1 Architecture

All AI calls are proxied through the PHP backend. The Anthropic API key never touches the browser. This enables: audit logging, rate limiting, budget enforcement, and rich server-side context assembly.

### 7.2 API Key Modes

| Mode | Description | Phase |
|---|---|---|
| **A — User key** | User provides their own Anthropic API key; encrypted server-side with libsodium | Phase 1 |
| **B — Platform key** | Operator key in config.php; per-world token budgets enforced | Phase 1 (optional) |
| **C — OAuth** | Link Claude.ai account directly; schema placeholder reserved | Phase 2 |

Keys are encrypted with `sodium_crypto_secretbox` (XSalsa20-Poly1305) using `APP_SECRET` from `config.php`. Only a fingerprint (first 10 + last 4 characters) is ever returned to the client. Keys are decrypted in memory at call time and zeroed with `sodium_memzero()` immediately after the HTTP request is dispatched.

### 7.3 Invocation Modes

| Mode | Scope | Example |
|---|---|---|
| Entity Assist | Single entity | Write a tragic backstory for this character |
| Relationship Infer | Two entities | How might these two characters first meet? |
| Arc Synthesiser | Story arc | Write a one-page synopsis of this arc |
| Consistency Check | Full world | Find contradictions in timeline or character data |
| Timeline Narrator | Timeline | Narrate this era as an in-world historical text |
| Lore Expander | Any entity | Expand this note into a full lore entry |
| Plot Hook Generator | Arc or location | Suggest three plot hooks from this setting |
| Free Prompt | Anything | User writes their own prompt; world context injected |

### 7.4 Context Assembly Priority

When context approaches the token limit, content is dropped in this order (last dropped first):

1. **NEVER DROP** — World config (genre, tone, era system)
2. **NEVER DROP** — Target entity (name, type, attributes)
3. Drop at 92% — Related entity attribute summaries
4. Drop at 88% — Timeline position
5. Drop at 85% — Arc membership + logline
6. Drop at 80% — Relationship notes (keep rel_type + name, drop notes)
7. Trim at 60% — Lore notes (oldest first)

### 7.5 Response Handling

- Responses rendered as Markdown via Marked.js + DOMPurify in the AI panel.
- Saved to `lore_notes` with `ai_generated=1`, linked to `ai_sessions` record.
- Three actions: **Accept** (promotes to canonical lore) | **Edit** (opens in Markdown editor) | **Discard** (soft delete).
- Token counts logged to `ai_sessions`; budget counter incremented on `worlds`.

### 7.6 Token Budget Dashboard

Visible to world owners at `/worlds/:slug/settings/ai`:
- Monthly budget, used, remaining, reset date
- Usage breakdown by invocation mode
- Paginated session history
- Warning banner at 80%; hard stop at 100% (non-AI features unaffected)

---

## 8. Feature Specifications

### 8.1 Relationship Graph

`/graph` renders the full entity graph using vis-network. Nodes are colour-coded by entity type. Edge labels show relationship type. Force-directed layout with physics toggle. Click any node to navigate to its entity detail.

### 8.2 Interactive Timeline

Timelines use `position_order` integers for drag-to-reorder. Scale mode determines how positions are labelled. Multiple timelines can be overlaid to show parallel event threads across factions or story arcs.

### 8.3 Story Arc Kanban

Four default stages: Seed, Rising Action, Climax, Resolution. Entities drag between arcs and stages. Each arc has a logline, theme keywords, and an on-demand Claude synopsis.

### 8.4 Consistency Checker

A manual or scheduled check passes a structured world snapshot to Claude. Results are returned as structured JSON findings — severity, affected entities, description, suggested resolution. Each finding links to affected entities and can be saved as a draft lore note.

### 8.5 Import & Export

- **JSON export** — full world snapshot, importable into another LoreBuilder instance
- **Markdown export** — one `.md` file per entity; useful for Claude Code contexts
- **WCSOZ GDD sync** — CLI script maps entities to the WCSOZ game design schema

---

## 9. Security Design

| Control | Implementation |
|---|---|
| Authentication | bcrypt (cost 12) + optional TOTP; session cookie HttpOnly/SameSite=Strict |
| CSRF Protection | Double-submit token; X-CSRF-Token header required on all state-changing requests |
| Authorisation | `Guard::requireWorldAccess()` on every world-scoped endpoint; role hierarchy enforced |
| Multi-Tenant Isolation | Every world-scoped query includes `world_id` validated against session membership |
| Input Validation | Explicit allowlist via `Validator::parse()`; PDO prepared statements throughout |
| Output Encoding | `htmlspecialchars()` on reflected values; DOMPurify on AI Markdown output |
| API Key Storage | libsodium secretbox; APP_SECRET in config.php only; fingerprint returned to client |
| Rate Limiting | Token-bucket: 20 AI req/user/hour, 100/world/hour; login lockout after 10 failures |
| Audit Log | Append-only `audit_log`; all mutations recorded with diff_json |
| Security Headers | X-Frame-Options DENY, X-Content-Type-Options, CSP, HSTS |
| File Uploads | Outside web root; MIME validated via finfo; no execution permitted |
| GDPR | Minimal PII; IP pseudonymised after 30 days; full export always available |

See `SECURITY.md` for full threat model (STRIDE) and incident response notes.

---

## 10. Phased Roadmap

### Phase 1 — Foundation (MVP)
1. Database schema & migrations
2. PHP router, DB wrapper, session auth, CSRF, Guard
3. CRUD API for entities, relationships, tags, worlds, members
4. Vue 3 SPA scaffold: entity list, detail, create/edit forms

### Phase 2 — Narrative Structure
1. Timeline model and timeline view (vis-timeline)
2. Story arc model and Kanban board
3. Full-text search (MariaDB FULLTEXT)
4. Lore notes panel with Markdown rendering
5. Tag system and filtering

### Phase 3 — Visualisation
1. Interactive relationship graph (vis-network)
2. Dashboard with statistics and recent activity
3. Audit log viewer

### Phase 4 — Claude Integration
1. `POST /api/v1/worlds/{wid}/ai/assist` with context assembly
2. AI panel on entity detail (all invocation modes)
3. Prompt template editor in Settings
4. AI session history and token budget dashboard
5. Consistency Checker view

### Phase 5 — Power Features
1. WCSOZ GDD sync CLI script
2. JSON & Markdown export/import
3. Timeline overlay (multi-arc view)
4. Multi-user invitations and role management UI
5. Anthropic OAuth (Phase 2 schema already reserved)

---

## 11. Technology Stack Summary

| Component | Technology | Notes |
|---|---|---|
| Web Server | Apache 2.4 | mod_rewrite for SPA routing |
| Backend | PHP 8.3 | No framework; custom router |
| Database | MariaDB 10.11 | FULLTEXT search, JSON columns |
| Frontend | Vue 3 + Vite | Composition API, script setup |
| State | Pinia | Entity cache, AI session state |
| Graph UI | vis-network | Force-directed entity graph |
| Timeline UI | vis-timeline | In-world era timeline |
| Markdown | Marked.js + DOMPurify | Render + sanitise lore and AI output |
| AI | Anthropic API (Claude) | claude-sonnet-4-20250514; server-side proxy only |
| Encryption | libsodium (PHP built-in) | API key encryption, TOTP secrets |
| Deployment | Self-hosted LAMP | No Docker required; SilverDay Media infra |

---

## 12. Constraints & Assumptions

- Deployment targets a standard LAMP server; no containerisation required.
- Users bring their own Anthropic API key (Mode A), or the platform operator provides a shared key with per-world token budgets (Mode B). Billing is per-user or per-operator, not centralised to SilverDay Media.
- Anthropic OAuth (Mode C) is reserved in the schema for Phase 2; no implementation until Anthropic publishes an OAuth specification.
- Single primary author per world initially; multi-user collaboration is supported from Phase 1 at the data model level but the invitation UI ships in Phase 5.
- No offline mode — AI features require internet access; core CRUD works as long as the server is reachable.
- WCSOZ is the primary target world; the schema is deliberately generic for any fictional universe.
- Registration can be set to open or invite-only via `REGISTRATION_OPEN` in `config.php`.

---

*LoreBuilder — Design Document v1.0 — SilverDay Media*
