<script setup>
/**
 * EntityTrashView — browse and restore soft-deleted entities.
 * Guard: admin role required.
 */
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const items    = ref([])
const total    = ref(0)
const page     = ref(1)
const loading  = ref(false)
const error    = ref('')
const limit    = 50

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/entities/trash`, {
      page: page.value,
      per_page: limit,
    })
    items.value = data ?? []
    total.value = meta?.total ?? 0
  } catch (e) {
    error.value = e.message || 'Failed to load trash.'
  } finally {
    loading.value = false
  }
}

async function restore(entity) {
  if (!confirm(`Restore "${entity.name}"?`)) return
  try {
    await api.post(`/api/v1/worlds/${wid}/entities/${entity.id}/restore`)
    items.value = items.value.filter(e => e.id !== entity.id)
    total.value--
  } catch (e) {
    error.value = e.message || 'Failed to restore entity.'
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Trash</h1>
      <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Dashboard</RouterLink>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <p v-if="!items.length" class="empty-state">No deleted items.</p>

      <ul v-else class="entity-list">
        <li v-for="item in items" :key="item.id" class="entity-row">
          <div class="trash-row">
            <div class="trash-row__info">
              <span class="entity-name">{{ item.name }}</span>
              <span class="badge">{{ item.type }}</span>
              <span class="badge badge-archived">{{ item.status }}</span>
              <span class="trash-row__date">Deleted {{ item.deleted_at }}</span>
            </div>
            <button
              class="btn btn-sm btn-success"
              aria-label="Restore entity"
              @click="restore(item)"
            >
              Restore
            </button>
          </div>
        </li>
      </ul>

      <div v-if="total > limit" class="pagination">
        <button class="btn btn-sm btn-ghost" :disabled="page <= 1" @click="page--; load()">
          ← Previous
        </button>
        <span class="pagination-info">Page {{ page }}</span>
        <button class="btn btn-sm btn-ghost" :disabled="items.length < limit" @click="page++; load()">
          Next →
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.trash-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.trash-row__info {
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
  flex: 1;
}

.trash-row__date {
  font-size: .8rem;
  color: var(--color-muted);
}
</style>
