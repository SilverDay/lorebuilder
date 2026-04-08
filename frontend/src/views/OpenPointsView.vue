<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

// ── List state ───────────────────────────────────────────────────────────────
const points    = ref([])
const total     = ref(0)
const loading   = ref(false)
const error     = ref('')
const page      = ref(1)
const perPage   = 20
const statusFilter   = ref('open')
const priorityFilter = ref('')

const STATUSES = ['open', 'in_progress', 'resolved', 'wont_fix']
const STATUS_LABELS = { open: 'Open', in_progress: 'In Progress', resolved: 'Resolved', wont_fix: "Won't Fix" }
const PRIORITIES = ['low', 'medium', 'high', 'critical']
const PRIORITY_LABELS = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' }

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const params = { page: page.value, per_page: perPage }
    if (statusFilter.value)   params.status   = statusFilter.value
    if (priorityFilter.value) params.priority = priorityFilter.value
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/open-points`, params)
    points.value = data ?? []
    total.value  = meta?.total ?? points.value.length
  } catch (e) {
    error.value = e.message || 'Failed to load open points.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([statusFilter, priorityFilter], () => { page.value = 1; load() })
watch(page, load)

const totalPages = computed(() => Math.ceil(Math.max(1, total.value) / perPage))

// ── Create form ──────────────────────────────────────────────────────────────
const showCreate = ref(false)
const saving     = ref(false)
const saveError  = ref('')
const form = ref({ title: '', description: '', priority: 'medium', status: 'open', entity_id: '' })

async function createPoint() {
  if (!form.value.title.trim()) return
  saving.value    = true
  saveError.value = ''
  try {
    const payload = {
      title:       form.value.title.trim(),
      description: form.value.description.trim() || null,
      priority:    form.value.priority,
      status:      form.value.status,
      entity_id:   form.value.entity_id ? parseInt(form.value.entity_id, 10) : null,
    }
    await api.post(`/api/v1/worlds/${wid}/open-points`, payload)
    showCreate.value = false
    form.value = { title: '', description: '', priority: 'medium', status: 'open', entity_id: '' }
    page.value = 1
    await load()
  } catch (e) {
    saveError.value = e.message || 'Failed to create open point.'
  } finally {
    saving.value = false
  }
}

// ── Detail / Edit ─────────────────────────────────────────────────────────────
const selected   = ref(null)
const editMode   = ref(false)
const editForm   = ref({})
const editSaving = ref(false)
const editError  = ref('')
const detailLoading = ref(false)

async function openDetail(id) {
  detailLoading.value = true
  editMode.value  = false
  editError.value = ''
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/open-points/${id}`)
    selected.value = data
    editForm.value = {
      title:       data.title,
      description: data.description ?? '',
      status:      data.status,
      priority:    data.priority,
      resolution:  data.resolution ?? '',
    }
  } catch (e) {
    error.value = e.message || 'Failed to load open point.'
  } finally {
    detailLoading.value = false
  }
}

async function saveEdit() {
  editSaving.value = true
  editError.value  = ''
  try {
    const payload = {
      title:       editForm.value.title.trim(),
      description: editForm.value.description.trim() || null,
      status:      editForm.value.status,
      priority:    editForm.value.priority,
      resolution:  editForm.value.resolution.trim() || null,
    }
    await api.patch(`/api/v1/worlds/${wid}/open-points/${selected.value.id}`, payload)
    editMode.value = false
    await openDetail(selected.value.id)
    await load()
  } catch (e) {
    editError.value = e.message || 'Failed to save.'
  } finally {
    editSaving.value = false
  }
}

// Quick-resolve from list
async function quickResolve(point) {
  try {
    await api.patch(`/api/v1/worlds/${wid}/open-points/${point.id}`, { status: 'resolved' })
    await load()
    if (selected.value?.id === point.id) selected.value.status = 'resolved'
  } catch (e) {
    error.value = e.message || 'Failed to resolve.'
  }
}

const deleteConfirm = ref(false)
const deleteError   = ref('')

