<script setup>
/**
 * StoryEntityList — linked entities with search, add, and remove.
 * Also includes entity scan button for discovering unlinked entities.
 */
import { ref } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  worldId:  { type: [Number, String], required: true },
  storyId:  { type: [Number, String], required: true },
  entities: { type: Array, default: () => [] },
})

const emit = defineEmits(['refresh'])

const error          = ref('')
const entitySearch   = ref('')
const searchResults  = ref([])
const searching      = ref(false)
const addRole        = ref('')

// Entity scan state
const scanning       = ref(false)
const scanResults    = ref(null)   // { found: [], unlinked: [] }

let searchTimeout = null

function onSearch() {
  clearTimeout(searchTimeout)
  const q = entitySearch.value.trim()
  if (q.length < 2) { searchResults.value = []; return }
  searchTimeout = setTimeout(async () => {
    searching.value = true
    try {
      const { data } = await api.get(`/api/v1/worlds/${props.worldId}/entities`, { q, limit: 10 })
      const linked = new Set(props.entities.map(e => e.entity_id))
      searchResults.value = (data ?? []).filter(e => !linked.has(e.id))
    } catch {
      searchResults.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

async function addEntity(entity) {
  error.value = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/entities`, {
      entity_id: entity.id,
      role: addRole.value.trim() || null,
    })
    entitySearch.value  = ''
    searchResults.value = []
    addRole.value       = ''
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to add entity.'
  }
}

async function removeEntity(entityId) {
  error.value = ''
  try {
    await api.delete(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/entities/${entityId}`)
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to remove entity.'
  }
}

async function linkScanned(entity) {
  error.value = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/entities`, {
      entity_id: entity.id,
      role: null,
    })
    // Remove from unlinked list
    if (scanResults.value) {
      scanResults.value.unlinked = scanResults.value.unlinked.filter(e => e.id !== entity.id)
      scanResults.value.found.push(entity)
    }
    emit('refresh')
  } catch (e) {
    error.value = e.message || 'Failed to link entity.'
  }
}

async function scanEntities() {
  scanning.value  = true
  scanResults.value = null
  error.value     = ''
  try {
    const { data } = await api.post(`/api/v1/worlds/${props.worldId}/stories/${props.storyId}/ai/scan-entities`)
    scanResults.value = data
  } catch (e) {
    error.value = e.message || 'Scan failed.'
  } finally {
    scanning.value = false
  }
}
</script>

<template>
  <div class="story-entities">
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <!-- Linked entities -->
    <ul v-if="entities.length" class="story-entities__list">
      <li v-for="ent in entities" :key="ent.entity_id" class="story-entities__item">
        <RouterLink :to="`/worlds/${worldId}/entities/${ent.entity_id}`" class="story-entities__link">
          {{ ent.entity_name }}
        </RouterLink>
        <span class="badge">{{ ent.entity_type }}</span>
        <span v-if="ent.role" class="badge badge-role">{{ ent.role }}</span>
        <button class="btn btn-ghost btn-sm" title="Unlink entity" @click="removeEntity(ent.entity_id)">✕</button>
      </li>
    </ul>
    <p v-else class="empty-state-sm">No entities linked yet.</p>

    <!-- Add entity search -->
    <div class="story-entities__add">
      <div class="story-entities__search-row">
        <input
          v-model="entitySearch"
          type="text"
          placeholder="Search entities…"
          @input="onSearch"
        />
        <input
          v-model="addRole"
          type="text"
          placeholder="Role"
          maxlength="128"
          style="max-width: 120px;"
        />
      </div>
      <ul v-if="searchResults.length" class="arc-search-results">
        <li v-for="ent in searchResults" :key="ent.id" class="arc-search-item" @click="addEntity(ent)">
          <span>{{ ent.name }}</span>
          <span class="badge">{{ ent.type }}</span>
        </li>
      </ul>
      <p v-else-if="searching" class="empty-state-sm">Searching…</p>
      <p v-else-if="entitySearch.length >= 2 && !searchResults.length" class="empty-state-sm">No matching entities.</p>
    </div>

    <!-- Entity scan -->
    <div class="story-entities__scan">
      <button class="btn btn-secondary btn-sm" :disabled="scanning" @click="scanEntities">
        {{ scanning ? 'Scanning…' : 'Scan for Entities' }}
      </button>

      <div v-if="scanResults" class="story-entities__scan-results">
        <div v-if="scanResults.unlinked?.length">
          <p class="story-entities__scan-label">Found but not linked:</p>
          <ul class="story-entities__list">
            <li v-for="ent in scanResults.unlinked" :key="ent.id" class="story-entities__item">
              <span>{{ ent.name }}</span>
              <span class="badge">{{ ent.type }}</span>
              <button class="btn btn-primary btn-sm" @click="linkScanned(ent)">Link</button>
            </li>
          </ul>
        </div>
        <div v-if="scanResults.found?.length">
          <p class="story-entities__scan-label">Already linked: {{ scanResults.found.length }} entities ✓</p>
        </div>
        <p v-if="!scanResults.unlinked?.length && !scanResults.found?.length" class="empty-state-sm">
          No entity names found in the story text.
        </p>
      </div>
    </div>
  </div>
</template>
