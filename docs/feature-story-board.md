# Feature Document: Story Board

**Status:** Proposal  
**Date:** 2026-04-10  
**Author:** AI-assisted design

---

## 1. Goal

Give authors a place to **write actual stories** within LoreBuilder, using the lore database as a living reference. The Story Board is a split-pane environment: a Markdown editor on the left for prose, and a context panel on the right for linking entities, timelines, notes, and arcs that participate in the story. The AI assistant operates within this context to help with writing, consistency, and entity discovery.

---

## 2. Core Concept

A **Story** is a new first-class object, distinct from existing lore notes and story arcs:

- **Lore notes** = factual world-building fragments attached to entities
- **Story arcs** = structural outlines (logline, theme, status, entity membership)
- **Stories** = actual prose — chapters, scenes, short stories — written in Markdown

A story may be linked to a story arc (optional), and references specific entities, timelines, and notes. These links serve two purposes:

1. **Context for the author** — see relevant lore while writing
2. **Context for the AI** — the assistant knows which entities matter for this story

---

## 3. Data Model

### 3.1 New Table: `stories`

```sql
CREATE TABLE stories (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    arc_id          BIGINT UNSIGNED NULL,

    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(300) NOT NULL,
    content         LONGTEXT NOT NULL DEFAULT '',
    synopsis        VARCHAR(2000) NULL,
    status          ENUM('draft','in_progress','review','complete','archived')
                    NOT NULL DEFAULT 'draft',
    word_count      INT UNSIGNED NOT NULL DEFAULT 0,
    sort_order      SMALLINT NOT NULL DEFAULT 0,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL DEFAULT NULL,

    FOREIGN KEY (world_id)   REFERENCES worlds(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (arc_id)     REFERENCES story_arcs(id) ON DELETE SET NULL,
    UNIQUE KEY uq_story_slug (world_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 New Table: `story_entities` (junction)

Links stories to the entities, timelines, notes, and arcs referenced in them.

```sql
CREATE TABLE story_entities (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    story_id        BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,

    role            VARCHAR(128) NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0,

    FOREIGN KEY (story_id)  REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (world_id)  REFERENCES worlds(id),
    UNIQUE KEY uq_story_entity (story_id, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.3 New Table: `story_notes` (junction)

Links stories to specific lore notes for reference in the context panel.

```sql
CREATE TABLE story_notes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    story_id        BIGINT UNSIGNED NOT NULL,
    note_id         BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,

    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (note_id)  REFERENCES lore_notes(id) ON DELETE CASCADE,
    FOREIGN KEY (world_id) REFERENCES worlds(id),
    UNIQUE KEY uq_story_note (story_id, note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.4 Chapter Structure

A "story" record represents a **chapter or scene** — not an entire book. Chapters are grouped by their `arc_id` and ordered via `sort_order`. This keeps each record at a manageable size for auto-save and AI context assembly.

**Example:** An arc "The Cartographer's Debt" with 12 chapters:
- Story "Chapter 1: The Harbour" (arc_id=1, sort_order=0)
- Story "Chapter 2: The Map Room" (arc_id=1, sort_order=1)
- ...and so on

**Content limit:** 500,000 characters per story record (~100K words). Validated server-side. This is far beyond any realistic chapter but prevents unbounded growth.

Standalone stories (no arc) are also valid — for short stories, vignettes, or fragments.

### 3.5 Why Not Extend `story_arcs`?

Story arcs are structural metadata (logline, theme, status). Stories are prose. An arc may spawn multiple stories (chapters), or a story may exist without an arc. Keeping them separate follows the existing pattern where `arc_entities` links arcs to entities, and the new `story_entities` links stories to entities. The optional `arc_id` FK on `stories` connects them when appropriate.

---

## 4. UI Design

### 4.1 Layout: Split Pane

```
┌──────────────────────────────────────────────────────────────────┐
│  Story Board — "The Fall of Ironport"                    [Save] │
├──────────────────────────────┬───────────────────────────────────┤
│ [B] [I] [H1▾] [—] [❝] [🔗] │  Context Panel                   │
│                              │                                   │
│  # Chapter 1: The Harbour    │  ┌─ LINKED ENTITIES ──────────┐  │
│                              │  │ ○ Sera Voss (Character)     │  │
│  The morning light hit the   │  │ ○ Ironport (Location)       │  │
│  harbour wrong. Sera knew    │  │ ○ The Meridian Compact      │  │
│  it before she opened her    │  │   (Faction)                 │  │
│  eyes — the quality of      │  │                  [+ Add]    │  │
│  silence had changed. Not    │  └────────────────────────────┘  │
│  the absence of sound, but   │                                   │
│  the absence of the right    │  ┌─ NOTES ────────────────────┐  │
│  sounds.                     │  │ ✦ Sera speaks three island  │  │
│                              │  │   dialects...               │  │
│  She reached for the ley     │  │ ✦ Alliance exclusivity:     │  │
│  chart on the nightstand.    │  │   A player cannot...        │  │
│  It was warm.                │  │                  [+ Add]    │  │
│                              │  └────────────────────────────┘  │
│                              │                                   │
│                              │  ┌─ TIMELINE POSITION ────────┐  │
│                              │  │ → Year 1 AF: Sera escapes  │  │
│                              │  │   Ironport                  │  │
│                              │  └────────────────────────────┘  │
│                              │                                   │
│                              │  ┌─ STORY ARC ────────────────┐  │
│                              │  │ The Cartographer's Debt     │  │
│                              │  │ Status: rising_action       │  │
│                              │  └────────────────────────────┘  │
│                              │                                   │
│                              │  [🤖 AI Assistant]               │
│                              │                                   │
├──────────────────────────────┴───────────────────────────────────┤
│  Words: 1,247  ·  Status: draft  ·  Last saved: 2 min ago       │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2 Markdown Editor (Left Pane)

**Toolbar buttons** (top of editor):
| Button | Action | Markdown |
|---|---|---|
| **B** | Bold | `**text**` |
| *I* | Italic | `*text*` |
| H1▾ | Heading dropdown (H1–H4) | `# ` – `#### ` |
| — | Horizontal rule | `---` |
| ❝ | Blockquote | `> ` |
| 🔗 | Link | `[text](url)` |
| • | Unordered list | `- ` |
| 1. | Ordered list | `1. ` |
| `<>` | Code block | ``` |
| ↩ | Undo | |
| ↪ | Redo | |

**Editor behaviour:**
- WYSIWYG editing via Milkdown — authors see formatted text, stored as Markdown
- No separate preview mode needed (editor IS the preview)
- Auto-save every 30 seconds if content has changed (debounced PATCH)
- Manual save button + Ctrl+S / Cmd+S keyboard shortcut
- Word count updated on save/auto-save (computed server-side for consistency)

**Editor library:**  
Use **Milkdown** (`@milkdown/vue`, `@milkdown/preset-commonmark`) — a WYSIWYG Markdown editor built on ProseMirror. Reasons:
- Authors see formatted output while writing — no raw Markdown syntax required
- Stores Markdown natively — content remains portable and indexable
- Vue integration via `@milkdown/vue`
- Plugin system supports custom extensions for entity mention detection
- Toolbar commands handled by Milkdown's command API (bold, italic, headings, etc.)

See ADR-008 in DECISIONS.md.

### 4.3 Context Panel (Right Pane)

Collapsible sections, each with an [+ Add] button:

**Linked Entities**
- List of entities linked to this story via `story_entities`
- Each shows: name, type badge, optional role
- Click to expand: shows `short_summary` and key attributes inline
- Click entity name to navigate to entity detail (opens in new tab)
- [+ Add] opens a search modal (reuse existing `SearchModal.vue`) filtered to current world
- Drag to reorder (sets `sort_order`)
- ✕ to unlink

**Notes**
- List of lore notes linked via `story_notes`
- Each shows: truncated content, entity name it belongs to, canonical badge
- [+ Add] opens note picker (list notes from linked entities, or search all)

**Timeline Position**
- If linked entities have timeline events, show them in chronological order
- Read-only reference — click event to see full description
- Derived automatically from linked entities (no separate junction table needed)

**Story Arc**
- If `arc_id` is set, shows arc name, logline, theme, status
- Click to navigate to arc detail
- [Change] / [Remove] buttons

**AI Assistant**
- Integrated AI panel (reuse `AiPanel.vue` pattern) with story-specific modes
- See Section 6 for AI integration details

### 4.4 Story List View

Route: `/worlds/:wid/stories`

- Grid or list of stories in the world
- Columns: title, status, arc (if linked), word count, last updated
- Sort by: title, status, updated_at, word_count
- Filter by: status, arc
- [+ New Story] button

### 4.5 Story Board View

Route: `/worlds/:wid/stories/:sid`

The split-pane editor described above. Full-height layout (no page chrome except AppNav).

---

## 5. API Endpoints

### 5.1 Stories CRUD

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `/worlds/:wid/stories` | author | List stories (paginated, filterable) |
| POST | `/worlds/:wid/stories` | author | Create story |
| GET | `/worlds/:wid/stories/:sid` | author | Get story with linked entities/notes |
| PATCH | `/worlds/:wid/stories/:sid` | author | Update story (title, content, status, arc_id, synopsis) |
| DELETE | `/worlds/:wid/stories/:sid` | author | Soft delete |

### 5.2 Story Entity Links

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `/worlds/:wid/stories/:sid/entities` | author | List linked entities |
| PUT | `/worlds/:wid/stories/:sid/entities` | author | Replace entity set (atomic, follows arc pattern) |
| POST | `/worlds/:wid/stories/:sid/entities` | author | Add single entity link |
| DELETE | `/worlds/:wid/stories/:sid/entities/:eid` | author | Remove single entity link |

### 5.3 Story Note Links

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `/worlds/:wid/stories/:sid/notes` | author | List linked notes |
| POST | `/worlds/:wid/stories/:sid/notes` | author | Link a note |
| DELETE | `/worlds/:wid/stories/:sid/notes/:nid` | author | Unlink a note |

### 5.4 AI Story Assist

| Method | Path | Role | Description |
|---|---|---|---|
| POST | `/worlds/:wid/stories/:sid/ai/assist` | author | AI assist with story context |
| POST | `/worlds/:wid/stories/:sid/ai/scan-entities` | author | Scan text for unlinked entities |

### 5.5 Response Format

```json
// GET /worlds/:wid/stories/:sid
{
  "data": {
    "id": 42,
    "title": "The Fall of Ironport",
    "slug": "the-fall-of-ironport",
    "content": "# Chapter 1...",
    "synopsis": "Sera witnesses the Fracture...",
    "status": "in_progress",
    "word_count": 1247,
    "arc": {
      "id": 1,
      "name": "The Cartographer's Debt",
      "status": "rising_action"
    },
    "entities": [
      { "id": 5, "name": "Sera Voss", "type": "Character", "role": "protagonist" }
    ],
    "notes": [
      { "id": 12, "content": "Sera speaks three island dialects...", "entity_name": "Sera Voss" }
    ],
    "created_at": "2026-04-10T...",
    "updated_at": "2026-04-10T..."
  }
}
```

---

## 6. AI Integration

### 6.1 Story-Specific AI Modes

| Mode | Purpose | Context Assembly |
|---|---|---|
| `story_assist` | General writing help — continue a scene, suggest dialogue, describe a setting | Story content (current section) + linked entities + linked notes + arc logline |
| `story_consistency` | Check the current text against established lore | Story content + all linked entity attributes + all linked notes |
| `entity_scan` | Find entity names in the text that exist in the DB but aren't linked | Story content + full entity name list for the world |
| `entity_suggest` | Suggest new entities that should be created based on the text | Story content + existing entity names (to avoid duplicates) |
| `story_outline` | Generate or refine a chapter/scene outline | Synopsis + arc logline + arc theme + linked entity summaries |

### 6.2 Entity Scan Flow

This is the "find entities in the text that are not yet part of the database" feature:

```
1. User clicks [🔍 Scan for Entities] in context panel
2. Frontend sends POST /worlds/:wid/stories/:sid/ai/scan-entities
   Body: { content: <story text> }
3. Backend:
   a. Load all entity names + slugs for the world
   b. Fuzzy-match entity names against the story text (server-side, no AI needed)
   c. Optionally: send text to AI for semantic entity detection
      (finds references like "the merchant" → Ermelin)
   d. Compare found entities against already-linked story_entities
   e. Return { found: [...], unlinked: [...], suggested_new: [...] }
4. Frontend shows results in context panel:
   - "Found but not linked" — [Link] button for each
   - "Possibly new entities" — [Create Entity] button for each
```

**Phase 1:** Server-side string matching against entity names (no AI, fast, free).  
**Phase 2:** AI-powered semantic scan for indirect references and entity suggestions.

### 6.3 Context Assembly for Story Modes

Extends the existing `AiEngine::buildContext()` pattern:

```
1. World config (genre, tone, era_system) — NEVER drop
2. Story synopsis + arc logline + theme — NEVER drop
3. Linked entity summaries + attributes — drop after 70%
4. Linked notes — drop after 80%
5. Story content (current section, ~2000 words around cursor) — trim from 60%
6. Related entity relationships — drop after 88%
7. Timeline positions for linked entities — drop after 92%
```

The story content section uses a cursor-aware window: the AI receives text around the author's current writing position, not the entire story (which could be novel-length).

### 6.4 New Prompt Templates

```sql
-- Seed templates for story modes
INSERT INTO prompt_templates (world_id, mode, name, system_tpl, user_tpl, is_default)
VALUES
(NULL, 'story_assist', 'Story Writing Assistant',
 'You are a writing assistant for the world "{{world.name}}" ({{world.genre}}, {{world.tone}}). Help the author continue, refine, or expand their story while respecting established lore.\n\nLinked entities:\n{{story.entities}}\n\nRelevant notes:\n{{story.notes}}\n\nStory arc: {{story.arc}}\n\nStory so far (around current position):\n{{story.context_window}}',
 '{{user_prompt}}', 1),

(NULL, 'story_consistency', 'Story Consistency Checker',
 'You are a continuity editor for "{{world.name}}". Check the following story text for contradictions with established lore. Only flag genuine inconsistencies, not creative liberties.\n\nEstablished lore:\n{{story.entity_details}}\n{{story.notes}}',
 'Check this text for lore consistency:\n\n{{story.content}}', 1),

(NULL, 'entity_scan', 'Entity Scanner',
 'You are analysing a story set in "{{world.name}}". Identify characters, locations, factions, artefacts, or concepts mentioned in the text that might warrant their own entity entry.\n\nExisting entities in this world:\n{{world.entity_names}}',
 'Scan this text and list any named characters, places, factions, or concepts that are not in the existing entity list:\n\n{{story.content}}', 1);
```

---

## 7. Auto-Save Design

Stories can be long. Losing work is unacceptable.

| Trigger | Action |
|---|---|
| 30-second interval (if dirty) | `PATCH /stories/:sid` with `{ content }` |
| Ctrl+S / Cmd+S | Immediate save |
| [Save] button | Immediate save |
| Tab/window close (`beforeunload`) | Warn if unsaved changes |

Auto-save sends only `content` and `word_count`. Metadata changes (title, status, arc) are saved immediately on change via separate PATCH calls.

The backend always stores the full content (no delta/OT). Conflict detection via `updated_at` — if the stored `updated_at` is newer than the client's last-known value, return 409 Conflict and let the client decide (overwrite or reload).

---

## 8. Import/Export Integration

### 8.1 Export

Add `stories` to the JSON export:

```json
{
  "stories": [
    {
      "id": 1,
      "title": "The Fall of Ironport",
      "content": "# Chapter 1...",
      "synopsis": "...",
      "status": "in_progress",
      "arc_id": 1,
      "entity_ids": [1, 3],
      "note_ids": [12, 15],
      "sort_order": 0
    }
  ]
}
```

### 8.2 Import

Add `stories` to the import handler. Entity/note IDs are remapped through `$oldToNewEntity` / `$oldToNewNote` maps (same pattern as arc entities).

### 8.3 Import Prompt

Add `stories` to the JSON schema in `lorebuilder-import-prompt.md`. Stories are optional in imports — most AI-generated imports won't include them (they're for author-written prose, not structured lore).

---

## 9. Frontend Components

### 9.1 New Files

```
frontend/src/
├── views/
│   ├── StoryListView.vue          # /worlds/:wid/stories
│   └── StoryBoardView.vue         # /worlds/:wid/stories/:sid
├── components/
│   └── story/
│       ├── StoryEditor.vue        # CodeMirror Markdown editor + toolbar
│       ├── StoryContextPanel.vue  # Right pane: entities, notes, timeline, AI
│       ├── StoryEntityList.vue    # Linked entities section
│       ├── StoryNoteList.vue      # Linked notes section
│       ├── StoryTimelineRef.vue   # Timeline reference section
│       ├── StoryArcRef.vue        # Arc reference section
│       └── StoryAiPanel.vue       # AI panel with story-specific modes
├── stores/
│   └── story.js                   # Pinia store for story state + auto-save
```

### 9.2 New Dependencies

```json
{
  "@milkdown/core": "^7.x",
  "@milkdown/ctx": "^7.x",
  "@milkdown/prose": "^7.x",
  "@milkdown/preset-commonmark": "^7.x",
  "@milkdown/plugin-listener": "^7.x",
  "@milkdown/theme-nord": "^7.x",
  "@milkdown/vue": "^7.x"
}
```

### 9.3 Router

```js
{
  path: '/worlds/:wid/stories',
  component: () => import('@/views/StoryListView.vue'),
  meta: { requiresAuth: true }
},
{
  path: '/worlds/:wid/stories/:sid',
  component: () => import('@/views/StoryBoardView.vue'),
  meta: { requiresAuth: true }
}
```

---

## 10. Backend Files

### 10.1 New Files

```
api/
└── StoryController.php        # CRUD + entity/note linking + AI endpoints
```

### 10.2 Modified Files

| File | Change |
|---|---|
| `core/Router.php` | Add story routes |
| `core/AiEngine.php` | Add story context assembly + story AI modes |
| `api/ExportController.php` | Add stories to export/import |
| `docs/lorebuilder-import-prompt.md` | Add stories to schema |
| `frontend/src/components/AppNav.vue` | Add "Stories" nav item |

---

## 11. Permissions

Stories follow the same ownership model as entities:

| Action | Required Role |
|---|---|
| List stories | author |
| Create story | author |
| Read story | author (reads all in world) |
| Update story | author (own) or admin |
| Delete story | author (own) or admin |
| Link/unlink entities | author (own story) or admin |
| AI assist | author (rate-limited) |

---

## 12. Implementation Phases

### Phase 1: Core Story Board
1. Database migration (`008_stories.sql`)
2. `StoryController.php` — CRUD + entity/note linking
3. `StoryListView.vue` — list/create stories
4. `StoryBoardView.vue` — split-pane layout
5. `StoryEditor.vue` — Milkdown WYSIWYG Markdown editor + toolbar
6. `StoryContextPanel.vue` — entity/note/arc display
7. Auto-save implementation
8. Router + navigation integration
9. Export/import support

**Result:** Functional story editor with entity linking and auto-save.

### Phase 2: Entity Scanning
1. Server-side name matching (no AI, fast)
2. Scan UI in context panel — show found/unlinked entities
3. Quick-link buttons for discovered entities

**Result:** Author can discover entities in their text and link them with one click.

### Phase 3: AI Story Assistance
1. Story-specific prompt templates
2. Story context assembly in `AiEngine.php`
3. `StoryAiPanel.vue` — story-specific AI modes
4. Cursor-aware context window for long stories

**Result:** AI can help write, check consistency, and suggest entities.

### Phase 4: Polish
1. Keyboard shortcut refinements and custom Milkdown plugins
2. Story word count statistics/progress tracking
3. Resizable split pane
4. Full-screen / distraction-free writing mode

---

## 13. What Stays the Same

- **Lore notes** remain the canonical lore record. Stories reference notes but don't replace them.
- **Story arcs** remain structural metadata. A story is prose that may belong to an arc.
- **AI modes** for entity_assist, arc_synthesiser, etc. remain unchanged. Story modes are additive.
- **Entity types** — no new entity types needed. Stories are not entities.
- **Permissions model** — same Guard pattern, same role hierarchy.

---

## 14. Risks & Considerations

| Risk | Mitigation |
|---|---|
| Stories can be very long (novel-length) | Chapters as separate story records (grouped by arc + sort_order); 500K char limit per record; cursor-aware AI context window |
| Milkdown bundle size | Lazy-load the story editor route; tree-shake unused plugins |
| Auto-save conflicts (two tabs) | `updated_at` conflict detection; 409 response on stale writes |
| AI context budget with long story text | Cursor-window approach: send ~2000 words around cursor, not entire story |
| Entity scan false positives | Show results as suggestions, never auto-link; string matching is Phase 1 (simple, reviewable) |
| Pane resizing complexity | Use CSS `resize` or a lightweight splitter; defer to Phase 4 |

---

## 15. Resolved Design Decisions

All decisions logged in `DECISIONS.md` (ADR-008 through ADR-012).

1. **Milkdown WYSIWYG** — Authors see formatted output while writing; stored as Markdown. (ADR-008)
2. **Route-level views** — `/worlds/:wid/stories` and `/worlds/:wid/stories/:sid`. (ADR-009)
3. **Chapters as story records** — Each record is a chapter/scene. Grouped by arc + sort_order. 500K char limit per record (~100K words, far beyond any realistic chapter). The story list and arc grouping naturally encourage chapter-based organization. (ADR-010)
4. **No server-side versioning** — Auto-save overwrites in place. GitHub integration is a future option for external version control. (ADR-011)
5. **No collaborative editing** — Single-author editing only. Conflict detection via `updated_at` for multi-tab safety. (ADR-012)
