<script setup>
/**
 * TimelineView — vis-timeline wrapper with full CRUD.
 *
 * Features:
 * - List, create, edit, delete timelines
 * - List, create, edit, delete timeline events
 * - Events displayed on vis-timeline ordered by position_order
 * - Groups by era when scale_mode = 'era'
 * - Drag items to reorder → PUT reorder endpoint
 */
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { Timeline, DataSet } from 'vis-timeline/standalone'
import { api } from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const timelines      = ref([])
const selectedId     = ref(null)
const events         = ref([])
const currentTimeline = ref(null)
const loading        = ref(false)
const error          = ref('')
const container      = ref(null)
let   tlInstance     = null

async function loadTimelines() {
  loading.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/timelines`)
    timelines.value = data ?? []
    if (timelines.value.length && !selectedId.value) {
      selectedId.value = timelines.value[0].id
    }
  } catch (e) {
    error.value = e.message || 'Failed to load timelines.'
  } finally {
    loading.value = false
  }
}

onMounted(loadTimelines)

watch(selectedId, async (tid) => {
  if (!tid) return
  await loadTimelineDetail(tid)
})

async function loadTimelineDetail(tid) {
  loading.value = true
  error.value   = ''
  try {
    const [tlRes, evRes] = await Promise.all([
      api.get(`/api/v1/worlds/${wid}/timelines/${tid}`),
      api.get(`/api/v1/worlds/${wid}/timelines/${tid}/events`),
    ])
    currentTimeline.value = tlRes.data
    events.value          = evRes.data ?? []
    await nextTick()
    await renderTimeline()
  } catch (e) {
    error.value = e.message || 'Failed to load timeline events.'
  } finally {
    loading.value = false
  }
}

async function renderTimeline() {
  tlInstance?.destroy()
  tlInstance = null

  await new Promise(resolve => setTimeout(resolve, 0))

  const tl = currentTimeline.value
  if (!tl || !container.value) return

  const isEra = tl.scale_mode === 'era'

  // Build vis-timeline items and optional groups
  let groups = null
  const items = new DataSet(
    events.value.map((ev, idx) => {
      const content = ev.label ?? ev.title ?? `Event ${ev.id}`
      const item = {
        id:      ev.id,
        content,
        title:   ev.description ?? content,
        // For era-based timelines, use position_order as x-axis proxy
        start:   ev.position_value ?? ev.position_order ?? idx,
        end:     undefined,
      }
      if (isEra && ev.position_era) {
        item.group = ev.position_era
      }
      return item
    })
  )

  if (isEra) {
    const eras = [...new Set(events.value.map(e => e.position_era).filter(Boolean))]
    groups = new DataSet(eras.map(era => ({ id: era, content: era })))
  }

  const options = {
    orientation: 'top',
    moveable:    true,
    zoomable:    true,
    selectable:  true,
    editable:    { updateTime: true, updateGroup: false, remove: false },
    onMove: handleReorder,
  }

  tlInstance = groups
    ? new Timeline(container.value, items, groups, options)
    : new Timeline(container.value, items, options)
}

async function handleReorder(item, callback) {
  // Recompute position_order for all items based on their current start positions
  const allItems = tlInstance.itemsData.get({ order: 'start' })
  const ordered  = allItems.map((it, idx) => ({ id: it.id, position_order: idx }))

  try {
    await api.put(`/api/v1/worlds/${wid}/timelines/${selectedId.value}/events/reorder`, {
      order: ordered,
    })
    callback(item)  // confirm move in vis-timeline
  } catch (e) {
    error.value = e.message || 'Failed to save new order.'
    callback(null)  // revert move
  }
}

onBeforeUnmount(() => {
  tlInstance?.destroy()
  tlInstance = null
})

// ── Create Timeline ──────────────────────────────────────────────────────────

const showCreateTl = ref(false)
const createTlForm = ref({ name: '', description: '', scale_mode: 'era', color: '#4A90A4' })
const creatingTl   = ref(false)

function resetCreateTl() {
  createTlForm.value = { name: '', description: '', scale_mode: 'era', color: '#4A90A4' }
  showCreateTl.value = false
}

async function submitCreateTl() {
  if (!createTlForm.value.name.trim()) return
  creatingTl.value = true
  error.value      = ''
  try {
    const { data } = await api.post(`/api/v1/worlds/${wid}/timelines`, {
      name:        createTlForm.value.name.trim(),
      description: createTlForm.value.description.trim() || null,
      scale_mode:  createTlForm.value.scale_mode,
      color:       createTlForm.value.color,
    })
    resetCreateTl()
    await loadTimelines()
    selectedId.value = data.id
  } catch (e) {
    error.value = e.message || 'Failed to create timeline.'
  } finally {
    creatingTl.value = false
  }
}

// ── Edit Timeline ────────────────────────────────────────────────────────────

const showEditTl = ref(false)
const editTlForm = ref({ name: '', description: '', scale_mode: 'era', color: '#4A90A4' })
const savingTl   = ref(false)

function startEditTl() {
  if (!currentTimeline.value) return
  const tl = currentTimeline.value
  editTlForm.value = {
    name:        tl.name        ?? '',
    description: tl.description ?? '',
    scale_mode:  tl.scale_mode  ?? 'era',
    color:       tl.color       ?? '#4A90A4',
  }
  showEditTl.value = true
}

function cancelEditTl() {
  showEditTl.value = false
}

async function submitEditTl() {
  if (!selectedId.value || !editTlForm.value.name.trim()) return
  savingTl.value = true
  error.value    = ''
  try {
    await api.patch(`/api/v1/worlds/${wid}/timelines/${selectedId.value}`, {
      name:        editTlForm.value.name.trim(),
      description: editTlForm.value.description.trim() || null,
      scale_mode:  editTlForm.value.scale_mode,
      color:       editTlForm.value.color,
    })
    showEditTl.value = false
    await loadTimelines()
    await loadTimelineDetail(selectedId.value)
  } catch (e) {
    error.value = e.message || 'Failed to save timeline.'
  } finally {
    savingTl.value = false
  }
}

// ── Delete Timeline ──────────────────────────────────────────────────────────

async function deleteTimeline() {
  if (!selectedId.value || !currentTimeline.value) return
  if (!confirm(`Delete timeline "${currentTimeline.value.name}"? All events will also be removed.`)) return
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/timelines/${selectedId.value}`)
    selectedId.value      = null
    currentTimeline.value = null
    events.value          = []
    tlInstance?.destroy()
    tlInstance = null
    await loadTimelines()
  } catch (e) {
    error.value = e.message || 'Failed to delete timeline.'
  }
}