async function deletePoint() {
  deleteError.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/open-points/${selected.value.id}`)
    selected.value      = null
    deleteConfirm.value = false
    await load()
  } catch (e) {
    deleteError.value = e.message || 'Failed to delete.'
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Open Points</h1>
      <div class="page-header-actions">
        <button class="btn btn-primary" @click="showCreate = !showCreate">
          + New Open Point
        </button>
      </div>
    </header>

    <!-- Create form -->
    <section v-if="showCreate" class="settings-section">
      <h2>Add Open Point</h2>
      <form class="settings-form" @submit.prevent="createPoint">
        <label>
          Title / Question *
          <input v-model="form.title" type="text" required maxlength="512"
                 placeholder="Why did the council dissolve before the war ended?" />
        </label>
        <label>
          Context / Description
          <textarea v-model="form.description" rows="3" maxlength="4000"
                    placeholder="Provide full context so anyone can understand this open point…"></textarea>
        </label>
        <div class="form-row">
          <label>
            Priority
            <select v-model="form.priority">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
            </select>
          </label>
          <label>
            Status
            <select v-model="form.status">
              <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
            </select>
          </label>
          <label>
            Entity ID (optional)
            <input v-model="form.entity_id" type="number" min="1" placeholder="Leave blank if world-level" />
          </label>
        </div>
        <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost" @click="showCreate = false">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Saving…' : 'Add Open Point' }}
          </button>
        </div>
      </form>
    </section>

    <!-- Filters -->
    <div class="filter-bar">
      <select v-model="statusFilter">
        <option value="">All statuses</option>
        <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
      </select>
      <select v-model="priorityFilter">
        <option value="">All priorities</option>
        <option v-for="p in PRIORITIES" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
      </select>
    </div>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <div v-if="points.length" class="op-list">
        <div
          v-for="op in points"
          :key="op.id"
          class="op-card"
          :class="`op-status-${op.status}`"
          @click="openDetail(op.id)"
        >
          <div class="op-card-header">
            <span class="badge" :class="`badge-priority-${op.priority}`">{{ PRIORITY_LABELS[op.priority] }}</span>
            <span class="badge" :class="`badge-status-${op.status}`">{{ STATUS_LABELS[op.status] }}</span>
            <span class="op-card-title">{{ op.title }}</span>
          </div>
          <div v-if="op.entity_name" class="op-card-meta">Entity: {{ op.entity_name }}</div>
          <div v-if="op.description" class="op-card-desc">{{ op.description }}</div>
          <div class="op-card-footer">
            <span class="op-creator">{{ op.creator_name }}</span>
            <button
              v-if="op.status !== 'resolved' && op.status !== 'wont_fix'"
              class="btn btn-ghost btn-sm"
              @click.stop="quickResolve(op)"
            >Mark Resolved</button>
          </div>
        </div>
      </div>
      <p v-else class="empty-state">
        {{ statusFilter === 'open' ? 'No open points — all clear!' : 'No open points match this filter.' }}
      </p>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="page === 1" @click="page--">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <button :disabled="page >= totalPages" @click="page++">Next</button>
      </div>
    </template>

    <!-- Detail drawer -->
    <Teleport to="body">
      <div v-if="selected" class="drawer-backdrop" @click.self="selected = null">
        <aside class="drawer">
          <button class="drawer-close" aria-label="Close open point details" @click="selected = null">✕</button>

          <p v-if="detailLoading" class="loading">Loading…</p>
          <template v-else>
            <template v-if="!editMode">
              <div class="drawer-header">
                <span class="badge" :class="`badge-priority-${selected.priority}`">{{ PRIORITY_LABELS[selected.priority] }}</span>
                <span class="badge" :class="`badge-status-${selected.status}`">{{ STATUS_LABELS[selected.status] }}</span>
                <h2>{{ selected.title }}</h2>
              </div>
              <dl class="detail-list">
                <template v-if="selected.description">
                  <dt>Description</dt><dd>{{ selected.description }}</dd>
                </template>
                <template v-if="selected.resolution">
                  <dt>Resolution</dt><dd>{{ selected.resolution }}</dd>
                </template>
                <template v-if="selected.entity_name">
                  <dt>Linked Entity</dt>
                  <dd>
                    <RouterLink :to="`/worlds/${wid}/entities/${selected.entity_id}`">
                      {{ selected.entity_name }}
                    </RouterLink>
                  </dd>
                </template>
                <template v-if="selected.ai_session_id">
                  <dt>AI Session</dt><dd>#{{ selected.ai_session_id }}</dd>
                </template>
                <dt>Added by</dt><dd>{{ selected.creator_name }}</dd>
                <template v-if="selected.resolved_at">
                  <dt>Resolved by</dt><dd>{{ selected.resolver_name }} on {{ selected.resolved_at }}</dd>
                </template>
                <dt>Created</dt><dd>{{ selected.created_at }}</dd>
              </dl>
              <div class="drawer-actions">
                <button class="btn btn-secondary" @click="editMode = true">Edit</button>
                <button
                  v-if="selected.status !== 'resolved'"
                  class="btn btn-success"
                  @click="quickResolve(selected); selected.status = 'resolved'"
                >Mark Resolved</button>
                <button class="btn btn-danger" @click="deleteConfirm = true">Delete</button>
              </div>
              <div v-if="deleteConfirm" class="confirm-box">
                <p>Delete this open point?</p>
                <p v-if="deleteError" class="form-error">{{ deleteError }}</p>
                <button class="btn btn-danger" @click="deletePoint">Confirm Delete</button>
                <button class="btn btn-ghost" @click="deleteConfirm = false">Cancel</button>
              </div>
            </template>

            <template v-else>
              <h2>Edit Open Point</h2>
              <form class="settings-form" @submit.prevent="saveEdit">
                <label>Title<input v-model="editForm.title" type="text" required maxlength="512" /></label>
                <label>Description<textarea v-model="editForm.description" rows="3" maxlength="4000"></textarea></label>
                <div class="form-row">
                  <label>
                    Status
                    <select v-model="editForm.status">
                      <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
                    </select>
                  </label>
                  <label>
                    Priority
                    <select v-model="editForm.priority">
                      <option v-for="p in PRIORITIES" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
                    </select>
                  </label>
                </div>
                <label>
                  Resolution notes
                  <textarea v-model="editForm.resolution" rows="3" maxlength="4000"
                            placeholder="How was this resolved?"></textarea>
                </label>
                <p v-if="editError" class="form-error" role="alert">{{ editError }}</p>
                <div class="form-actions">
                  <button type="button" class="btn btn-ghost" @click="editMode = false">Cancel</button>
                  <button type="submit" class="btn btn-primary" :disabled="editSaving">
                    {{ editSaving ? 'Saving…' : 'Save' }}
                  </button>
                </div>
              </form>
            </template>
          </template>
        </aside>
      </div>
    </Teleport>
  </div>
</template>
