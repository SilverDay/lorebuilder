<script setup>
/**
 * WorldAiSettingsView — API key management, provider/model selection,
 * and token budget display.
 *
 * Security: The API key is sent to the server once and immediately discarded
 * from this component's state. The server returns only a fingerprint, never
 * the full key.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute }       from 'vue-router'
import { api }            from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const budget      = ref(null)
const loadError   = ref('')
const loading     = ref(true)

// Provider list from backend
const providers   = ref({})

// Key entry state — cleared immediately after save
const keyMode     = ref('user')
const newKey      = ref('')
const savingKey   = ref(false)
const keyError    = ref('')
const keySaved    = ref(false)

// Provider/model selection state
const selectedProvider = ref('anthropic')
const selectedModel    = ref('')
const endpointUrl      = ref('')
const savingProvider   = ref(false)
const providerSaved    = ref(false)
const providerError    = ref('')

const isOllama = computed(() => selectedProvider.value === 'ollama')

const currentProviderModels = computed(() => {
  const p = providers.value[selectedProvider.value]
  return p ? p.models : {}
})

const currentProviderLabel = computed(() => {
  const p = providers.value[selectedProvider.value]
  return p ? p.label : selectedProvider.value
})

// Auto-set model to provider default when provider changes
watch(selectedProvider, (newProv) => {
  const p = providers.value[newProv]
  if (p && !p.models[selectedModel.value]) {
    selectedModel.value = p.default_model
  }
})

async function loadProviders() {
  try {
    const { data } = await api.get('/api/v1/ai/providers')
    providers.value = data
  } catch {
    // Non-fatal; provider list is a convenience feature
  }
}

async function loadBudget() {
  loading.value   = true
  loadError.value = ''
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/settings/ai/budget`)
    budget.value   = data
    keyMode.value  = data.ai_key_mode ?? 'user'
    // Load current provider/model from world config
    selectedProvider.value = data.ai_provider ?? 'anthropic'
    selectedModel.value    = data.ai_model ?? ''
    endpointUrl.value      = data.ai_endpoint_url ?? ''
    // If no model set, use provider default
    if (!selectedModel.value) {
      const p = providers.value[selectedProvider.value]
      if (p) selectedModel.value = p.default_model
    }
  } catch (e) {
    loadError.value = e.message || 'Failed to load AI settings.'
  } finally {
    loading.value = false
  }
}

async function saveKey() {
  if (!newKey.value.trim()) return
  savingKey.value = true
  keyError.value  = ''
  keySaved.value  = false
  try {
    const { data } = await api.put(`/api/v1/worlds/${wid}/settings/ai/key`, {
      api_key:  newKey.value.trim(),
      key_mode: keyMode.value,
    })
    if (budget.value) {
      budget.value.ai_key_fingerprint = data.fingerprint
      budget.value.ai_key_mode        = keyMode.value
    }
    keySaved.value = true
    newKey.value   = ''   // discard from component state immediately
  } catch (e) {
    keyError.value = e.message || 'Failed to save API key.'
  } finally {
    savingKey.value = false
  }
}

async function saveProviderModel() {
  savingProvider.value = true
  providerError.value  = ''
  providerSaved.value  = false
  try {
    const payload = {
      ai_provider: selectedProvider.value,
      ai_model:    selectedModel.value,
    }
    if (isOllama.value) {
      payload.ai_endpoint_url = endpointUrl.value.trim() || null
    }
    await api.patch(`/api/v1/worlds/${wid}`, payload)
    if (budget.value) {
      budget.value.ai_provider     = selectedProvider.value
      budget.value.ai_model        = selectedModel.value
      budget.value.ai_endpoint_url = payload.ai_endpoint_url ?? null
    }
    providerSaved.value = true
  } catch (e) {
    providerError.value = e.message || 'Failed to save provider settings.'
  } finally {
    savingProvider.value = false
  }
}

const budgetPct = () => {
  if (!budget.value) return 0
  return Math.min(100, Math.round((budget.value.ai_tokens_used / budget.value.ai_token_budget) * 100))
}

onMounted(async () => {
  await loadProviders()
  await loadBudget()
})
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>AI Settings</h1>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="loadError" class="form-error" role="alert">{{ loadError }}</p>

    <template v-else-if="budget">
      <!-- Provider & Model Section -->
      <section class="settings-section">
        <h2>AI Provider &amp; Model</h2>
        <form class="settings-form" @submit.prevent="saveProviderModel">
          <label>
            Provider
            <select v-model="selectedProvider" :disabled="savingProvider">
              <option
                v-for="(p, pid) in providers"
                :key="pid"
                :value="pid"
              >{{ p.label }}</option>
            </select>
          </label>

          <label>
            Model
            <select v-model="selectedModel" :disabled="savingProvider">
              <option
                v-for="(label, mid) in currentProviderModels"
                :key="mid"
                :value="mid"
              >{{ label }}</option>
            </select>
            <small v-if="isOllama">Enter any model name available on your Ollama instance. The list above shows common models.</small>
          </label>

          <!-- Ollama: custom model name input -->
          <label v-if="isOllama">
            Custom model name (optional)
            <input
              v-model="selectedModel"
              type="text"
              placeholder="e.g. llama3.1:latest or a custom model"
              :disabled="savingProvider"
              maxlength="64"
            />
            <small>Override the dropdown above with any model name installed on your Ollama server.</small>
          </label>

          <!-- Ollama: endpoint URL -->
          <label v-if="isOllama">
            Ollama endpoint URL
            <input
              v-model="endpointUrl"
              type="url"
              placeholder="http://localhost:11434"
              :disabled="savingProvider"
              maxlength="512"
            />
            <small>Must be localhost or a private network address. Leave blank for http://localhost:11434.</small>
          </label>

          <p v-if="providerError" class="form-error" role="alert">{{ providerError }}</p>
          <p v-if="providerSaved" class="form-success" role="status">Provider settings saved.</p>

          <button
            type="submit"
            class="btn btn-primary"
            :disabled="savingProvider"
          >
            {{ savingProvider ? 'Saving…' : 'Save Provider Settings' }}
          </button>
        </form>
      </section>

      <!-- API Key Section (not needed for Ollama unless using a proxy) -->
      <section class="settings-section">
        <h2>{{ isOllama ? 'Authentication (Optional)' : currentProviderLabel + ' API Key' }}</h2>

        <p v-if="isOllama" class="muted" style="font-size:.85rem;margin-bottom:.75rem">
          Ollama runs locally and typically doesn't require an API key.
          Only configure a key if your Ollama endpoint is behind an authentication proxy.
        </p>

        <div v-if="budget.ai_key_fingerprint" class="key-status">
          <span class="badge badge-success">Key configured</span>
          <code class="key-fingerprint">{{ budget.ai_key_fingerprint }}</code>
        </div>
        <p v-else class="key-status--empty">No API key configured.</p>

        <form class="settings-form" @submit.prevent="saveKey">
          <label>
            Key mode
            <select v-model="keyMode" :disabled="savingKey">
              <option value="user">User-provided key</option>
              <option value="platform">Platform key (shared)</option>
            </select>
          </label>

          <label v-if="keyMode === 'user'">
            API key
            <input
              v-model="newKey"
              type="password"
              autocomplete="off"
              placeholder="Your API key"
              :disabled="savingKey"
            />
            <small>Encrypted with AES-256 before storage. Never stored in plaintext.</small>
          </label>

          <p v-if="keyError" class="form-error" role="alert">{{ keyError }}</p>
          <p v-if="keySaved" class="form-success" role="status">API key saved successfully.</p>

          <button
            v-if="keyMode === 'user'"
            type="submit"
            class="btn btn-primary"
            :disabled="savingKey || !newKey.trim()"
          >
            {{ savingKey ? 'Saving…' : 'Save API Key' }}
          </button>
        </form>
      </section>

      <!-- Token Budget Section -->
      <section class="settings-section">
        <h2>Token Budget</h2>

        <div class="budget-stats">
          <div class="stat-card">
            <span class="stat-card__label">Used this month</span>
            <span class="stat-card__value">{{ budget.ai_tokens_used?.toLocaleString() }}</span>
          </div>
          <div class="stat-card">
            <span class="stat-card__label">Monthly budget</span>
            <span class="stat-card__value">{{ budget.ai_token_budget?.toLocaleString() }}</span>
          </div>
          <div class="stat-card">
            <span class="stat-card__label">Resets</span>
            <span class="stat-card__value">{{ budget.ai_budget_resets_at ?? 'Monthly' }}</span>
          </div>
        </div>

        <div class="budget-bar" :title="`${budgetPct()}% used`">
          <div
            class="budget-bar__fill"
            :style="{ width: budgetPct() + '%' }"
            :class="{
              'budget-bar__fill--warn':     budgetPct() >= 75,
              'budget-bar__fill--critical': budgetPct() >= 90,
            }"
          />
        </div>
        <p class="budget-bar__label">{{ budgetPct() }}% used</p>

        <!-- Daily usage chart (simple list) -->
        <div v-if="budget.usage_by_day?.length" class="usage-history">
          <h3>Daily Usage (this month)</h3>
          <ul class="usage-list">
            <li
              v-for="day in budget.usage_by_day"
              :key="day.day"
              class="usage-list__item"
            >
              <span class="usage-list__day">{{ day.day }}</span>
              <span class="usage-list__tokens">{{ Number(day.tokens).toLocaleString() }} tokens</span>
            </li>
          </ul>
        </div>
      </section>

      <!-- Navigation -->
      <section class="settings-section">
        <RouterLink :to="`/worlds/${wid}/ai/history`" class="btn btn-secondary">
          View AI Session History →
        </RouterLink>
      </section>
    </template>
  </div>
</template>
