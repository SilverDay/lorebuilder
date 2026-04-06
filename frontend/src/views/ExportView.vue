<script setup>
import { ref }      from 'vue'
import { useRoute } from 'vue-router'
import { api }      from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const format      = ref('json')
const exporting   = ref(false)
const exportError = ref('')

const importing   = ref(false)
const importFile  = ref(null)
const importMsg   = ref('')
const importError = ref('')
const importStats = ref(null)

// ── Claude import prompt ───────────────────────────────────────────────────────
const promptCopied   = ref(false)
const promptExpanded = ref(false)

const CLAUDE_PROMPT = `You are a world-building assistant helping to structure lore for import into LoreBuilder.

When the user asks you to generate the import JSON, output a single valid JSON object that
strictly follows the schema below. Do not add any commentary, markdown fences, or text
outside the JSON object itself.

## Rules

- \`lorebuilder_version\` must always be the string \`"1"\`.
- Every object with an \`id\` field uses a **local integer ID** — these are only used to
  cross-reference objects within this file (e.g. linking a note to an entity, or an event
  to a timeline). Start entity IDs at 1, timeline IDs at 1, arc IDs at 1. They will be
  remapped to real database IDs on import.
- Omit any top-level array that has no entries (or include it as \`[]\`).
- All string fields have maximum lengths — truncate if necessary (see limits below).
- Unknown or ambiguous entity types default to \`"Concept"\`.
- Unknown arc statuses default to \`"seed"\`.

## Entity types (use exactly as written)
\`Character\` \`Location\` \`Event\` \`Faction\` \`Artefact\` \`Creature\` \`Concept\` \`StoryArc\` \`Timeline\` \`Race\`

## Entity statuses
\`draft\` \`published\` \`archived\`

## Arc statuses
\`seed\` \`rising_action\` \`climax\` \`resolution\` \`complete\` \`abandoned\`

## Timeline scale modes
\`numeric\` \`date\` \`era\`

## Relationship types
Free text — use natural language that fits the world (e.g. \`"ally of"\`, \`"rules over"\`,
\`"child of"\`, \`"sworn enemy of"\`, \`"created by"\`). Max 64 characters.

---

## JSON Schema

\`\`\`json
{
  "lorebuilder_version": "1",
  "exported_at": "<ISO 8601 timestamp or empty string>",

  "world": {
    "name":             "<string, required, max 255>",
    "slug":             "<lowercase-hyphenated, max 100, derived from name>",
    "description":      "<string, optional, max 2000>",
    "genre":            "<string, optional, e.g. Fantasy / Sci-Fi / Horror>",
    "tone":             "<string, optional, e.g. Dark, Hopeful, Gritty>",
    "era_system":       "<string, optional, e.g. Age of Myth / Age of Steel>",
    "content_warnings": "<string, optional>"
  },

  "tags": [
    {
      "name":  "<string, max 64>",
      "color": "<hex color, e.g. #4A90A4>"
    }
  ],

  "entities": [
    {
      "id":            1,
      "type":          "<Entity type from list above>",
      "name":          "<string, required, max 255>",
      "status":        "<draft | published | archived>",
      "short_summary": "<one-sentence description, max 512>",
      "lore_body":     "<longer Markdown description, optional>",
      "tags":          ["<tag name>"],
      "attributes": [
        {
          "attr_key":   "<label, max 64>",
          "attr_value": "<value, max 4000>",
          "data_type":  "<string | integer | boolean | date | markdown>",
          "sort_order": 0
        }
      ]
    }
  ],

  "relationships": [
    {
      "from_entity_id": 1,
      "to_entity_id":   2,
      "rel_type":       "<free text, max 64>",
      "strength":       null,
      "notes":          "<optional context, max 1000>",
      "bidirectional":  false
    }
  ],

  "timelines": [
    {
      "id":          1,
      "name":        "<string, required, max 255>",
      "description": "<optional>",
      "scale_mode":  "<numeric | date | era>"
    }
  ],

  "events": [
    {
      "timeline_id":     1,
      "entity_id":       1,
      "label":           "<string, required, max 255>",
      "description":     "<optional>",
      "position_order":  0,
      "position_label":  "<e.g. Year 340, max 128>",
      "position_era":    "<e.g. Age of Myth, max 128>"
    }
  ],

  "arcs": [
    {
      "id":         1,
      "name":       "<string, required, max 255>",
      "logline":    "<one-sentence pitch, max 512>",
      "theme":      "<thematic statement, max 255>",
      "status":     "<arc status from list above>",
      "sort_order": 0,
      "entity_ids": [1, 2]
    }
  ],

  "notes": [
    {
      "entity_id":    1,
      "content":      "<Markdown text, required>",
      "is_canonical": true,
      "ai_generated": false
    }
  ],

  "open_points": [
    {
      "entity_id":   1,
      "title":       "<short question or unresolved issue, required, max 512>",
      "description": "<fuller context, optional>",
      "status":      "<open | in_progress | resolved | wont_fix>",
      "priority":    "<low | medium | high | critical>"
    }
  ]
}
\`\`\`

Use \`open_points\` for anything unresolved, contradictory, or deliberately left ambiguous — questions the world still needs to answer, design decisions pending, plot holes, lore gaps. Notes are for established lore; open points are for things that still need work.
`

