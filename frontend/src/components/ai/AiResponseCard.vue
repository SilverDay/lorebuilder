<script setup>
/**
 * AiResponseCard — renders AI response with promote-to-canonical action.
 *
 * The response text is rendered as Markdown via marked + DOMPurify to prevent XSS.
 * v-html is ONLY used on the sanitised output of DOMPurify.sanitize().
 *
 * Props:
 *   result   — { text, session_id, note_id, entity_id, prompt_tokens, completion_tokens, total_tokens, model }
 *   worldId  — world the note belongs to
 *
 * Emits:
 *   refresh — after note is successfully promoted to canonical
 */
import { ref, computed }  from 'vue'
import { marked }         from 'marked'
import DOMPurify          from 'dompurify'
import { api }            from '@/api/client.js'

const props = defineProps({
  result:  { type: Object,           required: true },
  worldId: { type: [Number, String], required: true },
})

const emit = defineEmits(['refresh'])

const saving  = ref(false)
const saved   = ref(false)
const saveErr = ref('')

// Safe HTML rendering — Marked converts Markdown, DOMPurify strips XSS vectors
// v-html is safe here because DOMPurify.sanitize() removes all script content
const renderedHtml = computed(() =>
  DOMPurify.sanitize(marked.parse(props.result.text ?? ''))
)

async function promoteToCanonical() {
  if (!props.result.note_id) {
    saveErr.value = 'Note ID missing — cannot promote.'
    return
  }
  saving.value  = true
  saveErr.value = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/notes/${props.result.note_id}/promote`, {})
    saved.value = true
    emit('refresh')
  } catch (e) {
    saveErr.value = e.message || 'Failed to promote note.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="ai-response-card">
    <!-- Token meta -->
    <div class="ai-response-card__meta">
      <span class="badge badge-ai">{{ result.model }}</span>
      <span class="ai-response-card__tokens">
        {{ result.prompt_tokens?.toLocaleString() }} in
        · {{ result.completion_tokens?.toLocaleString() }} out
        · {{ result.total_tokens?.toLocaleString() }} total
      </span>
    </div>

    <!-- Response body — sanitised Markdown -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div class="ai-response-card__body prose" v-html="renderedHtml" />

    <!-- Actions -->
    <div class="ai-response-card__actions">
      <button
        v-if="!saved"
        class="btn btn-secondary btn-sm"
        :disabled="saving"
        @click="promoteToCanonical"
      >
        {{ saving ? 'Saving…' : 'Promote to Canonical' }}
      </button>
      <span v-else class="badge badge-success">Saved as canonical</span>
      <p v-if="saveErr" class="form-error form-error--sm">{{ saveErr }}</p>
    </div>
  </div>
</template>
