<script setup>
/**
 * NotesList — chronological Markdown notes (Marked.js + DOMPurify).
 *
 * Security: all user-generated content is sanitised through DOMPurify
 * before being injected via v-html. Marked converts Markdown to HTML;
 * DOMPurify strips any dangerous tags/attributes.
 */
import { ref, computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { api } from '@/api/client.js'

const props = defineProps({
  notes:    { type: Array,  required: true },
  worldId:  { type: [String, Number], required: true },
  entityId: { type: [String, Number], required: true },
})

const emit = defineEmits(['refresh'])

const newNote = ref('')
const posting = ref(false)
const error   = ref('')

// ── Inline editing ────────────────────────────────────────────────────────────
const editingNoteId = ref(null)
const editContent   = ref('')
const editSaving    = ref(false)
const editError     = ref('')

function startEditNote(note) {
  editingNoteId.value = note.id
  editContent.value   = note.content
  editError.value     = ''
}

function cancelEditNote() {
  editingNoteId.value = null
}

async function saveEditNote() {
  if (!editContent.value.trim()) return
  editSaving.value = true
  editError.value  = ''
  try {
    await api.patch(`/api/v1/worlds/${props.worldId}/notes/${editingNoteId.value}`, {
      content: editContent.value.trim(),
    })
    editingNoteId.value = null
    emit('refresh')
  } catch (e) {
    editError.value = e.message || 'Failed to save note.'
  } finally {
    editSaving.value = false
  }
}

function renderNote(content) {
  return DOMPurify.sanitize(marked.parse(content))
}

async function addNote() {
  if (!newNote.value.trim()) return
  posting.value = true
  error.value   = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/entities/${props.entityId}/notes`, {
      content: newNote.value.trim(),
    })
    newNote.value = ''
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to add note.'
  } finally {
    posting.value = false
  }
}

async function deleteNote(noteId) {
  if (!confirm('Delete this note?')) return
  try {
    await api.delete(`/api/v1/worlds/${props.worldId}/notes/${noteId}`)
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to delete note.'
  }
}
</script>

<template>
  <section>
    <h2>Notes</h2>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <div class="notes-list">
      <article
        v-for="note in notes"
        :key="note.id"
        class="note-card"
        :class="{ 'note-canonical': note.is_canonical, 'note-ai': note.ai_generated }"
      >
        <!-- Read mode -->
        <template v-if="editingNoteId !== note.id">
          <div class="note-badges">
            <span v-if="note.is_canonical" class="badge badge-canonical">Canonical</span>
            <span v-if="note.ai_generated" class="badge badge-ai">AI</span>
          </div>
          <!-- eslint-disable-next-line vue/no-v-html -- sanitised by DOMPurify -->
          <div class="note-body" v-html="renderNote(note.content)"></div>
          <footer class="note-footer">
            <span>{{ note.author_name }}</span>
            <span>{{ note.created_at }}</span>
            <button class="btn btn-ghost btn-sm" @click="startEditNote(note)">Edit</button>
            <button class="btn btn-ghost btn-sm" @click="deleteNote(note.id)">Delete</button>
          </footer>
        </template>

        <!-- Edit mode -->
        <template v-else>
          <textarea v-model="editContent" rows="4" class="note-edit-area"></textarea>
          <p v-if="editError" class="form-error" role="alert">{{ editError }}</p>
          <div class="form-actions" style="margin-top:.4rem">
            <button type="button" class="btn btn-ghost btn-sm" @click="cancelEditNote">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" :disabled="editSaving || !editContent.trim()" @click="saveEditNote">
              {{ editSaving ? 'Saving…' : 'Save' }}
            </button>
          </div>
        </template>
      </article>

      <p v-if="!notes.length" class="empty-state">No notes yet.</p>
    </div>

    <form class="note-add" @submit.prevent="addNote">
      <textarea
        v-model="newNote"
        rows="3"
        placeholder="Add a note (Markdown supported)…"
      ></textarea>
      <button type="submit" class="btn btn-primary" :disabled="posting || !newNote.trim()">
        {{ posting ? 'Saving…' : 'Add note' }}
      </button>
    </form>
  </section>
</template>
