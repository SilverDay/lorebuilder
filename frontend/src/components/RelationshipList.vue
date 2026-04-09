<script setup>
import { ref, computed, watch } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  relationships: { type: Array,  required: true },
  entityId:      { type: Number, required: true },
  worldId:       { type: [String, Number], required: true },
})

const emit = defineEmits(['refresh'])

// ── Entity list for "add" form (lazy-loaded when form opens) ──────────────────
const allEntities   = ref([])
const entitiesReady = ref(false)

async function loadEntities() {
  if (entitiesReady.value) return
  try {
    const { data } = await api.get(`/api/v1/worlds/${props.worldId}/entities`, { per_page: 500 })
    allEntities.value = (data ?? []).filter(e => e.id !== props.entityId)
    entitiesReady.value = true
  } catch {
    allEntities.value = []
  }
}

// ── Entity search / autocomplete ──────────────────────────────────────────────
const entitySearch      = ref('')
const showEntityResults = ref(false)
const selectedEntity    = ref(null)

const filteredEntities = computed(() => {
  if (!entitySearch.value.trim()) return allEntities.value.slice(0, 20)
  const q = entitySearch.value.toLowerCase()
  return allEntities.value
    .filter(e => e.name.toLowerCase().includes(q) || e.type.toLowerCase().includes(q))
    .slice(0, 20)
})

function selectEntity(e) {
  selectedEntity.value = e
  entitySearch.value   = `${e.name} (${e.type})`
  showEntityResults.value = false
  addForm.value.to_entity_id = e.id
}

function clearEntity() {
  selectedEntity.value = null
  entitySearch.value   = ''
  addForm.value.to_entity_id = ''
}

function onEntityBlur() {
  // Delay to allow click on result item to fire first
  setTimeout(() => { showEntityResults.value = false }, 200)
}

// ── Add form ──────────────────────────────────────────────────────────────────
const showAdd   = ref(false)
const addSaving = ref(false)
const addError  = ref('')
const addForm   = ref({ to_entity_id: '', rel_type: '', is_bidirectional: false, strength: '', notes: '' })

// ── Relationship type autocomplete ────────────────────────────────────────────
const knownRelTypes      = ref([])
const showRelTypeResults = ref(false)

async function loadRelTypes() {
  try {
    const { data } = await api.get(`/api/v1/worlds/${props.worldId}/relationships/types`)
    knownRelTypes.value = data ?? []
  } catch {
    knownRelTypes.value = []
  }
}

const filteredRelTypes = computed(() => {
  const q = addForm.value.rel_type.toLowerCase().trim()
  if (!q) return knownRelTypes.value.slice(0, 15)
  return knownRelTypes.value.filter(t => t.toLowerCase().includes(q)).slice(0, 15)
})

const relTypeIsNew = computed(() => {
  const q = addForm.value.rel_type.trim().toLowerCase()
  return q && !knownRelTypes.value.some(t => t.toLowerCase() === q)
})

function selectRelType(t) {
  addForm.value.rel_type = t
  showRelTypeResults.value = false
}

function onRelTypeBlur() {
  setTimeout(() => { showRelTypeResults.value = false }, 200)
}

function openAdd() {
  addForm.value = { to_entity_id: '', rel_type: '', is_bidirectional: false, strength: '', notes: '' }
  addError.value = ''
  clearEntity()
  showAdd.value  = true
  loadEntities()
  loadRelTypes()
}

async function saveAdd() {
  if (!addForm.value.to_entity_id || !addForm.value.rel_type.trim()) return
  addSaving.value = true
  addError.value  = ''
  try {
    await api.post(`/api/v1/worlds/${props.worldId}/relationships`, {
      from_entity_id:  props.entityId,
      to_entity_id:    Number(addForm.value.to_entity_id),
      rel_type:        addForm.value.rel_type.trim(),
      is_bidirectional: addForm.value.is_bidirectional,
      strength:        addForm.value.strength ? Number(addForm.value.strength) : null,
      notes:           addForm.value.notes || null,
    })
    showAdd.value = false
    emit('refresh')
  } catch (e) {
    addError.value = e.message || 'Failed to add relationship.'
  } finally {
    addSaving.value = false
  }
}

// ── Edit form ─────────────────────────────────────────────────────────────────
const editingId  = ref(null)
const editSaving = ref(false)
const editError  = ref('')
const editForm   = ref({})

function openEdit(rel) {
  editingId.value = rel.id
  editError.value = ''
  editForm.value  = {
    rel_type:         rel.rel_type,
    is_bidirectional: !!rel.bidirectional,
    strength:         rel.strength ?? '',
    notes:            rel.notes ?? '',
  }
}