// ── Create Event ─────────────────────────────────────────────────────────────

const showCreateEv = ref(false)
const createEvForm = ref({ label: '', description: '', position_era: '', position_label: '', color: '' })
const creatingEv   = ref(false)

function resetCreateEv() {
  createEvForm.value = { label: '', description: '', position_era: '', position_label: '', color: '' }
  showCreateEv.value = false
}

async function submitCreateEv() {
  if (!selectedId.value || !createEvForm.value.label.trim()) return
  creatingEv.value = true
  error.value      = ''
  try {
    await api.post(`/api/v1/worlds/${wid}/timelines/${selectedId.value}/events`, {
      label:          createEvForm.value.label.trim(),
      description:    createEvForm.value.description.trim() || null,
      position_era:   createEvForm.value.position_era.trim() || null,
      position_label: createEvForm.value.position_label.trim() || null,
      color:          createEvForm.value.color || null,
    })
    resetCreateEv()
    await loadTimelineDetail(selectedId.value)
  } catch (e) {
    error.value = e.message || 'Failed to create event.'
  } finally {
    creatingEv.value = false
  }
}

// ── Edit Event ───────────────────────────────────────────────────────────────

const editingEvent = ref(null)
const editEvForm   = ref({ label: '', description: '', position_era: '', position_label: '', color: '' })
const savingEv     = ref(false)

function startEditEv(ev) {
  editingEvent.value = ev
  editEvForm.value   = {
    label:          ev.label          ?? '',
    description:    ev.description    ?? '',
    position_era:   ev.position_era   ?? '',
    position_label: ev.position_label ?? '',
    color:          ev.color          ?? '',
  }
}

