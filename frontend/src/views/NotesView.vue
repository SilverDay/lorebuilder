<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const notes    = ref([])
const total    = ref(0)
const loading  = ref(false)
const error    = ref('')
const page     = ref(1)
const perPage  = 30
const filterCanonical  = ref('')   // '' | '1' | '0'
const filterAi         = ref('')   // '' | '1' | '0'

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const params = { page: page.value, per_page: perPage }
    if (filterCanonical.value !== '') params.canonical    = filterCanonical.value
    if (filterAi.value !== '')        params.ai_generated = filterAi.value
    const { data, meta } = await api.get(`/api/v1/worlds/${wid}/notes`, params)
    notes.value = data ?? []
    total.value = meta?.total ?? notes.value.length
  } catch (e) {
    error.value = e.message || 'Failed to load notes.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([filterCanonical, filterAi], () => { page.value = 1; load() })
watch(page, load)

const totalPages = computed(() => Math.ceil(Math.max(1, total.value) / perPage))

function excerpt(content) {
  if (!content) return ''
  const plain = content.replace(/[#*_`>\-]/g, '').trim()
  return plain.length > 160 ? plain.slice(0, 160) + '…' : plain
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Lore Notes</h1>
    </header>

    <p class="muted" style="margin-bottom:1rem">
      All notes across this world. Entity notes can also be added from each entity's detail page.
    </p>

    <!-- Filters -->
    <div class="filter-bar">
      <select v-model="filterCanonical" aria-label="Filter by canonical">
        <option value="">All notes</option>
        <option value="1">Canonical only</option>
        <option value="0">Non-canonical only</option>
      </select>
      <select v-model="filterAi" aria-label="Filter by source">
        <option value="">Any source</option>
        <option value="1">AI-generated</option>
        <option value="0">Human-written</option>
      </select>
    </div>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <div v-if="notes.length" class="notes-feed">
        <div v-for="note in notes" :key="note.id" class="note-feed-card">
          <div class="note-feed-card__header">
            <span v-if="note.is_canonical" class="badge badge-canonical">Canonical</span>
            <span v-if="note.ai_generated" class="badge badge-ai">AI</span>
            <RouterLink
              v-if="note.entity_id"
              :to="`/worlds/${wid}/entities/${note.entity_id}`"
              class="note-feed-card__entity"
            >{{ note.entity_name }}</RouterLink>
            <span v-else class="note-feed-card__entity note-feed-card__entity--world">World</span>
            <span class="note-feed-card__meta">{{ note.author_name }} · {{ note.created_at }}</span>
          </div>
          <p class="note-feed-card__excerpt">{{ excerpt(note.content) }}</p>
          <RouterLink
            v-if="note.entity_id"
            :to="`/worlds/${wid}/entities/${note.entity_id}`"
            class="note-feed-card__link"
          >View entity →</RouterLink>
        </div>
      </div>
      <p v-else class="empty-state">No notes found.</p>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="page === 1" @click="page--">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <button :disabled="page >= totalPages" @click="page++">Next</button>
      </div>
    </template>
  </div>
</template>