async function copyPrompt() {
  await navigator.clipboard.writeText(CLAUDE_PROMPT)
  promptCopied.value = true
  setTimeout(() => { promptCopied.value = false }, 2500)
}

async function doExport() {
  exporting.value   = true
  exportError.value = ''
  try {
    // GET is CSRF-exempt; use fetch directly to get a blob (api wrapper always parses JSON)
    const res = await fetch(`/api/v1/worlds/${wid}/export?format=${format.value}`, {
      credentials: 'same-origin',
    })
    if (!res.ok) {
      const json = await res.json().catch(() => ({}))
      throw new Error(json.error || `Export failed (${res.status})`)
    }
    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    const ext  = format.value === 'json' ? 'json' : 'md'
    a.href     = url
    a.download = `world-export.${ext}`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    exportError.value = e.message || 'Export failed.'
  } finally {
    exporting.value = false
  }
}

function onFileChange(e) {
  importFile.value = e.target.files?.[0] ?? null
}

async function doImport() {
  if (!importFile.value) return
  importing.value   = true
  importError.value = ''
  importMsg.value   = ''
  importStats.value = null

  try {
    const text = await importFile.value.text()
    const { data } = await api.post(`/api/v1/worlds/${wid}/import`, JSON.parse(text))
    importMsg.value   = 'Import complete.'
    importStats.value = data
  } catch (e) {
    importError.value = e.message || 'Import failed.'
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Export / Import</h1>
      <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Dashboard</RouterLink>
    </header>

    <!-- Export section -->
    <section class="settings-section">
      <h2>Export World</h2>
      <p class="muted">Downloads a complete snapshot of all entities, relationships, timelines, arcs and notes.</p>

      <form class="settings-form" @submit.prevent="doExport">
        <label>
          Format
          <select v-model="format">
            <option value="json">JSON (LoreBuilder format — can be re-imported)</option>
            <option value="markdown">Markdown (human-readable, one section per entity)</option>
          </select>
        </label>
        <p v-if="exportError" class="form-error" role="alert">{{ exportError }}</p>
        <button type="submit" class="btn btn-primary" :disabled="exporting">
          {{ exporting ? 'Preparing…' : 'Download Export' }}
        </button>
      </form>
    </section>

    <!-- Generate with Claude section -->
    <section class="settings-section">
      <h2>Generate import JSON with Claude</h2>
      <p class="muted">
        Have a world living in notes, docs, or an existing conversation? Copy the prompt below and
        use it in any of these ways:
      </p>

      <ol class="import-steps">
        <li><strong>Existing conversation:</strong> paste the prompt directly into the chat where your world was discussed, then say <em>"Now generate the LoreBuilder import JSON."</em> Claude will use everything already in that thread.</li>
        <li><strong>New conversation from notes:</strong> open a new Claude chat, paste the prompt, paste your notes or lore text, then ask for the JSON.</li>
        <li><strong>Long or old threads:</strong> start a fresh chat, paste the prompt, then paste a summary of your world — Claude produces cleaner results with a focused context.</li>
        <li>Save Claude's JSON response as a <code>.json</code> file and import it in the section below.</li>
      </ol>

      <div class="import-prompt-actions">
        <button class="btn btn-primary" @click="copyPrompt">
          {{ promptCopied ? '✓ Copied!' : 'Copy prompt to clipboard' }}
        </button>
        <button class="btn btn-ghost" @click="promptExpanded = !promptExpanded">
          {{ promptExpanded ? 'Hide prompt' : 'Preview prompt' }}
        </button>
      </div>

      <div v-if="promptExpanded" class="import-prompt-preview">
        <pre>{{ CLAUDE_PROMPT }}</pre>
      </div>
    </section>

    <!-- Import section -->
    <section class="settings-section">
      <h2>Import from JSON</h2>
      <p class="muted">
        Imports entities, relationships, timelines, arcs and notes from a LoreBuilder JSON export
        or a Claude-generated import file. Existing data is preserved — the import always adds new records.
      </p>

      <form class="settings-form" @submit.prevent="doImport">
        <label>
          JSON export file
          <input type="file" accept=".json,application/json" @change="onFileChange" :disabled="importing" />
        </label>
        <p v-if="importError" class="form-error" role="alert">{{ importError }}</p>
        <p v-if="importMsg"   class="form-success" role="status">{{ importMsg }}</p>

        <div v-if="importStats" class="import-stats">
          <strong>Imported:</strong>
          {{ importStats.entities }} entities ·
          {{ importStats.relationships }} relationships ·
          {{ importStats.notes }} notes ·
          {{ importStats.open_points }} open points ·
          {{ importStats.timelines }} timelines ·
          {{ importStats.arcs }} arcs ·
          {{ importStats.tags }} tags
        </div>

        <button
          type="submit"
          class="btn btn-secondary"
          :disabled="importing || !importFile"
        >
          {{ importing ? 'Importing…' : 'Import' }}
        </button>
      </form>
    </section>
  </div>
</template>
