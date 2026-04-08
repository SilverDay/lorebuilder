<script setup>
/**
 * AiPanel — floating drawer that appears over any entity detail or world view.
 *
 * Props:
 *   worldId  (required) — the current world's numeric ID
 *   entityId (optional) — if set, defaults to entity_assist mode
 *
 * Emits:
 *   response — when AI returns a result ({ text, session_id, tokens })
 */
import { ref, computed } from 'vue'
import { useAiStore }    from '@/stores/ai.js'
import AiResponseCard    from './AiResponseCard.vue'

const props = defineProps({
  worldId:  { type: [Number, String], required: true },
  entityId: { type: [Number, String], default: null },
})

const emit = defineEmits(['response'])

const ai      = useAiStore()
const open    = ref(false)
const prompt  = ref('')
const mode    = ref(props.entityId ? 'entity_assist' : 'world_overview')

const MODES = [
  { value: 'entity_assist',   label: 'Entity Assist' },
  { value: 'arc_synthesiser', label: 'Arc Synthesiser' },
  { value: 'world_overview',  label: 'World Overview' },
  { value: 'image_prompt',    label: 'Image Prompt' },
  { value: 'custom',          label: 'Custom' },
]

const isImagePrompt = computed(() => mode.value === 'image_prompt')

// Editable copy of the image prompt result
const editablePrompt = ref('')
const copied = ref(false)

const canSubmit = computed(() =>
  (prompt.value.trim().length > 0 || isImagePrompt.value) && !ai.loading
)

function togglePanel() {
  open.value = !open.value
  if (open.value) ai.clearResult()
}

async function submit() {
  if (!canSubmit.value) return
  copied.value = false
  const userPrompt = prompt.value.trim() || (isImagePrompt.value ? 'Generate a detailed image prompt for this entity.' : '')
  if (!userPrompt) return
  try {
    const result = await ai.assist(
      props.worldId,
      mode.value,
      userPrompt,
      mode.value === 'entity_assist' || mode.value === 'image_prompt' ? props.entityId : null
    )
    if (isImagePrompt.value && result?.text) {
      editablePrompt.value = result.text
    }
    emit('response', result)
  } catch {
    // error displayed via ai.error
  }
}

function clear() {
  prompt.value = ''
  editablePrompt.value = ''
  copied.value = false
  ai.clearResult()
}

async function copyPrompt() {
  try {
    await navigator.clipboard.writeText(editablePrompt.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Fallback: select textarea content
    const ta = document.querySelector('.ai-image-prompt__editor')
    if (ta) { ta.select(); document.execCommand('copy'); copied.value = true }
  }
}
</script>

<template>
  <!-- Toggle button -->
  <button
    class="ai-fab"
    :class="{ 'ai-fab--open': open }"
    @click="togglePanel"
    :aria-label="open ? 'Close AI panel' : 'Open AI assistant'"
    title="AI Assistant"
  >
    <span aria-hidden="true">✦</span>
  </button>

  <!-- Sliding drawer -->
  <Transition name="ai-panel">
    <aside v-if="open" class="ai-panel" role="complementary" aria-label="AI assistant">
      <header class="ai-panel__header">
        <h2>AI Assistant</h2>
        <button class="btn btn-ghost btn-sm" aria-label="Close AI panel" @click="togglePanel">✕</button>
      </header>

      <div class="ai-panel__body">
        <!-- Mode selector -->
        <label class="ai-panel__label">
          Mode
          <select v-model="mode" class="ai-panel__select" :disabled="ai.loading">
            <option v-for="m in MODES" :key="m.value" :value="m.value">
              {{ m.label }}
            </option>
          </select>
        </label>

        <!-- Prompt textarea -->
        <label class="ai-panel__label">
          {{ isImagePrompt ? 'Describe the visual you want (optional — leave blank for automatic)' : 'Your request' }}
          <textarea
            v-model="prompt"
            class="ai-panel__textarea"
            :placeholder="isImagePrompt
              ? 'e.g. \'Full body portrait in dramatic lighting\' or leave blank to auto-generate from entity data…'
              : 'Describe what you need, e.g. \'Write a backstory for this character\' or \'Suggest plot hooks involving this entity\'…'"
            rows="5"
            :disabled="ai.loading"
            @keydown.ctrl.enter.prevent="submit"
          />
          <span class="ai-panel__hint">Ctrl+Enter to send</span>
        </label>

        <!-- Actions -->
        <div class="ai-panel__actions">
          <button
            class="btn btn-primary"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ ai.loading ? 'Thinking…' : isImagePrompt ? 'Generate Image Prompt' : 'Ask AI' }}
          </button>
          <button
            v-if="prompt || ai.lastResult || editablePrompt"
            class="btn btn-ghost btn-sm"
            @click="clear"
            :disabled="ai.loading"
          >
            Clear
          </button>
        </div>

        <!-- Error -->
        <p v-if="ai.error" class="form-error" role="alert">{{ ai.error }}</p>

        <!-- Image Prompt: editable + copyable result -->
        <div v-if="isImagePrompt && editablePrompt" class="ai-image-prompt">
          <div class="ai-image-prompt__header">
            <h3>Generated Image Prompt</h3>
            <button
              class="btn btn-secondary btn-sm"
              @click="copyPrompt"
              :aria-label="copied ? 'Copied!' : 'Copy prompt to clipboard'"
            >
              {{ copied ? '✓ Copied' : 'Copy' }}
            </button>
          </div>
          <textarea
            v-model="editablePrompt"
            class="ai-image-prompt__editor"
            rows="12"
            aria-label="Editable image generation prompt"
          />
          <p class="ai-image-prompt__hint">Edit the prompt above, then copy and paste into your image generator.</p>
          <!-- Token meta -->
          <div v-if="ai.lastResult" class="ai-response-card__meta">
            <span class="badge badge-ai">{{ ai.lastResult.model }}</span>
            <span class="ai-response-card__tokens">
              {{ ai.lastResult.prompt_tokens?.toLocaleString() }} in
              · {{ ai.lastResult.completion_tokens?.toLocaleString() }} out
            </span>
          </div>
        </div>

        <!-- Standard Response (non-image modes) -->
        <AiResponseCard
          v-if="!isImagePrompt && ai.lastResult"
          :result="ai.lastResult"
          :world-id="worldId"
          :entity-id="entityId"
          @refresh="$emit('response', ai.lastResult)"
        />
      </div>
    </aside>
  </Transition>

  <!-- Backdrop -->
  <Transition name="ai-backdrop">
    <div v-if="open" class="ai-backdrop" @click="togglePanel" aria-hidden="true" />
  </Transition>
</template>
