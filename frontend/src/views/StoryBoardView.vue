<script setup>
/**
 * StoryBoardView — split-pane story editor with context panel.
 *
 * Left: StoryEditor (Milkdown WYSIWYG)
 * Right: StoryContextPanel (entities, notes, arc, AI)
 * Footer: word count, status, last saved
 *
 * Auto-saves every 30s when dirty. Ctrl+S for manual save.
 * beforeunload warning if unsaved changes.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useStoryStore } from '@/stores/story.js'
import StoryEditor from '@/components/story/StoryEditor.vue'
import StoryContextPanel from '@/components/story/StoryContextPanel.vue'

const route  = useRoute()
const router = useRouter()
const store  = useStoryStore()

const wid = route.params.wid
const sid = route.params.sid

const error    = ref('')
const loading  = ref(true)

// Story metadata editing
const editingMeta = ref(false)
const metaForm    = ref({ title: '', status: '', synopsis: '', arc_id: '' })
const savingMeta  = ref(false)

// Arcs list for selector
const arcs = ref([])

const STATUSES = [
  { key: 'draft',       label: 'Draft' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'review',      label: 'Review' },
  { key: 'complete',    label: 'Complete' },
  { key: 'archived',    label: 'Archived' },
]

const story = computed(() => store.currentStory)

function statusLabel(key) {
  return STATUSES.find(s => s.key === key)?.label ?? key
}

// ── Load Story ──────────────────────────────────────────────────────────

async function loadStory() {
  loading.value = true
  error.value   = ''
  try {
    await store.fetchStory(wid, sid)
    loadArcs()
  } catch (e) {
    error.value = e.message || 'Failed to load story.'
  } finally {
    loading.value = false
  }
}

async function loadArcs() {
  try {
    const { data } = await (await import('@/api/client.js')).api.get(`/api/v1/worlds/${wid}/story-arcs`)
    arcs.value = data ?? []
  } catch { /* non-critical */ }
}

onMounted(() => {
  loadStory()
  window.addEventListener('keydown', onGlobalKeydown)
  window.addEventListener('beforeunload', onBeforeUnload)
})

