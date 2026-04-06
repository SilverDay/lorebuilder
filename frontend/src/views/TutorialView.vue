<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const wid   = route.params.wid   // may be undefined if accessed from landing

// Active section for sidebar highlight
const active = ref('getting-started')

function onScroll() {
  const sections = document.querySelectorAll('.tut-section')
  for (const s of [...sections].reverse()) {
    if (s.getBoundingClientRect().top <= 120) {
      active.value = s.id
      break
    }
  }
}

function scrollTo(id) {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  active.value = id
}

const NAV = [
  { id: 'getting-started', label: 'Getting Started' },
  { id: 'entities',        label: 'Entities' },
  { id: 'relationships',   label: 'Relationships' },
  { id: 'graph',           label: 'Relationship Graph' },
  { id: 'timelines',       label: 'Timelines' },
  { id: 'story-arcs',      label: 'Story Arcs' },
  { id: 'notes',           label: 'Lore Notes' },
  { id: 'references',      label: 'References' },
  { id: 'open-points',     label: 'Open Points' },
  { id: 'ai-assistant',    label: 'AI Assistant' },
  { id: 'export-import',   label: 'Export & Import' },
  { id: 'members',         label: 'Team & Roles' },
]
</script>

<template>
  <div class="tut-layout" @scroll.passive="onScroll">

    <!-- ── Sidebar ── -->
    <aside class="tut-sidebar">
      <div class="tut-sidebar__brand">
        <RouterLink to="/" class="tut-sidebar__logo">LoreBuilder</RouterLink>
        <span class="tut-sidebar__label">User Guide</span>
      </div>
      <nav class="tut-nav">
        <button
          v-for="item in NAV"
          :key="item.id"
          class="tut-nav__item"
          :class="{ 'tut-nav__item--active': active === item.id }"
          @click="scrollTo(item.id)"
        >{{ item.label }}</button>
      </nav>
      <div class="tut-sidebar__back">
        <RouterLink v-if="wid" :to="`/worlds/${wid}`" class="btn btn-ghost btn-sm">← Dashboard</RouterLink>
        <RouterLink v-else to="/" class="btn btn-ghost btn-sm">← Home</RouterLink>
      </div>
    </aside>

    <!-- ── Content ── -->
    <main class="tut-content" @scroll.passive="onScroll">

      <div class="tut-hero">
        <h1>LoreBuilder User Guide</h1>
        <p>Everything you need to build, manage, and explore your fictional world.</p>
      </div>

      <!-- Getting Started -->
      <section id="getting-started" class="tut-section">
        <h2>Getting Started</h2>
        <p>
          LoreBuilder organises your fiction as a structured knowledge base. The top-level
          container is a <strong>World</strong>. Every character, place, faction, and event
          lives inside a world and can be connected to every other element.
        </p>
        <ol class="tut-steps">
          <li>
            <strong>Create an account</strong> — click <em>Get started free</em> on the home page
            and choose a username, email, and password (minimum 12 characters).
          </li>
          <li>
            <strong>Create your first world</strong> — from <em>My Worlds</em>, click
            <em>New World</em>. Give it a name, genre, tone, and an optional era system
            (e.g. "Age of Myth / Age of Kingdoms"). These fields feed the AI assistant's
            context, so the more detail you add here the better.
          </li>
          <li>
            <strong>Open the Dashboard</strong> — the dashboard gives you a stat overview and
            quick links to every section of your world.
          </li>
        </ol>
        <div class="tut-tip">
          <strong>Tip:</strong> The world description and tone fields are the first thing the AI
          reads when you ask it a question. A sentence or two about the setting and mood goes a
          long way.
        </div>
      </section>

      <!-- Entities -->
      <section id="entities" class="tut-section">
        <h2>Entities</h2>
        <p>
          An <strong>entity</strong> is any named element in your world. LoreBuilder supports
          these types:
        </p>
        <div class="tut-type-grid">
          <div class="tut-type-pill">Character</div>
          <div class="tut-type-pill">Location</div>
          <div class="tut-type-pill">Faction</div>
          <div class="tut-type-pill">Race</div>
          <div class="tut-type-pill">Creature</div>
          <div class="tut-type-pill">Event</div>
          <div class="tut-type-pill">Artefact</div>
          <div class="tut-type-pill">Concept</div>
          <div class="tut-type-pill">StoryArc</div>
          <div class="tut-type-pill">Timeline</div>
        </div>

        <h3>Creating an entity</h3>
        <ol class="tut-steps">
          <li>Go to <em>Entities</em> and click <em>New Entity</em>.</li>
          <li>Set the type, name, status (<em>draft / published / archived</em>), and an optional summary.</li>
          <li>Add <strong>custom attributes</strong> — any key/value pairs specific to this entity
          (e.g. "Age: 34", "Alignment: Chaotic Neutral", "Capital City: Arathorn").</li>
          <li>Add <strong>tags</strong> to group entities across types (e.g. "main-cast", "prologue").</li>
        </ol>

        <h3>Entity status</h3>
        <ul class="tut-list">
          <li><strong>Draft</strong> — work in progress, not yet canon.</li>
          <li><strong>Published</strong> — finalised, treated as canon by the AI.</li>
          <li><strong>Archived</strong> — retired but kept for reference.</li>
        </ul>

        <div class="tut-tip">
          <strong>Tip:</strong> Use the <em>summary</em> field as a one-sentence description.
          The AI uses summaries when building context for other entities to keep token usage efficient.
        </div>
      </section>

      <!-- Relationships -->
      <section id="relationships" class="tut-section">
        <h2>Relationships</h2>
        <p>
          Relationships are typed, directed connections between any two entities.
          They appear in the graph and are included in AI context.
        </p>
        <h3>Adding a relationship</h3>
        <ol class="tut-steps">
          <li>Open an entity's detail page.</li>
          <li>In the <em>Relationships</em> panel, click <em>Add relationship</em>.</li>
          <li>Choose a <strong>target entity</strong>, a <strong>relation type</strong>
          (e.g. "ally of", "child of", "rules over"), and an optional strength (1–10).</li>
          <li>Mark as <strong>bidirectional</strong> if the relationship runs both ways —
          this renders as a two-headed arrow in the graph.</li>
        </ol>
        <div class="tut-tip">
          <strong>Tip:</strong> Relation types are free text — use whatever language fits your world.
          "Sworn enemy of" is just as valid as "nemesis".
        </div>
      </section>

      <!-- Graph -->
      <section id="graph" class="tut-section">
        <h2>Relationship Graph</h2>
        <p>
          The graph renders every published entity as a node and every relationship as an edge.
          Node colour indicates type. You can drag nodes, zoom, and click a node to jump to
          that entity's detail page.
        </p>
        <ul class="tut-list">
          <li>Nodes are coloured by type (Character = blue, Location = green, Faction = purple, Race = violet, etc.).</li>
          <li>Arrow direction shows which entity "owns" the relationship.</li>
          <li>Bidirectional relationships show arrows at both ends.</li>
          <li>Only entities with <em>published</em> or <em>draft</em> status appear; archived entities are hidden.</li>
        </ul>
      </section>

      <!-- Timelines -->
      <section id="timelines" class="tut-section">
        <h2>Timelines</h2>
        <p>
          Timelines let you place events in chronological order and link them to entities.
          You can have multiple timelines per world (e.g. one for the main plot, one for
          ancient history).
        </p>
        <ol class="tut-steps">
          <li>Go to <em>Timeline</em> and click <em>New Timeline</em>.</li>
          <li>Add <strong>events</strong> with a label, optional date/era, and an optional
          linked entity. The event order can be dragged to reorder.</li>
          <li>Select which timeline to view using the dropdown at the top of the page.</li>
        </ol>
      </section>

      <!-- Story Arcs -->
      <section id="story-arcs" class="tut-section">
        <h2>Story Arcs</h2>
        <p>
          Story arcs track the narrative shape of your plot. Each arc has a status that moves
          it through a Kanban board.
        </p>
        <div class="tut-type-grid">
          <div class="tut-type-pill">Seed</div>
          <div class="tut-type-pill">Rising Action</div>
          <div class="tut-type-pill">Climax</div>
          <div class="tut-type-pill">Resolution</div>
          <div class="tut-type-pill">Complete</div>
          <div class="tut-type-pill">Abandoned</div>
        </div>
        <ol class="tut-steps">
          <li>Go to <em>Story Arcs</em> and click <em>New Arc</em>.</li>
          <li>Add a title, logline (one-sentence summary), and optional full description.</li>
          <li>Use <em>Manage Entities</em> on the arc detail to assign which entities are
          part of this arc — this is what the AI uses when you ask for an arc synthesis.</li>
          <li>Drag cards between columns to update arc status.</li>
        </ol>
      </section>

      <!-- Notes -->
      <section id="notes" class="tut-section">
        <h2>Lore Notes</h2>
        <p>
          Lore notes are free-text Markdown notes attached to a specific entity. They are the
          main place to write down backstory, descriptions, and ideas.
        </p>
        <ul class="tut-list">
          <li><strong>Draft notes</strong> are private working notes.</li>
          <li><strong>Canonical notes</strong> are flagged as authoritative lore — the AI
          prioritises these when building context.</li>
          <li><strong>AI-generated notes</strong> are created automatically when you use the
          AI assistant. You can promote them to canonical once you're happy with the content.</li>
        </ul>
        <div class="tut-tip">
          <strong>Tip:</strong> Notes support full Markdown — headings, bold, italic, bullet
          lists, and code blocks all render correctly.
        </div>
      </section>

      <!-- References -->
      <section id="references" class="tut-section">
        <h2>References</h2>
        <p>
          References are research sources — URLs, books, articles, films, podcasts — attached
          to a world to document where your inspiration came from.
        </p>
        <ol class="tut-steps">
          <li>Go to <em>References</em> and click <em>New Reference</em>.</li>
          <li>Choose a type (URL, Book, Article, Film, Podcast, Other), add a title, and
          fill in the URL, author, and a note on why it's relevant.</li>
          <li>Use <em>Edit → Linked Entities</em> to associate the reference with specific
          entities it inspired.</li>
        </ol>
        <div class="tut-tip">
          <strong>Tip:</strong> Tags on references let you group sources by theme —
          e.g. "mythology", "maps", "character-inspiration".
        </div>
      </section>

      <!-- Open Points -->
      <section id="open-points" class="tut-section">
        <h2>Open Points</h2>
        <p>
          Open points capture unresolved questions, plot holes, and things you need to
          clarify. They are often raised automatically during AI sessions
          ("<em>wait — if the war ended in Year 40, how was the king born in Year 38?</em>").
        </p>
        <h3>Statuses</h3>
        <ul class="tut-list">
          <li><strong>Open</strong> — needs attention.</li>
          <li><strong>In Progress</strong> — being actively worked on.</li>
          <li><strong>Resolved</strong> — answered; resolution notes saved.</li>
          <li><strong>Won't Fix</strong> — acknowledged but intentionally left unresolved.</li>
        </ul>
        <h3>Priorities</h3>
        <ul class="tut-list">
          <li><strong>Critical</strong> — blocks the story from progressing.</li>
          <li><strong>High</strong> — important but not immediately blocking.</li>
          <li><strong>Medium</strong> — should be resolved before final draft.</li>
          <li><strong>Low</strong> — nice to have, low urgency.</li>
        </ul>
        <div class="tut-tip">
          <strong>Tip:</strong> Use <em>Mark Resolved</em> directly from the list — no need to
          open the detail view. Add resolution notes to record what you decided.
        </div>
      </section>

      <!-- AI Assistant -->
      <section id="ai-assistant" class="tut-section">
        <h2>AI Assistant</h2>
        <p>
          The AI assistant uses Claude to answer questions about your world, generate
          backstory, synthesise arc summaries, and spot inconsistencies — with full
          context of your entities, relationships, and notes.
        </p>

        <h3>Setting up your API key</h3>
        <ol class="tut-steps">
          <li>Go to <em>AI Settings</em> from your world dashboard.</li>
          <li>Enter your Anthropic API key. The key is encrypted before storage and
          is never returned to the browser.</li>
          <li>Optionally set a token budget to avoid unexpected usage.</li>
        </ol>

        <h3>Using the assistant</h3>
        <ol class="tut-steps">
          <li>Click the <strong>✦</strong> button (bottom-right of any world page)
          to open the AI panel.</li>
          <li>Choose a <strong>mode</strong>:
            <ul class="tut-list" style="margin-top:.4rem">
              <li><strong>Entity Assist</strong> — focuses on a specific entity you select.</li>
              <li><strong>Arc Synthesiser</strong> — summarises and analyses a story arc.</li>
              <li><strong>World Overview</strong> — broad questions about the whole world.</li>
              <li><strong>Custom</strong> — freeform prompt with full world context.</li>
            </ul>
          </li>
          <li>Type your question or request, then press <kbd>Ctrl+Enter</kbd> or click <em>Ask</em>.</li>
          <li>The response is automatically saved as a lore note. Click
          <em>Promote to Canonical</em> to mark it as authoritative lore.</li>
        </ol>

        <div class="tut-tip">
          <strong>Tip:</strong> Ask the AI to "list any inconsistencies you notice" after
          adding a batch of new entities — it often catches timeline gaps and contradictory
          attributes you missed.
        </div>
      </section>

      <!-- Export / Import -->
      <section id="export-import" class="tut-section">
        <h2>Export &amp; Import</h2>
        <p>
          You can export a complete snapshot of your world and re-import it into any
          LoreBuilder instance.
        </p>
        <h3>Exporting</h3>
        <ol class="tut-steps">
          <li>Go to <em>Export</em> from the dashboard.</li>
          <li>Choose <strong>JSON</strong> (full data, re-importable) or
          <strong>Markdown</strong> (human-readable, one section per entity).</li>
          <li>Click <em>Download Export</em>. The file downloads immediately.</li>
        </ol>
        <h3>Importing</h3>
        <ol class="tut-steps">
          <li>On the Export page, scroll to <em>Import from JSON</em>.</li>
          <li>Select a <code>.json</code> export file from your device.</li>
          <li>Click <em>Import</em>. Existing data is preserved — the import always
          <em>adds</em> new records rather than replacing existing ones.</li>
        </ol>
        <div class="tut-tip">
          <strong>Tip:</strong> Use export/import to merge two worlds or to seed a new
          world from an existing one.
        </div>
      </section>

      <!-- Members -->
      <section id="members" class="tut-section">
        <h2>Team &amp; Roles</h2>
        <p>
          You can invite collaborators to a world and assign them a role. Each role has
          different permissions:
        </p>
        <table class="tut-table">
          <thead>
            <tr>
              <th>Role</th>
              <th>Read</th>
              <th>Create / Edit own</th>
              <th>Edit all</th>
              <th>Manage members</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Owner</td>   <td>✓</td><td>✓</td><td>✓</td><td>✓</td></tr>
            <tr><td>Admin</td>   <td>✓</td><td>✓</td><td>✓</td><td>—</td></tr>
            <tr><td>Author</td>  <td>✓</td><td>✓</td><td>—</td><td>—</td></tr>
            <tr><td>Reviewer</td><td>✓</td><td>—</td><td>—</td><td>—</td></tr>
            <tr><td>Viewer</td>  <td>✓</td><td>—</td><td>—</td><td>—</td></tr>
          </tbody>
        </table>
        <h3>Inviting someone</h3>
        <ol class="tut-steps">
          <li>Go to <em>Members</em> from the dashboard.</li>
          <li>Enter the person's email address and choose their role.</li>
          <li>They receive an invitation link. Once accepted, they appear in the members list.</li>
        </ol>
      </section>

    </main>
  </div>
</template>
