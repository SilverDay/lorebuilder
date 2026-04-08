<script setup>
/**
 * StoryArcKanban — Kanban board for story arc status management.
 *
 * Columns: seed → rising_action → climax → resolution → complete → abandoned
 * Drag arc cards between columns → PATCH status endpoint.
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

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/story-arcs`)
    arcs.value = data ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load story arcs.'
  } finally {
    loading.value = false
  }
})

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
</script>

<template>
  <div class="page page-wide">
    <header class="page-header">
      <h1>Story Arcs</h1>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <p v-if="loading" class="loading">Loading…</p>

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
            draggable="true"
            role="listitem"
            :aria-label="`${arc.name} — drag to change status`"
            @dragstart="onDragStart(arc)"
          >
            <div class="kanban-card-title">{{ arc.name }}</div>
            <p v-if="arc.logline" class="kanban-card-logline">{{ arc.logline }}</p>
            <div v-if="arc.theme" class="kanban-card-meta">Theme: {{ arc.theme }}</div>
          </div>

          <p v-if="!grouped[col.key].length" class="kanban-empty">—</p>
        </div>
      </div>
    </div>
  </div>
</template>