async function saveEdit() {
  editSaving.value = true
  editError.value  = ''
  try {
    await api.patch(`/api/v1/worlds/${props.worldId}/relationships/${editingId.value}`, {
      rel_type:         editForm.value.rel_type.trim(),
      is_bidirectional: editForm.value.is_bidirectional,
      strength:         editForm.value.strength ? Number(editForm.value.strength) : null,
      notes:            editForm.value.notes || null,
    })
    editingId.value = null
    emit('refresh')
  } catch (e) {
    editError.value = e.message || 'Failed to save.'
  } finally {
    editSaving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────
const deletingId = ref(null)

async function deleteRel(id) {
  if (!confirm('Delete this relationship?')) return
  deletingId.value = id
  try {
    await api.delete(`/api/v1/worlds/${props.worldId}/relationships/${id}`)
    emit('refresh')
  } catch (e) {
    alert(e.message || 'Failed to delete.')
  } finally {
    deletingId.value = null
  }
}

// ── Display ───────────────────────────────────────────────────────────────────
const grouped = computed(() => {
  const map = {}
  for (const rel of props.relationships) {
    if (!map[rel.rel_type]) map[rel.rel_type] = []
    map[rel.rel_type].push(rel)
  }
  return map
})

function counterpart(rel) {
  return rel.from_entity_id === props.entityId
    ? { id: rel.to_entity_id,   name: rel.to_name,   type: rel.to_type }
    : { id: rel.from_entity_id, name: rel.from_name, type: rel.from_type }
}
</script>

<template>
  <section>
    <div class="rel-header">
      <h2>Relationships</h2>
      <button v-if="!showAdd" class="btn btn-ghost btn-sm" @click="openAdd">+ Add</button>
    </div>

    <!-- Add form -->
    <form v-if="showAdd" class="rel-edit-form" @submit.prevent="saveAdd">
      <label>
        Entity
        <div class="entity-search-wrapper">
          <input
            v-model="entitySearch"
            type="text"
            autocomplete="off"
            placeholder="Search entities…"
            @focus="showEntityResults = true"
            @blur="onEntityBlur"
            @input="selectedEntity = null; addForm.to_entity_id = ''"
          />
          <button v-if="selectedEntity" type="button" class="entity-search-clear" @click="clearEntity" title="Clear">✕</button>
          <ul v-if="showEntityResults && filteredEntities.length" class="entity-search-results">
            <li v-for="e in filteredEntities" :key="e.id" @mousedown.prevent="selectEntity(e)"
                :class="{ selected: selectedEntity?.id === e.id }">
              <span class="entity-search-name">{{ e.name }}</span>
              <span class="badge">{{ e.type }}</span>
            </li>
          </ul>
          <p v-if="showEntityResults && entitySearch && !filteredEntities.length" class="entity-search-empty">
            No matching entities
          </p>
        </div>
      </label>
      <label>
        Relationship type
        <div class="entity-search-wrapper">
          <input v-model="addForm.rel_type" type="text" maxlength="64"
                 placeholder="e.g. ally of, child of" required autocomplete="off"
                 @focus="showRelTypeResults = true"
                 @blur="onRelTypeBlur" />
          <ul v-if="showRelTypeResults && filteredRelTypes.length" class="entity-search-results">
            <li v-for="t in filteredRelTypes" :key="t" @mousedown.prevent="selectRelType(t)"
                :class="{ selected: addForm.rel_type === t }">
              <span class="entity-search-name">{{ t }}</span>
            </li>
          </ul>
          <p v-if="showRelTypeResults && relTypeIsNew && addForm.rel_type.trim()" class="reltype-new-hint">
            New type: <strong>{{ addForm.rel_type.trim() }}</strong>
          </p>
        </div>
      </label>
      <label class="rel-edit-inline">
        <input v-model="addForm.is_bidirectional" type="checkbox" />
        Bidirectional
      </label>
      <label>
        Strength (1–10, optional)
        <input v-model="addForm.strength" type="number" min="1" max="10" placeholder="–" />
      </label>
      <label>
        Notes
        <textarea v-model="addForm.notes" rows="2" maxlength="2000" placeholder="Optional context…"></textarea>
      </label>
      <p v-if="addError" class="form-error" role="alert">{{ addError }}</p>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost btn-sm" @click="showAdd = false">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" :disabled="addSaving">
          {{ addSaving ? 'Saving…' : 'Add' }}
        </button>
      </div>
    </form>

    <!-- Relationship groups -->
    <div v-if="Object.keys(grouped).length" class="rel-groups">
      <div v-for="(rels, type) in grouped" :key="type" class="rel-group">
        <h4 class="rel-type">{{ type }}</h4>
        <ul>
          <li v-for="rel in rels" :key="rel.id">

            <!-- Edit form (inline) -->
            <form v-if="editingId === rel.id" class="rel-edit-form" @submit.prevent="saveEdit">
              <label>
                Relationship type
                <input v-model="editForm.rel_type" type="text" maxlength="64" required />
              </label>
              <label class="rel-edit-inline">
                <input v-model="editForm.is_bidirectional" type="checkbox" />
                Bidirectional
              </label>
              <label>
                Strength (1–10, optional)
                <input v-model="editForm.strength" type="number" min="1" max="10" placeholder="–" />
              </label>
              <label>
                Notes
                <textarea v-model="editForm.notes" rows="2" maxlength="2000"></textarea>
              </label>
              <p v-if="editError" class="form-error" role="alert">{{ editError }}</p>
              <div class="form-actions">
                <button type="button" class="btn btn-ghost btn-sm" @click="editingId = null">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="editSaving">
                  {{ editSaving ? 'Saving…' : 'Save' }}
                </button>
              </div>
            </form>

            <!-- Read view -->
            <template v-else>
              <span class="badge badge-dir" :title="rel.bidirectional ? 'Bidirectional' : 'Unidirectional'">
                {{ rel.bidirectional ? '↔' : '→' }}
              </span>
              <RouterLink :to="`/worlds/${worldId}/entities/${counterpart(rel).id}`">
                {{ counterpart(rel).name }}
              </RouterLink>
              <span class="badge">{{ counterpart(rel).type }}</span>
              <span v-if="rel.strength" class="rel-strength">×{{ rel.strength }}</span>
              <span class="rel-actions">
                <button class="btn-icon" title="Edit" @click="openEdit(rel)">✏</button>
                <button class="btn-icon btn-icon--danger" title="Delete"
                        :disabled="deletingId === rel.id" @click="deleteRel(rel.id)">✕</button>
              </span>
            </template>

          </li>
        </ul>
      </div>
    </div>

    <p v-else-if="!showAdd" class="empty-state">No relationships yet.</p>
  </section>
</template>
