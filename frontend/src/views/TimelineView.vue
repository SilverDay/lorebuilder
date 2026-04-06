<script setup>
/**
 * TimelineView — vis-timeline wrapper.
 *
 * Features:
 * - Lists all timelines for the world; user selects one to view
 * - Events displayed on vis-timeline ordered by position_order
 * - Groups by era when scale_mode = 'era'
 * - Drag items to reorder → PUT reorder endpoint
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
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

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/timelines`)
    timelines.value = data ?? []
    if (timelines.value.length) {
      selectedId.value = timelines.value[0].id
    }
  } catch (e) {
    error.value = e.message || 'Failed to load timelines.'
  } finally {
    loading.value = false
  }
})

watch(selectedId, async (tid) => {
  if (!tid) return
  loading.value = true
  error.value   = ''
  try {
    const [tlRes, evRes] = await Promise.all([
      api.get(`/api/v1/worlds/${wid}/timelines/${tid}`),
      api.get(`/api/v1/worlds/${wid}/timelines/${tid}/events`),
    ])
    currentTimeline.value = tlRes.data
    events.value          = evRes.data ?? []
    await renderTimeline()
  } catch (e) {
    error.value = e.message || 'Failed to load timeline events.'
  } finally {
    loading.value = false
  }
})

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
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Timelines</h1>
      <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Back</RouterLink>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <div v-if="timelines.length" class="timeline-selector">
      <label>
        Select timeline
        <select v-model="selectedId">
          <option v-for="tl in timelines" :key="tl.id" :value="tl.id">{{ tl.name }}</option>
        </select>
      </label>
    </div>

    <p v-else-if="!loading" class="empty-state">No timelines yet.</p>

    <p v-if="loading" class="loading">Loading…</p>

    <div
      v-show="currentTimeline && !loading"
      ref="container"
      class="timeline-container"
    ></div>
  </div>
</template>
