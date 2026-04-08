<script setup>
/**
 * SearchModal — global search overlay triggered by Ctrl+K.
 * Calls GET /api/v1/worlds/:wid/search?q= and navigates to selected entity.
 */
import { ref, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const props = defineProps({
  worldId: { type: [String, Number], required: true },
})

const emit = defineEmits(['close'])

const router  = useRouter()
const query   = ref('')
const results = ref([])
const loading = ref(false)
const inputEl = ref(null)

let debounceTimer = null

watch(query, (val) => {
  clearTimeout(debounceTimer)
  const q = val.trim()
  if (q.length < 2) {
    results.value = []
    return
  }
  debounceTimer = setTimeout(() => search(q), 300)
})

async function search(q) {
  loading.value = true
  try {
    const { data } = await api.get(`/api/v1/worlds/${props.worldId}/search`, { q })
    results.value = data ?? []
  } catch {
    results.value = []
  } finally {
    loading.value = false
  }
}

function select(entity) {
  emit('close')
  router.push(`/worlds/${props.worldId}/entities/${entity.id}`)
}

function onBackdropClick(e) {
  if (e.target === e.currentTarget) emit('close')
}

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}

// Auto-focus input on mount
nextTick(() => inputEl.value?.focus())
</script>

<template>
  <Teleport to="body">
    <div class="search-overlay" @click="onBackdropClick" @keydown="onKeydown">
      <div class="search-modal" role="dialog" aria-label="Search entities">
        <div class="search-input-wrap">
          <input
            ref="inputEl"
            v-model="query"
            type="search"
            placeholder="Search entities…"
            class="search-input"
            aria-label="Search entities"
          />
          <kbd class="search-kbd">Esc</kbd>
        </div>

        <div class="search-results">
          <p v-if="loading" class="search-status">Searching…</p>
          <p v-else-if="query.trim().length >= 2 && !results.length" class="search-status">
            No results found.
          </p>

          <ul v-if="results.length" class="search-list">
            <li
              v-for="item in results"
              :key="item.id"
              class="search-item"
              @click="select(item)"
            >
              <span class="search-item__name">{{ item.name }}</span>
              <span class="badge">{{ item.type }}</span>
              <p v-if="item.short_summary" class="search-item__summary">
                {{ item.short_summary }}
              </p>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.search-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, .6);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 15vh;
  backdrop-filter: blur(2px);
}

.search-modal {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 560px;
  box-shadow: var(--shadow-lg);
  overflow: hidden;
}

.search-input-wrap {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: .75rem 1rem;
  border-bottom: 1px solid var(--color-border);
}

.search-input {
  flex: 1;
  background: transparent;
  border: none;
  color: var(--color-text);
  font-size: 1rem;
  outline: none;
  max-width: none;
}

.search-kbd {
  font-size: .7rem;
  padding: .15rem .4rem;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  color: var(--color-muted);
  background: var(--color-surface2);
}

.search-results {
  max-height: 400px;
  overflow-y: auto;
}

.search-status {
  padding: 1rem;
  text-align: center;
  color: var(--color-muted);
  font-size: .875rem;
}

.search-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.search-item {
  padding: .65rem 1rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
  transition: background var(--transition);
}

.search-item:hover {
  background: var(--color-surface2);
}

.search-item__name {
  font-weight: 500;
}

.search-item__summary {
  width: 100%;
  font-size: .8rem;
  color: var(--color-muted);
  margin: 0;
}
</style>
