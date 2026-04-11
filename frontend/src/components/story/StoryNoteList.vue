<script setup>
/**
 * StoryNoteList — linked lore notes with search and unlink.
 */
import { ref } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  worldId:  { type: [Number, String], required: true },
  storyId:  { type: [Number, String], required: true },
  notes:    { type: Array, default: () => [] },
})

const emit = defineEmits(['refresh'])

const error       = ref('')
const noteSearch  = ref('')
const searchResults = ref([])
const searching   = ref(false)

let searchTimeout = null

function onSearch() {
  clearTimeout(searchTimeout)
  const q = noteSearch.value.trim()
  if (q.length < 2) { searchResults.value = []; return }
  searchTimeout = setTimeout(async () => {
    searching.value = true
    try {
      const { data } = await api.get(`/api/v1/worlds/${props.worldId}/notes`, { q, limit: 10 })
      const linked = new Set(props.notes.map(n => n.note_id))
      searchResults.value = (data ?? []).filter(n => !linked.has(n.id))
    } catch {
      searchResults.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

async function addNote(note) {
  error.value = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/notes`, {
      note_id: note.id,
    })
    noteSearch.value    = ''
    searchResults.value = []
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to add note.'
  }
}

async function removeNote(noteId) {
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/notes/${noteId}`)
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to remove note.'
  }
}

function excerpt(content) {
  if (!content) return ''
  const plain = content.replace(/[#*_`>\-]/g, '').trim()
  return plain.length > 120 ? plain.slice(0, 120) + '…' : plain
}
</script>

<template>
  <div class="story-notes">
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Linked notes -->
    <ul v-if="notes.length" class="story-notes__list">
      <li v-for="note in notes" :key="note.note_id" class="story-notes__item">
        <div class="story-notes__header">
          <span v-if="note.entity_name" class="badge">{{ note.entity_name }}</span>
          <span v-else class="badge">World</span>
          <span v-if="note.is_canonical" class="badge badge-canonical">Canon</span>
          <button class="btn btn-ghost btn-sm" title="Unlink note" @click="removeNote(note.note_id)">✕</button>
        </div>
        <p class="story-notes__excerpt">{{ excerpt(note.content) }}</p>
      </li>
    </ul>
    <p v-else class="empty-state-sm">No notes linked yet.</p>

    <!-- Add note search -->
    <div class="story-notes__add">
      <input
        v-model="noteSearch"
        type="text"
        placeholder="Search notes…"
        @input="onSearch"
      />
      <ul v-if="searchResults.length" class="arc-search-results">
        <li v-for="note in searchResults" :key="note.id" class="arc-search-item" @click="addNote(note)">
          <span>{{ excerpt(note.content) }}</span>
          <span v-if="note.entity_name" class="badge">{{ note.entity_name }}</span>
        </li>
      </ul>
      <p v-else-if="searching" class="empty-state-sm">Searching…</p>
      <p v-else-if="noteSearch.length >= 2 && !searchResults.length" class="empty-state-sm">No matching notes.</p>
    </div>
  </div>
</template>
