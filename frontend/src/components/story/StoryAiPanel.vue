<script setup>
/**
 * StoryAiPanel — AI assistant for story writing.
 *
 * Modes: story_assist, story_consistency
 * Sends story context (synopsis, content, entities) to AI backend.
 */
import { ref, computed } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  worldId: { type: [Number, String], required: true },
  storyId: { type: [Number, String], required: true },
  story:   { type: Object, required: true },
})

const loading     = ref(false)
const error       = ref('')
const prompt      = ref('')
const mode        = ref('story_assist')
const result      = ref(null)
const cursorPos   = ref(0)

const MODES = [
  { value: 'story_assist',       label: 'Story Assist' },
  { value: 'story_consistency',  label: 'Consistency Check' },
]

const canSubmit = computed(() => prompt.value.trim().length > 0 && !loading.value)

async function submit() {
  if (!canSubmit.value) return
  loading.value = true
  error.value   = ''
  result.value  = null
  try {
    const { data } = await api.post(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/ai/assist`, {
      mode: mode.value,
      user_prompt: prompt.value.trim(),
      cursor_pos: cursorPos.value,
    })
    result.value = data
  } catch (e) {
    error.value = e.message || 'AI request failed.'
  } finally {
    loading.value = false
  }
}

function clear() {
  prompt.value = ''
  result.value = null
  error.value  = ''
}
</script>

<template>
  <div class="story-ai">
    <!-- Mode selector -->
    <label class="story-ai__label">
      Mode
      <select v-model="mode" :disabled="loading">
        <option v-for="m in MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
    </label>

    <!-- Prompt -->
    <label class="story-ai__label">
      Your request
      <textarea
        v-model="prompt"
        rows="4"
        placeholder="e.g. 'Suggest what happens next' or 'Check for inconsistencies in this chapter'…"
        :disabled="loading"
        @keydown.ctrl.enter.prevent="submit"
      />
      <span class="story-ai__hint">Ctrl+Enter to send</span>
    </label>

    <!-- Actions -->
    <div class="story-ai__actions">
      <button class="btn btn-primary btn-sm" :disabled="!canSubmit" @click="submit">
        {{ loading ? 'Thinking…' : 'Ask AI' }}
      </button>
      <button v-if="prompt || result" class="btn btn-ghost btn-sm" :disabled="loading" @click="clear">
        Clear
      </button>
    </div>

    <!-- Error -->
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Result -->
    <div v-if="result" class="story-ai__result">
      <div class="story-ai__result-header">
        <span class="badge badge-ai">{{ result.model }}</span>
        <span class="story-ai__tokens">
          {{ result.prompt_tokens?.toLocaleString() }} in
          · {{ result.completion_tokens?.toLocaleString() }} out
        </span>
      </div>
      <div class="story-ai__result-text" v-text="result.text" />
    </div>
  </div>
</template>
