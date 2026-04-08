<script setup>
/**
 * AuditLogView — paginated change history table.
 * Requires admin role (enforced server-side; 403 if reviewer/viewer tries).
 */
import { ref, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client.js'

const route  = useRoute()
const wid    = route.params.wid

const entries    = ref([])
const total      = ref(0)
const loading    = ref(false)
const error      = ref('')
const page       = ref(1)
const actionFilter = ref('')
const limit      = 50

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const params = {
      limit,
      offset: (page.value - 1) * limit,
      ...(actionFilter.value ? { action: actionFilter.value } : {}),
    }
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/audit-log`, params)
    entries.value = data ?? []
    total.value   = meta?.total ?? entries.value.length
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 403
      ? 'You need admin access to view the audit log.'
      : (e.message || 'Failed to load audit log.')
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(actionFilter, () => { page.value = 1; load() })
watch(page, load)

function formatDiff(diff) {
  if (!diff) return '—'
  return JSON.stringify(diff, null, 2)
}

const expandedRows = ref(new Set())
function toggleRow(id) {
  if (expandedRows.value.has(id)) expandedRows.value.delete(id)
  else expandedRows.value.add(id)
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Audit Log</h1>
    </header>

    <div class="filter-bar">
      <input
        v-model="actionFilter"
        type="search"
        placeholder="Filter by action (e.g. entity.create)"
      />
    </div>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <table v-if="entries.length" class="audit-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Who</th>
            <th>Action</th>
            <th>Target</th>
            <th>Diff</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="entry in entries" :key="entry.id">
            <tr>
              <td class="audit-time">{{ entry.created_at }}</td>
              <td>{{ entry.actor_name ?? '—' }}</td>
              <td><code>{{ entry.action }}</code></td>
              <td>
                <span v-if="entry.target_type">
                  {{ entry.target_type }} #{{ entry.target_id }}
                </span>
                <span v-else>—</span>
              </td>
              <td>
                <button
                  v-if="entry.diff"
                  class="btn btn-ghost btn-sm"
                  @click="toggleRow(entry.id)"
                >
                  {{ expandedRows.has(entry.id) ? 'Hide' : 'Diff' }}
                </button>
                <span v-else>—</span>
              </td>
            </tr>
            <tr v-if="expandedRows.has(entry.id) && entry.diff" class="audit-diff-row">
              <td colspan="5">
                <pre class="audit-diff">{{ formatDiff(entry.diff) }}</pre>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <p v-else class="empty-state">No audit log entries found.</p>

      <div v-if="total > limit" class="pagination">
        <button :disabled="page === 1" @click="page--">Previous</button>
        <span>Page {{ page }} of {{ Math.ceil(total / limit) }}</span>
        <button :disabled="page * limit >= total" @click="page++">Next</button>
      </div>
    </template>
  </div>
</template>