function cancelEditEv() {
  editingEvent.value = null
}

async function submitEditEv() {
  if (!editingEvent.value || !editEvForm.value.label.trim()) return
  savingEv.value = true
  error.value    = ''
  try {
    await api.patch(
      `/api/v1/worlds/${wid}/timelines/${selectedId.value}/events/${editingEvent.value.id}`,
      {
        label:          editEvForm.value.label.trim(),
        description:    editEvForm.value.description.trim() || null,
        position_era:   editEvForm.value.position_era.trim() || null,
        position_label: editEvForm.value.position_label.trim() || null,
        color:          editEvForm.value.color || null,
      }
    )
    cancelEditEv()
    await loadTimelineDetail(selectedId.value)
  } catch (e) {
    error.value = e.message || 'Failed to save event.'
  } finally {
    savingEv.value = false
  }
}

// ── Delete Event ─────────────────────────────────────────────────────────────

async function deleteEvent(ev) {
  if (!confirm(`Delete event "${ev.label}"?`)) return
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/timelines/${selectedId.value}/events/${ev.id}`)
    if (editingEvent.value?.id === ev.id) cancelEditEv()
    await loadTimelineDetail(selectedId.value)
  } catch (e) {
    error.value = e.message || 'Failed to delete event.'
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Timelines</h1>
      <div class="page-header-actions">
        <button class="btn btn-primary" @click="showCreateTl = !showCreateTl">
          {{ showCreateTl ? 'Cancel' : '+ New Timeline' }}
        </button>
      </div>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Create Timeline Form -->
    <div v-if="showCreateTl" class="tl-form-panel">
      <h2>New Timeline</h2>
      <form @submit.prevent="submitCreateTl" class="tl-form">
        <label>
          Name
          <input v-model="createTlForm.name" type="text" required maxlength="255" placeholder="Timeline name" />
        </label>
        <label>
          Description
          <textarea v-model="createTlForm.description" rows="3" maxlength="5000" placeholder="Optional description…"></textarea>
        </label>
        <div class="tl-form-row">
          <label>
            Scale mode
            <select v-model="createTlForm.scale_mode">
              <option value="era">Era (custom labels)</option>
              <option value="numeric">Numeric</option>
              <option value="date">Date</option>
            </select>
          </label>
          <label>
            Color
            <input v-model="createTlForm.color" type="color" />
          </label>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="creatingTl">
            {{ creatingTl ? 'Creating…' : 'Create Timeline' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="resetCreateTl">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Edit Timeline Form -->
    <div v-if="showEditTl" class="tl-form-panel">
      <h2>Edit Timeline</h2>
      <form @submit.prevent="submitEditTl" class="tl-form">
        <label>
          Name
          <input v-model="editTlForm.name" type="text" required maxlength="255" />
        </label>
        <label>
          Description
          <textarea v-model="editTlForm.description" rows="3" maxlength="5000"></textarea>
        </label>
        <div class="tl-form-row">
          <label>
            Scale mode
            <select v-model="editTlForm.scale_mode">
              <option value="era">Era (custom labels)</option>
              <option value="numeric">Numeric</option>
              <option value="date">Date</option>
            </select>
          </label>
          <label>
            Color
            <input v-model="editTlForm.color" type="color" />
          </label>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="savingTl">
            {{ savingTl ? 'Saving…' : 'Save Changes' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="cancelEditTl">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Timeline selector + actions -->
    <div v-if="timelines.length" class="tl-header-bar">
      <label style="flex: 0 0 auto; margin-bottom: 0;">
        <select v-model="selectedId" style="max-width: 280px;">
          <option v-for="tl in timelines" :key="tl.id" :value="tl.id">{{ tl.name }}</option>
        </select>
      </label>
      <button class="btn btn-ghost btn-sm" @click="startEditTl" :disabled="!selectedId" title="Edit timeline">Edit</button>
      <button class="btn btn-danger btn-sm" @click="deleteTimeline" :disabled="!selectedId" title="Delete timeline">Delete</button>
      <button class="btn btn-secondary btn-sm" @click="showCreateEv = !showCreateEv" :disabled="!selectedId">
        {{ showCreateEv ? 'Cancel' : '+ Add Event' }}
      </button>
    </div>

    <p v-else-if="!loading && !showCreateTl" class="empty-state">
      No timelines yet. Click <strong>+ New Timeline</strong> to create one.
    </p>

    <p v-if="loading" class="loading">Loading…</p>

    <!-- Timeline metadata -->
    <div v-if="currentTimeline && !loading" class="tl-meta">
      <span>Scale: {{ currentTimeline.scale_mode }}</span>
      <span v-if="currentTimeline.description">{{ currentTimeline.description }}</span>
    </div>

    <!-- Create Event Form -->
    <div v-if="showCreateEv && selectedId" class="tl-form-panel">
      <h2>New Event</h2>
      <form @submit.prevent="submitCreateEv" class="tl-form">
        <label>
          Label
          <input v-model="createEvForm.label" type="text" required maxlength="255" placeholder="Event label" />
        </label>
        <label>
          Description
          <textarea v-model="createEvForm.description" rows="2" maxlength="5000" placeholder="Optional description…"></textarea>
        </label>
        <div class="tl-form-row">
          <label v-if="currentTimeline?.scale_mode === 'era'">
            Era
            <input v-model="createEvForm.position_era" type="text" maxlength="128" placeholder="e.g. Age of Zot" />
          </label>
          <label>
            Position label
            <input v-model="createEvForm.position_label" type="text" maxlength="64" placeholder="e.g. Year 412" />
          </label>
        </div>
        <label>
          Color <small>(optional)</small>
          <input v-model="createEvForm.color" type="color" />
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="creatingEv">
            {{ creatingEv ? 'Creating…' : 'Add Event' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="resetCreateEv">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Edit Event Form -->
    <div v-if="editingEvent" class="tl-form-panel">
      <h2>Edit Event: {{ editingEvent.label }}</h2>
      <form @submit.prevent="submitEditEv" class="tl-form">
        <label>
          Label
          <input v-model="editEvForm.label" type="text" required maxlength="255" />
        </label>
        <label>
          Description
          <textarea v-model="editEvForm.description" rows="2" maxlength="5000"></textarea>
        </label>
        <div class="tl-form-row">
          <label v-if="currentTimeline?.scale_mode === 'era'">
            Era
            <input v-model="editEvForm.position_era" type="text" maxlength="128" />
          </label>
          <label>
            Position label
            <input v-model="editEvForm.position_label" type="text" maxlength="64" />
          </label>
        </div>
        <label>
          Color
          <input v-model="editEvForm.color" type="color" />
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="savingEv">
            {{ savingEv ? 'Saving…' : 'Save' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="cancelEditEv">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Vis-Timeline -->
    <div
      v-show="currentTimeline && !loading"
      ref="container"
      class="timeline-container"
      role="img"
      aria-label="Timeline visualization"
    ></div>

    <!-- Event List (table view for management) -->
    <div v-if="events.length && currentTimeline && !loading">
      <h3 style="font-size: .9rem; color: var(--color-muted); margin: 1rem 0 .5rem;">Events ({{ events.length }})</h3>
      <ul class="tl-event-list">
        <li v-for="ev in events" :key="ev.id" class="tl-event-item" :class="{ 'kanban-card-active': editingEvent?.id === ev.id }">
          <div class="tl-event-info">
            <div class="tl-event-label">{{ ev.label }}</div>
            <div v-if="ev.description" class="tl-event-desc">{{ ev.description }}</div>
            <div class="tl-event-meta">
              <span v-if="ev.position_era" class="badge">{{ ev.position_era }}</span>
              <span v-if="ev.position_label" class="badge">{{ ev.position_label }}</span>
            </div>
          </div>
          <div class="tl-event-actions">
            <button class="btn btn-ghost btn-sm" @click="startEditEv(ev)" title="Edit event">Edit</button>
            <button class="btn btn-danger btn-sm" @click="deleteEvent(ev)" title="Delete event">✕</button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
