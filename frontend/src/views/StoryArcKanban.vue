<script setup>
/**
 * StoryArcKanban — Kanban board for story arc status management.
 *
 * Columns: seed → rising_action → climax → resolution → complete → abandoned
 * Drag arc cards between columns → PATCH status endpoint.
 * Create, edit, delete arcs inline. View entity roster.
 *
 * Uses native HTML5 drag-and-drop (no extra library).
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client.js'

const route  = useRoute()
const wid    = route.params.wid

const arcs    = ref([])
const loading = ref(false)
const error   = ref('')

const COLUMNS = [
  { key: 'seed',          label: 'Seed' },
  { key: 'rising_action', label: 'Rising Action' },
  { key: 'climax',        label: 'Climax' },
  { key: 'resolution',    label: 'Resolution' },
  { key: 'complete',      label: 'Complete' },
  { key: 'abandoned',     label: 'Abandoned' },
]

const grouped = computed(() => {
  const map = {}
  for (const col of COLUMNS) map[col.key] = []
  for (const arc of arcs.value) {
    if (map[arc.status] !== undefined) map[arc.status].push(arc)
  }
  return map
})

async function loadArcs() {
  loading.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/story-arcs`)
    arcs.value = data ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load story arcs.'
  } finally {
    loading.value = false
  }
}

onMounted(loadArcs)

// ── Drag and Drop ─────────────────────────────────────────────────────────────

const dragging = ref(null)    // { arcId, fromStatus }

function onDragStart(arc) {
  dragging.value = { arcId: arc.id, fromStatus: arc.status }
}

function onDragOver(e) {
  e.preventDefault()
}

async function onDrop(e, toStatus) {
  e.preventDefault()
  if (!dragging.value) return

  const { arcId, fromStatus } = dragging.value
  dragging.value = null

  if (fromStatus === toStatus) return

  // Optimistically update in local state
  const arc = arcs.value.find(a => a.id === arcId)
  if (!arc) return
  arc.status = toStatus

  try {
    await api.patch(`/api/v1/worlds/${wid}/story-arcs/${arcId}`, { status: toStatus })
  } catch (e) {
    // Revert on failure
    arc.status   = fromStatus
    error.value  = e.message || 'Failed to update arc status.'
  }
}

// ── Create Arc ────────────────────────────────────────────────────────────────

const showCreateForm = ref(false)
const createForm     = ref({ name: '', logline: '', theme: '', status: 'seed' })
const creating       = ref(false)

function resetCreateForm() {
  createForm.value = { name: '', logline: '', theme: '', status: 'seed' }
  showCreateForm.value = false
}

async function submitCreate() {
  if (!createForm.value.name.trim()) return
  creating.value = true
  error.value    = ''
  try {
    const { data } = await api.post(`/api/v1/worlds/${wid}/story-arcs`, {
      name:    createForm.value.name.trim(),
      logline: createForm.value.logline.trim() || null,
      theme:   createForm.value.theme.trim()   || null,
      status:  createForm.value.status,
    })
    resetCreateForm()
    await loadArcs()
  } catch (e) {
    error.value = e.message || 'Failed to create story arc.'
  } finally {
    creating.value = false
  }
}

// ── Edit Arc ──────────────────────────────────────────────────────────────────

const editingArc = ref(null) // full arc object being edited
const editForm   = ref({ name: '', logline: '', theme: '', status: '' })
const saving     = ref(false)

function startEdit(arc) {
  editingArc.value = arc
  editForm.value   = {
    name:    arc.name    ?? '',
    logline: arc.logline ?? '',
    theme:   arc.theme   ?? '',
    status:  arc.status  ?? 'seed',
  }
  // Also load full arc detail (with entities)
  loadArcDetail(arc.id)
}

function cancelEdit() {
  editingArc.value = null
  arcDetail.value  = null
}

async function submitEdit() {
  if (!editingArc.value || !editForm.value.name.trim()) return
  saving.value = true
  error.value  = ''
  try {
    await api.patch(`/api/v1/worlds/${wid}/story-arcs/${editingArc.value.id}`, {
      name:    editForm.value.name.trim(),
      logline: editForm.value.logline.trim() || null,
      theme:   editForm.value.theme.trim()   || null,
      status:  editForm.value.status,
    })
    cancelEdit()
    await loadArcs()
  } catch (e) {
    error.value = e.message || 'Failed to save story arc.'
  } finally {
    saving.value = false
  }
}

// ── Delete Arc ────────────────────────────────────────────────────────────────

async function deleteArc(arc) {
  if (!confirm(`Delete story arc "${arc.name}"?`)) return
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/story-arcs/${arc.id}`)
    await loadArcs()
    if (editingArc.value?.id === arc.id) cancelEdit()
  } catch (e) {
    error.value = e.message || 'Failed to delete story arc.'
  }
}

// ── Arc Detail / Entity Roster ────────────────────────────────────────────────

const arcDetail      = ref(null)
const arcDetailLoad  = ref(false)

async function loadArcDetail(arcId) {
  arcDetailLoad.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/story-arcs/${arcId}`)
    arcDetail.value = data
  } catch (e) {
    error.value = e.message || 'Failed to load arc details.'
  } finally {
    arcDetailLoad.value = false
  }
}

// ── Entity Search & Add ──────────────────────────────────────────────────────

const entitySearch    = ref('')
const entityResults   = ref([])
const entitySearching = ref(false)
const addRole         = ref('')
let searchTimeout     = null

function onEntitySearch() {
  clearTimeout(searchTimeout)
  const q = entitySearch.value.trim()
  if (q.length < 2) { entityResults.value = []; return }
  searchTimeout = setTimeout(async () => {
    entitySearching.value = true
    try {
      const { data } = await api.get(`/api/v1/worlds/${wid}/entities?q=${encodeURIComponent(q)}&limit=10`)
      // Filter out entities already in the arc
      const existing = new Set((arcDetail.value?.entities ?? []).map(e => e.entity_id))
      entityResults.value = (data ?? []).filter(e => !existing.has(e.id))
    } catch { entityResults.value = [] }
    finally { entitySearching.value = false }
  }, 250)
}

async function addEntityToArc(entity) {
  if (!arcDetail.value || !editingArc.value) return
  const current = (arcDetail.value.entities ?? []).map(e => ({
    entity_id:  e.entity_id,
    role:       e.role ?? null,
    notes:      e.notes ?? null,
    sort_order: e.sort_order ?? 0,
  }))
  current.push({
    entity_id:  entity.id,
    role:       addRole.value.trim() || null,
    notes:      null,
    sort_order: current.length,
  })
  error.value = ''
  try {
    await api.put(`/api/v1/worlds/${wid}/story-arcs/${editingArc.value.id}/entities`, {
      entities: current,
    })
    entitySearch.value  = ''
    entityResults.value = []
    addRole.value       = ''
    await loadArcDetail(editingArc.value.id)
    await loadArcs()
  } catch (e) {
    error.value = e.message || 'Failed to add entity.'
  }
}

async function removeEntityFromArc(entityId) {
  if (!arcDetail.value || !editingArc.value) return
  const current = (arcDetail.value.entities ?? [])
    .filter(e => e.entity_id !== entityId)
    .map((e, i) => ({
      entity_id:  e.entity_id,
      role:       e.role ?? null,
      notes:      e.notes ?? null,
      sort_order: i,
    }))
  error.value = ''
  try {
    await api.put(`/api/v1/worlds/${wid}/story-arcs/${editingArc.value.id}/entities`, {
      entities: current,
    })
    await loadArcDetail(editingArc.value.id)
    await loadArcs()
  } catch (e) {
    error.value = e.message || 'Failed to remove entity.'
  }
}
</script>

<template>
  <div class="page page-wide">
    <header class="page-header">
      <h1>Story Arcs</h1>
      <div class="page-header-actions">
        <button class="btn btn-primary" @click="showCreateForm = !showCreateForm">
          {{ showCreateForm ? 'Cancel' : '+ New Arc' }}
        </button>
      </div>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Create Form -->
    <div v-if="showCreateForm" class="arc-form-panel">
      <h2>New Story Arc</h2>
      <form @submit.prevent="submitCreate" class="arc-form">
        <label>
          Name
          <input v-model="createForm.name" type="text" required maxlength="255" placeholder="Arc name" />
        </label>
        <label>
          Logline <small>(short summary)</small>
          <textarea v-model="createForm.logline" rows="3" maxlength="512" placeholder="A brief summary…"></textarea>
        </label>
        <label>
          Theme
          <input v-model="createForm.theme" type="text" maxlength="255" placeholder="e.g. Betrayal, Redemption" />
        </label>
        <label>
          Initial status
          <select v-model="createForm.status">
            <option v-for="col in COLUMNS" :key="col.key" :value="col.key">{{ col.label }}</option>
          </select>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="creating">
            {{ creating ? 'Creating…' : 'Create Arc' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="resetCreateForm">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Edit Panel (two-column: form left, entities right) -->
    <div v-if="editingArc" class="arc-form-panel">
      <h2>Edit: {{ editingArc.name }}</h2>
      <div class="arc-edit-grid">
        <form @submit.prevent="submitEdit" class="arc-form">
          <label>
            Name
            <input v-model="editForm.name" type="text" required maxlength="255" />
          </label>
          <label>
            Logline
            <textarea v-model="editForm.logline" rows="3" maxlength="512"></textarea>
          </label>
          <label>
            Theme
            <input v-model="editForm.theme" type="text" maxlength="255" />
          </label>
          <label>
            Status
            <select v-model="editForm.status">
              <option v-for="col in COLUMNS" :key="col.key" :value="col.key">{{ col.label }}</option>
            </select>
          </label>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" :disabled="saving">
              {{ saving ? 'Saving…' : 'Save Changes' }}
            </button>
            <button type="button" class="btn btn-ghost" @click="cancelEdit">Cancel</button>
          </div>
        </form>

        <!-- Entity roster (right column) -->
        <div class="arc-entities-col">
          <div v-if="arcDetail" class="arc-entities">
            <h3>Entities in this arc</h3>
            <ul v-if="arcDetail.entities?.length" class="arc-entity-list">
              <li v-for="ent in arcDetail.entities" :key="ent.entity_id" class="arc-entity-item">
                <RouterLink :to="`/worlds/${wid}/entities/${ent.entity_id}`" class="arc-entity-link">
                  {{ ent.entity_name }}
                </RouterLink>
                <span class="badge">{{ ent.entity_type }}</span>
                <span v-if="ent.role" class="badge badge-role">{{ ent.role }}</span>
                <button
                  class="btn btn-ghost btn-sm arc-entity-remove"
                  title="Remove from arc"
                  @click="removeEntityFromArc(ent.entity_id)"
                >✕</button>
              </li>
            </ul>
            <p v-else class="empty-state-sm">No entities assigned yet.</p>

            <!-- Add entity -->
            <div class="arc-add-entity">
              <h3>Add entity</h3>
              <div class="arc-add-entity-row">
                <input
                  v-model="entitySearch"
                  type="text"
                  placeholder="Search entities…"
                  @input="onEntitySearch"
                />
                <input
                  v-model="addRole"
                  type="text"
                  placeholder="Role (optional)"
                  maxlength="128"
                  style="max-width: 140px;"
                />
              </div>
              <ul v-if="entityResults.length" class="arc-search-results">
                <li v-for="ent in entityResults" :key="ent.id" class="arc-search-item" @click="addEntityToArc(ent)">
                  <span>{{ ent.name }}</span>
                  <span class="badge">{{ ent.type }}</span>
                </li>
              </ul>
              <p v-else-if="entitySearching" class="empty-state-sm">Searching…</p>
              <p v-else-if="entitySearch.length >= 2 && !entityResults.length" class="empty-state-sm">No matching entities.</p>
            </div>
          </div>
          <p v-else-if="arcDetailLoad" class="loading">Loading details…</p>
        </div>
      </div>
    </div>

    <p v-if="loading" class="loading">Loading…</p>

    <p v-else-if="!arcs.length && !showCreateForm" class="empty-state">
      No story arcs yet. Click <strong>+ New Arc</strong> to create one.
    </p>

    <div v-else class="kanban-board">
      <div
        v-for="col in COLUMNS"
        :key="col.key"
        class="kanban-col"
        @dragover="onDragOver"
        @drop="onDrop($event, col.key)"
      >
        <h3 class="kanban-col-header">
          {{ col.label }}
          <span class="badge">{{ grouped[col.key].length }}</span>
        </h3>

        <div class="kanban-cards">
          <div
            v-for="arc in grouped[col.key]"
            :key="arc.id"
            class="kanban-card"
            :class="{ 'kanban-card-active': editingArc?.id === arc.id }"
            draggable="true"
            role="listitem"
            :aria-label="`${arc.name} — drag to change status`"
            @dragstart="onDragStart(arc)"
          >
            <div class="kanban-card-header">
              <div class="kanban-card-title" @click="startEdit(arc)" style="cursor: pointer">{{ arc.name }}</div>
              <button
                class="btn btn-ghost btn-sm kanban-card-delete"
                title="Delete arc"
                @click.stop="deleteArc(arc)"
              >✕</button>
            </div>
            <p v-if="arc.logline" class="kanban-card-logline">{{ arc.logline }}</p>
            <div v-if="arc.theme" class="kanban-card-meta">Theme: {{ arc.theme }}</div>
            <div class="kanban-card-actions">
              <button class="btn btn-ghost btn-sm" @click.stop="startEdit(arc)">Edit</button>
            </div>
          </div>

          <p v-if="!grouped[col.key].length" class="kanban-empty">—</p>
        </div>
      </div>
    </div>
  </div>
</template>