onBeforeUnmount(() => {
  store.stopAutoSave()
  window.removeEventListener('keydown', onGlobalKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
})

// Start auto-save once story is loaded
watch(story, (val) => {
  if (val) {
    store.startAutoSave(wid, sid)
  }
})

// ── Content Updates ─────────────────────────────────────────────────────

function onContentUpdate(newContent) {
  if (!store.currentStory) return
  store.currentStory.content = newContent
  store.currentStory.word_count = countWords(newContent)
  store.markDirty()
}

function countWords(text) {
  if (!text) return 0
  // Strip markdown syntax for rough word count
  const plain = text.replace(/[#*_`>\[\]()!~\-|]/g, ' ').trim()
  return plain.split(/\s+/).filter(w => w.length > 0).length
}

// ── Save ────────────────────────────────────────────────────────────────

async function saveNow() {
  if (!store.currentStory || store.saving) return
  error.value = ''
  try {
    await store.updateStory(wid, sid, {
      content: store.currentStory.content,
    })
  } catch (e) {
    error.value = store.error || e.message
  }
}

function onGlobalKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault()
    saveNow()
  }
}

function onBeforeUnload(e) {
  if (store.dirty) {
    e.preventDefault()
    e.returnValue = ''
  }
}

onBeforeRouteLeave((to, from, next) => {
  if (store.dirty) {
    const leave = confirm('You have unsaved changes. Leave anyway?')
    if (!leave) return next(false)
  }
  store.clear()
  next()
})

// ── Meta Editing ────────────────────────────────────────────────────────

function startMetaEdit() {
  if (!story.value) return
  metaForm.value = {
    title:    story.value.title ?? '',
    status:   story.value.status ?? 'draft',
    synopsis: story.value.synopsis ?? '',
    arc_id:   story.value.arc_id ?? '',
  }
  editingMeta.value = true
}

async function saveMeta() {
  if (!metaForm.value.title.trim()) return
  savingMeta.value = true
  error.value      = ''
  try {
    const payload = {
      title:    metaForm.value.title.trim(),
      status:   metaForm.value.status,
      synopsis: metaForm.value.synopsis.trim() || null,
    }
    if (metaForm.value.arc_id) {
      payload.arc_id = Number(metaForm.value.arc_id)
    } else {
      payload.arc_id = null
    }
    await store.updateStory(wid, sid, payload)
    editingMeta.value = false
  } catch (e) {
    error.value = store.error || e.message
  } finally {
    savingMeta.value = false
  }
}

// ── Refresh Linked Data ─────────────────────────────────────────────────

async function refreshStory() {
  try {
    await store.fetchStory(wid, sid)
  } catch (e) {
    error.value = e.message || 'Failed to refresh.'
  }
}

const savedAgo = computed(() => {
  if (!store.lastSavedAt) return null
  return store.lastSavedAt
})
</script>

<template>
  <div class="story-board">
    <!-- Loading / Error -->
    <p v-if="loading" class="loading" style="padding: 2rem">Loading story…</p>
    <p v-else-if="error && !story" class="form-error" style="padding: 2rem" role="alert">{{ error }}</p>

    <template v-if="story && !loading">
      <!-- Header bar -->
      <header class="story-board__header">
        <div v-if="!editingMeta" class="story-board__title-row">
          <h1 class="story-board__title" @click="startMetaEdit" title="Click to edit">{{ story.title }}</h1>
          <span class="badge" :class="`badge-status-${story.status}`">{{ statusLabel(story.status) }}</span>
          <span v-if="story.arc_name" class="badge badge-role">{{ story.arc_name }}</span>
          <button class="btn btn-ghost btn-sm" @click="startMetaEdit">Edit</button>
        </div>
        <div v-else class="story-board__meta-edit">
          <form @submit.prevent="saveMeta" class="story-board__meta-form">
            <input v-model="metaForm.title" type="text" required maxlength="255" placeholder="Title" class="story-board__meta-title-input" />
            <select v-model="metaForm.status">
              <option v-for="s in STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
            <select v-model="metaForm.arc_id">
              <option value="">No arc</option>
              <option v-for="arc in arcs" :key="arc.id" :value="arc.id">{{ arc.name }}</option>
            </select>
            <input v-model="metaForm.synopsis" type="text" maxlength="512" placeholder="Synopsis (brief summary)" class="story-board__meta-synopsis" />
            <div class="form-actions">
              <button type="submit" class="btn btn-primary btn-sm" :disabled="savingMeta">Save</button>
              <button type="button" class="btn btn-ghost btn-sm" @click="editingMeta = false">Cancel</button>
            </div>
          </form>
        </div>
      </header>

      <!-- Error banner -->
      <p v-if="error" class="form-error story-board__error" role="alert">{{ error }}</p>

      <!-- Split pane -->
      <div class="story-board__panes">
        <div class="story-board__editor-pane">
          <StoryEditor
            :content="story.content ?? ''"
            @update:content="onContentUpdate"
          />
        </div>
        <div class="story-board__context-pane">
          <StoryContextPanel
            :world-id="wid"
            :story-id="sid"
            :story="story"
            @refresh="refreshStory"
          />
        </div>
      </div>

      <!-- Footer bar -->
      <footer class="story-board__footer">
        <span class="story-board__word-count">{{ (story.word_count ?? 0).toLocaleString() }} words</span>
        <span v-if="store.dirty" class="story-board__unsaved">Unsaved changes</span>
        <span v-if="store.saving" class="story-board__saving">Saving…</span>
        <span v-else-if="savedAgo" class="story-board__saved">Saved {{ savedAgo }}</span>
        <button class="btn btn-ghost btn-sm" @click="saveNow" :disabled="store.saving || !store.dirty" title="Ctrl+S">
          Save
        </button>
        <RouterLink :to="`/worlds/${wid}/stories`" class="btn btn-ghost btn-sm">Back to list</RouterLink>
      </footer>
    </template>
  </div>
</template>
