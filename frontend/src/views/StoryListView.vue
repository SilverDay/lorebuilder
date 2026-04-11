<script setup>
/**
 * StoryListView — list, create, and manage stories within a world.
 *
 * Features: paginated table, sort, filter by status/arc, inline create form.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const route  = useRoute()
const router = useRouter()
const wid    = route.params.wid

const stories = ref([])
const total   = ref(0)
const loading = ref(false)
const error   = ref('')
const page    = ref(1)
const perPage = 30
const sort    = ref('updated_at')
const order   = ref('desc')

// Filters
const filterStatus = ref('')
const filterArc    = ref('')

// Arcs for filter dropdown + create form
const arcs = ref([])

const STATUSES = [
  { key: 'draft',      label: 'Draft' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'review',     label: 'Review' },
  { key: 'final',      label: 'Final' },
  { key: 'abandoned',  label: 'Abandoned' },
]

async function loadStories() {
  loading.value = true
  error.value   = ''
  try {
    const params = {
      page: page.value,
      per_page: perPage,
      sort: sort.value,
      order: order.value,
    }
    if (filterStatus.value) params.status = filterStatus.value
    if (filterArc.value)    params.arc_id = filterArc.value
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/stories`, params)
    stories.value = data ?? []
    total.value   = meta?.total ?? stories.value.length
  } catch (e) {
    error.value = e.message || 'Failed to load stories.'
  } finally {
    loading.value = false
  }
}

async function loadArcs() {
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/story-arcs`)
    arcs.value = data ?? []
  } catch {
    // Non-critical
  }
}

onMounted(() => { loadStories(); loadArcs() })
watch([filterStatus, filterArc], () => { page.value = 1; loadStories() })
watch(page, loadStories)

const totalPages = computed(() => Math.ceil(Math.max(1, total.value) / perPage))

function toggleSort(field) {
  if (sort.value === field) {
    order.value = order.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = field
    order.value = field === 'title' ? 'asc' : 'desc'
  }
  page.value = 1
  loadStories()
}

function sortIcon(field) {
  if (sort.value !== field) return ''
  return order.value === 'asc' ? ' ▲' : ' ▼'
}

function statusLabel(key) {
  return STATUSES.find(s => s.key === key)?.label ?? key
}

// ── Create Form ─────────────────────────────────────────────────────────

const showCreate = ref(false)
const createForm = ref({ title: '', status: 'draft', arc_id: '' })
const creating   = ref(false)

function resetCreate() {
  createForm.value = { title: '', status: 'draft', arc_id: '' }
  showCreate.value = false
}

async function submitCreate() {
  const title = createForm.value.title.trim()
  if (!title) return
  creating.value = true
  error.value    = ''
  try {
    const payload = { title, status: createForm.value.status }
    if (createForm.value.arc_id) payload.arc_id = Number(createForm.value.arc_id)
    const { data } = await api.post(`/api/v1/worlds/${wid}/stories`, payload)
    resetCreate()
    // Navigate to the new story
    router.push(`/worlds/${wid}/stories/${data.id}`)
  } catch (e) {
    error.value = e.message || 'Failed to create story.'
  } finally {
    creating.value = false
  }
}

// ── Delete ──────────────────────────────────────────────────────────────

async function deleteStory(story) {
  if (!confirm(`Delete story "${story.title}"?`)) return
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/stories/${story.id}`)
    await loadStories()
  } catch (e) {
    error.value = e.message || 'Failed to delete story.'
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Stories</h1>
      <div class="page-header-actions">
        <button class="btn btn-primary" @click="showCreate = !showCreate">
          {{ showCreate ? 'Cancel' : '+ New Story' }}
        </button>
      </div>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Create Form -->
    <div v-if="showCreate" class="arc-form-panel">
      <h2>New Story</h2>
      <form @submit.prevent="submitCreate" class="arc-form">
        <label>
          Title
          <input v-model="createForm.title" type="text" required maxlength="255" placeholder="Story title" />
        </label>
        <label>
          Status
          <select v-model="createForm.status">
            <option v-for="s in STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
          </select>
        </label>
        <label>
          Story Arc <small>(optional)</small>
          <select v-model="createForm.arc_id">
            <option value="">None</option>
            <option v-for="arc in arcs" :key="arc.id" :value="arc.id">{{ arc.name }}</option>
          </select>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="creating">
            {{ creating ? 'Creating…' : 'Create Story' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="resetCreate">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
      <select v-model="filterStatus" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option v-for="s in STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
      </select>
      <select v-model="filterArc" aria-label="Filter by arc">
        <option value="">All arcs</option>
        <option v-for="arc in arcs" :key="arc.id" :value="arc.id">{{ arc.name }}</option>
      </select>
    </div>

    <p v-if="loading" class="loading">Loading…</p>

    <template v-else>
      <div v-if="stories.length" class="story-list">
        <table class="story-table">
          <thead>
            <tr>
              <th class="story-table__sortable" @click="toggleSort('title')">
                Title{{ sortIcon('title') }}
              </th>
              <th class="story-table__sortable" @click="toggleSort('status')">
                Status{{ sortIcon('status') }}
              </th>
              <th>Arc</th>
              <th class="story-table__sortable story-table__num" @click="toggleSort('word_count')">
                Words{{ sortIcon('word_count') }}
              </th>
              <th>Entities</th>
              <th class="story-table__sortable" @click="toggleSort('updated_at')">
                Updated{{ sortIcon('updated_at') }}
              </th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="story in stories" :key="story.id" class="story-table__row" @click="router.push(`/worlds/${wid}/stories/${story.id}`)">
              <td class="story-table__title">{{ story.title }}</td>
              <td><span class="badge" :class="`badge-status-${story.status}`">{{ statusLabel(story.status) }}</span></td>
              <td>{{ story.arc_name || '—' }}</td>
              <td class="story-table__num">{{ (story.word_count ?? 0).toLocaleString() }}</td>
              <td class="story-table__num">{{ story.entity_count ?? 0 }}</td>
              <td class="story-table__date">{{ story.updated_at }}</td>
              <td class="story-table__actions" @click.stop>
                <button class="btn btn-ghost btn-sm" title="Delete" @click="deleteStory(story)">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="!showCreate" class="empty-state">
        No stories yet. Click <strong>+ New Story</strong> to start writing.
      </p>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="page === 1" @click="page--">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <button :disabled="page >= totalPages" @click="page++">Next</button>
      </div>
    </template>
  </div>
</template>
