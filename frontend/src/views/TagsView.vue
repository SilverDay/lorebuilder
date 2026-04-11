<script setup>
/**
 * TagsView — manage world-level tags.
 *
 * Features:
 * - List all tags with entity count and color preview
 * - Create new tags with name + color
 * - Inline edit tag name and color
 * - Delete tags (admin only, with confirmation)
 * - Click entity count to navigate to filtered entity list
 */
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client.js'
import { useToastStore } from '@/stores/toast.js'

const route = useRoute()
const toast = useToastStore()
const wid   = route.params.wid

const tags    = ref([])
const loading = ref(false)
const error   = ref('')

// ── Create form ─────────────────────────────────────────────────────────────
const showCreate  = ref(false)
const createForm  = ref({ name: '', color: '#4A90A4' })
const createError = ref('')
const creating    = ref(false)

// ── Edit state ──────────────────────────────────────────────────────────────
const editingId   = ref(null)
const editForm    = ref({ name: '', color: '' })
const editError   = ref('')
const editSaving  = ref(false)

// ── Delete state ────────────────────────────────────────────────────────────
const deletingId  = ref(null)

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/tags`)
    tags.value = data ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load tags.'
  } finally {
    loading.value = false
  }
}

async function createTag() {
  createError.value = ''
  const name = createForm.value.name.trim()
  if (!name) { createError.value = 'Name is required.'; return }

  creating.value = true
  try {
    await api.post(`/api/v1/worlds/${wid}/tags`, {
      name,
      color: createForm.value.color,
    })
    createForm.value = { name: '', color: '#4A90A4' }
    showCreate.value = false
    toast.success('Tag created.')
    await load()
  } catch (e) {
    createError.value = e.message || 'Failed to create tag.'
  } finally {
    creating.value = false
  }
}

function openEdit(tag) {
  editingId.value = tag.id
  editForm.value  = { name: tag.name, color: tag.color }
  editError.value = ''
}

async function saveEdit() {
  editError.value = ''
  const name = editForm.value.name.trim()
  if (!name) { editError.value = 'Name is required.'; return }

  editSaving.value = true
  try {
    await api.patch(`/api/v1/worlds/${wid}/tags/${editingId.value}`, {
      name,
      color: editForm.value.color,
    })
    editingId.value = null
    toast.success('Tag updated.')
    await load()
  } catch (e) {
    editError.value = e.message || 'Failed to update tag.'
  } finally {
    editSaving.value = false
  }
}

async function deleteTag(tag) {
  if (!confirm(`Delete tag "${tag.name}"? This will remove it from all entities.`)) return

  deletingId.value = tag.id
  try {
    await api.delete(`/api/v1/worlds/${wid}/tags/${tag.id}`)
    toast.success('Tag deleted.')
    await load()
  } catch (e) {
    alert(e.message || 'Failed to delete tag.')
  } finally {
    deletingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Tags</h1>
      <div class="page-header-actions">
        <button v-if="!showCreate" class="btn btn-primary" @click="showCreate = true">New tag</button>
      </div>
    </header>

    <!-- Create form -->
    <form v-if="showCreate" class="tag-form card" @submit.prevent="createTag">
      <h3>Create Tag</h3>
      <div class="tag-form__row">
        <label>
          Name
          <input v-model="createForm.name" type="text" maxlength="64" required autofocus placeholder="e.g. main-cast" />
        </label>
        <label class="tag-form__color-label">
          Color
          <div class="tag-form__color-wrap">
            <input v-model="createForm.color" type="color" class="tag-color-input" />
            <span class="tag-color-hex">{{ createForm.color }}</span>
          </div>
        </label>
      </div>
      <div class="tag-form__preview">
        Preview: <span class="tag-badge" :style="{ backgroundColor: createForm.color }">{{ createForm.name || '…' }}</span>
      </div>
      <p v-if="createError" class="form-error" role="alert">{{ createError }}</p>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" @click="showCreate = false">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="creating">{{ creating ? 'Creating…' : 'Create' }}</button>
      </div>
    </form>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <div v-else-if="tags.length" class="tag-list tag-list--manage">
      <div v-for="tag in tags" :key="tag.id" class="tag-list__item card">
        <!-- Edit mode -->
        <form v-if="editingId === tag.id" class="tag-form__row tag-form__row--inline" @submit.prevent="saveEdit">
          <input v-model="editForm.name" type="text" maxlength="64" required class="tag-edit-name" />
          <input v-model="editForm.color" type="color" class="tag-color-input" />
          <span class="tag-color-hex">{{ editForm.color }}</span>
          <p v-if="editError" class="form-error" role="alert">{{ editError }}</p>
          <div class="tag-list__actions">
            <button type="button" class="btn btn-ghost btn-sm" @click="editingId = null">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm" :disabled="editSaving">{{ editSaving ? 'Saving…' : 'Save' }}</button>
          </div>
        </form>

        <!-- Read mode -->
        <template v-else>
          <span class="tag-badge" :style="{ backgroundColor: tag.color }">{{ tag.name }}</span>
          <RouterLink :to="`/worlds/${wid}/entities?tag=${tag.id}`" class="tag-entity-count" :title="`View ${tag.entity_count} entities with this tag`">
            {{ tag.entity_count }} {{ tag.entity_count === 1 ? 'entity' : 'entities' }}
          </RouterLink>
          <div class="tag-list__actions">
            <button class="btn-icon" title="Edit" @click="openEdit(tag)">✏</button>
            <button class="btn-icon btn-icon--danger" title="Delete" :disabled="deletingId === tag.id" @click="deleteTag(tag)">✕</button>
          </div>
        </template>
      </div>
    </div>

    <p v-else-if="!showCreate" class="empty-state">No tags yet. Create one to get started.</p>
  </div>
</template>
