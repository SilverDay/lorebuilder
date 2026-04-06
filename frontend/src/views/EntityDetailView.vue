<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'
import EntityMeta from '@/components/EntityMeta.vue'
import NotesList from '@/components/NotesList.vue'
import RelationshipList from '@/components/RelationshipList.vue'
import AiPanel from '@/components/ai/AiPanel.vue'

const route  = useRoute()
const router = useRouter()
const wid    = route.params.wid
const eid    = route.params.eid

const entity        = ref(null)
const notes         = ref([])
const relationships = ref([])
const loading       = ref(true)
const error         = ref('')

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const [entityRes, notesRes, relsRes] = await Promise.all([
      api.get(`/api/v1/worlds/${wid}/entities/${eid}`),
      api.get(`/api/v1/worlds/${wid}/entities/${eid}/notes`),
      api.get(`/api/v1/worlds/${wid}/relationships`, { from_entity: eid }),
    ])
    entity.value        = entityRes.data
    notes.value         = notesRes.data ?? []
    relationships.value = relsRes.data  ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load entity.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else-if="entity">
      <header class="page-header">
        <h1>{{ entity.name }}</h1>
        <div class="page-header-actions">
          <RouterLink :to="`/worlds/${wid}/entities/${eid}/edit`" class="btn btn-secondary">
            Edit
          </RouterLink>
          <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Back</RouterLink>
        </div>
      </header>

      <div class="entity-detail-grid">
        <!-- Left panel: metadata -->
        <aside class="panel panel-meta">
          <EntityMeta :entity="entity" />
        </aside>

        <!-- Centre panel: lore notes -->
        <main class="panel panel-notes">
          <NotesList :notes="notes" :world-id="wid" :entity-id="eid" @refresh="load" />
        </main>

        <!-- Right panel: relationships -->
        <aside class="panel panel-rels">
          <RelationshipList :relationships="relationships" :entity-id="Number(eid)" :world-id="wid" />
        </aside>
      </div>
    </template>

    <!-- AI Assistant floating panel -->
    <AiPanel
      :world-id="wid"
      :entity-id="eid"
      @response="load"
    />
  </div>
</template>
