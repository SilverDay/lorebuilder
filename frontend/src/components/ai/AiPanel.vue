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
  { value: 'custom',          label: 'Custom' },
]

const canSubmit = computed(() =>
  prompt.value.trim().length > 0 && !ai.loading
)

function togglePanel() {
  open.value = !open.value
  if (open.value) ai.clearResult()
}

async function submit() {
  if (!canSubmit.value) return
  try {
    const result = await ai.assist(
      props.worldId,
      mode.value,
      prompt.value.trim(),
      mode.value === 'entity_assist' ? props.entityId : null
    )
    emit('response', result)
  } catch {
    // error displayed via ai.error
  }
}

function clear() {
  prompt.value = ''
  ai.clearResult()
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
        <button class="btn btn-ghost btn-sm" @click="togglePanel">✕</button>
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
          Your request
          <textarea
            v-model="prompt"
            class="ai-panel__textarea"
            placeholder="Describe what you need, e.g. 'Write a backstory for this character' or 'Suggest plot hooks involving this entity'…"
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
            {{ ai.loading ? 'Thinking…' : 'Ask Claude' }}
          </button>
          <button
            v-if="prompt || ai.lastResult"
            class="btn btn-ghost btn-sm"
            @click="clear"
            :disabled="ai.loading"
          >
            Clear
          </button>
        </div>

        <!-- Error -->
        <p v-if="ai.error" class="form-error" role="alert">{{ ai.error }}</p>

        <!-- Response -->
        <AiResponseCard
          v-if="ai.lastResult"
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
