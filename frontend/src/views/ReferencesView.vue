<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

// ── List state ───────────────────────────────────────────────────────────────
const refs      = ref([])
const total     = ref(0)
const loading   = ref(false)
const error     = ref('')
const page      = ref(1)
const perPage   = 20
const typeFilter = ref('')
const searchQ   = ref('')

const TYPE_LABELS = {
  url: 'URL', book: 'Book', article: 'Article',
  film: 'Film', podcast: 'Podcast', other: 'Other',
}
const TYPES = ['', 'url', 'book', 'article', 'film', 'podcast', 'other']

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const params = { page: page.value, per_page: perPage }
    if (typeFilter.value) params.type   = typeFilter.value
    if (searchQ.value)    params.search = searchQ.value
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/references`, params)
    refs.value  = data ?? []
    total.value = meta?.total ?? refs.value.length
  } catch (e) {
    error.value = e.message || 'Failed to load references.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([typeFilter, searchQ], () => { page.value = 1; load() })
watch(page, load)

const totalPages = computed(() => Math.ceil(Math.max(1, total.value) / perPage))

// ── Create form ──────────────────────────────────────────────────────────────
const showCreate = ref(false)
const saving     = ref(false)
const saveError  = ref('')
const form = ref({ ref_type: 'url', title: '', url: '', author: '', description: '', tags: '' })

async function createRef() {
  if (!form.value.title.trim()) return
  saving.value    = true
  saveError.value = ''
  try {
    const payload = {
      ref_type: form.value.ref_type,
      title:    form.value.title.trim(),
      url:      form.value.url.trim()         || null,
      author:   form.value.author.trim()      || null,
      description: form.value.description.trim() || null,
      tags:     form.value.tags.trim()
        ? form.value.tags.split(',').map(t => t.trim()).filter(Boolean)
        : null,
    }
    await api.post(`/api/v1/worlds/${wid}/references`, payload)
    showCreate.value = false
    form.value = { ref_type: 'url', title: '', url: '', author: '', description: '', tags: '' }
    page.value = 1
    await load()
  } catch (e) {
    saveError.value = e.message || 'Failed to create reference.'
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
    const { data } = await api.get(`/api/v1/worlds/${wid}/references/${id}`)
    selected.value = data
    editForm.value = {
      ref_type:    data.ref_type,
      title:       data.title,
      url:         data.url ?? '',
      author:      data.author ?? '',
      description: data.description ?? '',
      tags:        Array.isArray(data.tags) ? data.tags.join(', ') : (data.tags ?? ''),
    }
  } catch (e) {
    error.value = e.message || 'Failed to load reference.'
  } finally {
    detailLoading.value = false
  }
}

async function saveEdit() {
  editSaving.value = true
  editError.value  = ''
  try {
    const payload = {
      ref_type:    editForm.value.ref_type,
      title:       editForm.value.title.trim(),
      url:         editForm.value.url.trim()         || null,
      author:      editForm.value.author.trim()      || null,
      description: editForm.value.description.trim() || null,
      tags:        editForm.value.tags.trim()
        ? editForm.value.tags.split(',').map(t => t.trim()).filter(Boolean)
        : null,
    }
    await api.patch(`/api/v1/worlds/${wid}/references/${selected.value.id}`, payload)
    editMode.value = false
    await openDetail(selected.value.id)
    await load()
  } catch (e) {
    editError.value = e.message || 'Failed to save.'
  } finally {
    editSaving.value = false
  }
}

const deleteConfirm = ref(false)
const deleteError   = ref('')

async function deleteRef() {
  deleteError.value = ''
  try {
    await api.delete(`/api/v1/worlds/${wid}/references/${selected.value.id}`)
    selected.value    = null
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
      <h1>References</h1>
      <div class="page-header-actions">
        <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Dashboard</RouterLink>
        <button class="btn btn-primary" @click="showCreate = !showCreate">
          + New Reference
        </button>
      </div>
    </header>

    <!-- Create form -->
    <section v-if="showCreate" class="settings-section">
      <h2>Add Reference</h2>
      <form class="settings-form" @submit.prevent="createRef">
        <div class="form-row">
          <label>
            Type
            <select v-model="form.ref_type" required>
              <option v-for="t in TYPES.slice(1)" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
            </select>
          </label>
          <label style="flex:3">
            Title *
            <input v-model="form.title" type="text" required maxlength="512" placeholder="e.g. Wikipedia — Norse Mythology" />
          </label>
        </div>
        <label>
          URL
          <input v-model="form.url" type="url" maxlength="2048" placeholder="https://…" />
        </label>
        <label>
          Author / Creator
          <input v-model="form.author" type="text" maxlength="255" placeholder="J.R.R. Tolkien" />
        </label>
        <label>
          Why relevant (description)
          <textarea v-model="form.description" rows="3" maxlength="4000"></textarea>
        </label>
        <label>
          Tags (comma-separated)
          <input v-model="form.tags" type="text" placeholder="mythology, lore, inspiration" />
        </label>
        <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost" @click="showCreate = false">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Saving…' : 'Add Reference' }}
          </button>
        </div>
      </form>
    </section>

    <!-- Filters -->
    <div class="filter-bar">
      <input v-model="searchQ" type="search" placeholder="Search titles and descriptions…" />
      <select v-model="typeFilter">
        <option value="">All types</option>
        <option v-for="t in TYPES.slice(1)" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
      </select>
    </div>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <div v-if="refs.length" class="ref-list">
        <div
          v-for="r in refs"
          :key="r.id"
          class="ref-card"
          @click="openDetail(r.id)"
        >
          <div class="ref-card-header">
            <span class="badge" :class="`badge-type-${r.ref_type}`">{{ TYPE_LABELS[r.ref_type] ?? r.ref_type }}</span>
            <span class="ref-card-title">{{ r.title }}</span>
          </div>
          <div v-if="r.author" class="ref-card-meta">By {{ r.author }}</div>
          <div v-if="r.description" class="ref-card-desc">{{ r.description }}</div>
          <div v-if="r.url" class="ref-card-url">
            <a :href="r.url" target="_blank" rel="noopener noreferrer" @click.stop>{{ r.url }}</a>
          </div>
        </div>
      </div>
      <p v-else class="empty-state">No references yet. Add your first research source.</p>

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
          <button class="drawer-close" aria-label="Close reference details" @click="selected = null">✕</button>

          <p v-if="detailLoading" class="loading">Loading…</p>
          <template v-else>
            <template v-if="!editMode">
              <div class="drawer-header">
                <span class="badge" :class="`badge-type-${selected.ref_type}`">{{ TYPE_LABELS[selected.ref_type] }}</span>
                <h2>{{ selected.title }}</h2>
              </div>
              <dl class="detail-list">
                <template v-if="selected.url">
                  <dt>URL</dt>
                  <dd><a :href="selected.url" target="_blank" rel="noopener noreferrer">{{ selected.url }}</a></dd>
                </template>
                <template v-if="selected.author">
                  <dt>Author</dt><dd>{{ selected.author }}</dd>
                </template>
                <template v-if="selected.description">
                  <dt>Description</dt><dd>{{ selected.description }}</dd>
                </template>
                <template v-if="selected.tags">
                  <dt>Tags</dt>
                  <dd>
                    <span
                      v-for="tag in (Array.isArray(selected.tags) ? selected.tags : JSON.parse(selected.tags || '[]'))"
                      :key="tag"
                      class="tag-chip"
                    >{{ tag }}</span>
                  </dd>
                </template>
                <template v-if="selected.linked_entities?.length">
                  <dt>Linked Entities</dt>
                  <dd>
                    <RouterLink
                      v-for="e in selected.linked_entities"
                      :key="e.id"
                      :to="`/worlds/${wid}/entities/${e.id}`"
                      class="entity-link"
                    >{{ e.name }}</RouterLink>
                  </dd>
                </template>
                <dt>Added by</dt><dd>{{ selected.creator_name }}</dd>
                <dt>Created</dt><dd>{{ selected.created_at }}</dd>
              </dl>
              <div class="drawer-actions">
                <button class="btn btn-secondary" @click="editMode = true">Edit</button>
                <button class="btn btn-danger" @click="deleteConfirm = true">Delete</button>
              </div>
              <div v-if="deleteConfirm" class="confirm-box">
                <p>Delete this reference? This cannot be undone.</p>
                <p v-if="deleteError" class="form-error">{{ deleteError }}</p>
                <button class="btn btn-danger" @click="deleteRef">Confirm Delete</button>
                <button class="btn btn-ghost" @click="deleteConfirm = false">Cancel</button>
              </div>
            </template>

            <template v-else>
              <h2>Edit Reference</h2>
              <form class="settings-form" @submit.prevent="saveEdit">
                <div class="form-row">
                  <label>
                    Type
                    <select v-model="editForm.ref_type">
                      <option v-for="t in TYPES.slice(1)" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
                    </select>
                  </label>
                  <label style="flex:3">
                    Title
                    <input v-model="editForm.title" type="text" required maxlength="512" />
                  </label>
                </div>
                <label>URL<input v-model="editForm.url" type="url" maxlength="2048" /></label>
                <label>Author<input v-model="editForm.author" type="text" maxlength="255" /></label>
                <label>Description<textarea v-model="editForm.description" rows="3" maxlength="4000"></textarea></label>
                <label>Tags (comma-separated)<input v-model="editForm.tags" type="text" /></label>
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
