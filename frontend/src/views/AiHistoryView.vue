<script setup>
import { ref, onMounted } from 'vue'
import { useRoute }       from 'vue-router'
import { api }            from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const sessions  = ref([])
const meta      = ref({})
const page      = ref(1)
const loading   = ref(true)
const error     = ref('')
const expanded  = ref(null)  // expanded session ID for notes preview

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const { data, meta: m } = await api.get(`/api/v1/worlds/${wid}/ai/sessions`, { page: page.value })
    sessions.value = data ?? []
    meta.value     = m   ?? {}
  } catch (e) {
    error.value = e.message || 'Failed to load AI sessions.'
  } finally {
    loading.value = false
  }
}

function toggleExpand(id) {
  expanded.value = expanded.value === id ? null : id
}

function prevPage() {
  if (page.value > 1) { page.value--; load() }
}
function nextPage() {
  if (page.value < (meta.value.pages ?? 1)) { page.value++; load() }
}

const statusClass = (s) => ({
  success:      'badge-success',
  error:        'badge-error',
  rate_limited: 'badge-warn',
}[s] ?? '')

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>AI Session History</h1>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <p v-if="!sessions.length" class="muted">No AI sessions yet.</p>

      <table v-else class="audit-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Mode</th>
            <th>Entity</th>
            <th>Model</th>
            <th>Tokens</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="s in sessions" :key="s.id">
            <tr>
              <td>{{ new Date(s.created_at).toLocaleString() }}</td>
              <td><code>{{ s.mode }}</code></td>
              <td>{{ s.entity_name ?? '—' }}</td>
              <td><span class="badge badge-ai">{{ s.model }}</span></td>
              <td class="mono">{{ Number(s.total_tokens).toLocaleString() }}</td>
              <td>
                <span class="badge" :class="statusClass(s.status)">{{ s.status }}</span>
              </td>
              <td>
                <button
                  v-if="s.status === 'success'"
                  class="btn btn-ghost btn-sm"
                  @click="toggleExpand(s.id)"
                >
                  {{ expanded === s.id ? 'Hide' : 'Details' }}
                </button>
              </td>
            </tr>
            <tr v-if="expanded === s.id" class="audit-table__detail-row">
              <td colspan="7">
                <div class="session-detail">
                  <p><strong>User:</strong> {{ s.user_display_name }}</p>
                  <p v-if="s.error_message" class="form-error">{{ s.error_message }}</p>
                  <p>
                    <strong>Tokens:</strong>
                    {{ Number(s.prompt_tokens).toLocaleString() }} prompt +
                    {{ Number(s.completion_tokens).toLocaleString() }} completion =
                    {{ Number(s.total_tokens).toLocaleString() }} total
                  </p>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="meta.pages > 1" class="pagination">
        <button class="btn btn-ghost btn-sm" :disabled="page === 1" @click="prevPage">← Prev</button>
        <span>Page {{ page }} of {{ meta.pages }}</span>
        <button class="btn btn-ghost btn-sm" :disabled="page >= meta.pages" @click="nextPage">Next →</button>
      </div>
    </template>
  </div>
</template>
