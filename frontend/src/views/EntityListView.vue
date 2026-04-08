<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const route  = useRoute()
const router = useRouter()
const wid    = route.params.wid

const entities = ref([])
const total    = ref(0)
const loading  = ref(false)
const filter   = ref({ type: '', status: '', tag: '', q: '' })
const page     = ref(1)
const limit    = 30

const TYPES = ['Character','Location','Event','Faction','Artefact','Creature','Concept','StoryArc','Timeline','Race']

async function load() {
  loading.value = true
  try {
    const params = {
      limit,
      offset: (page.value - 1) * limit,
      ...(filter.value.type   ? { type:   filter.value.type   } : {}),
      ...(filter.value.status ? { status: filter.value.status } : {}),
      ...(filter.value.tag    ? { tag:    filter.value.tag    } : {}),
      ...(filter.value.q      ? { q:      filter.value.q      } : {}),
    }
    const endpoint = filter.value.q
      ? `/api/v1/worlds/${wid}/search`
      : `/api/v1/worlds/${wid}/entities`

    const { data, meta } = await api.get(endpoint, params)
    entities.value = data ?? []
    total.value    = meta?.total ?? entities.value.length
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(filter, () => { page.value = 1; load() }, { deep: true })
watch(page, load)
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Entities</h1>
      <div class="page-header-actions">
        <RouterLink :to="`/worlds/${wid}/entities/new`" class="btn btn-primary">New entity</RouterLink>
      </div>
    </header>

    <div class="filter-bar">
      <input v-model="filter.q"      type="search"  placeholder="Search…" />
      <select v-model="filter.type"  aria-label="Filter by type">
        <option value="">All types</option>
        <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
      </select>
      <select v-model="filter.status" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="archived">Archived</option>
      </select>
    </div>

    <p v-if="loading" class="loading">Loading…</p>

    <ul v-else-if="entities.length" class="entity-list">
      <li v-for="e in entities" :key="e.id" class="entity-row">
        <RouterLink :to="`/worlds/${wid}/entities/${e.id}`">
          <span class="entity-name">{{ e.name }}</span>
          <span class="badge">{{ e.type }}</span>
          <span class="badge badge-status" :data-status="e.status">{{ e.status }}</span>
        </RouterLink>
        <p v-if="e.short_summary" class="entity-summary">{{ e.short_summary }}</p>
      </li>
    </ul>

    <p v-else class="empty-state">No entities found.</p>

    <div v-if="total > limit" class="pagination">
      <button :disabled="page === 1" @click="page--">Previous</button>
      <span>Page {{ page }} of {{ Math.ceil(total / limit) }}</span>
      <button :disabled="page * limit >= total" @click="page++">Next</button>
    </div>
  </div>
</template>
