<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  relationships: { type: Array,  required: true },
  entityId:      { type: Number, required: true },
  worldId:       { type: [String, Number], required: true },
})

const emit = defineEmits(['refresh'])

// ── Entity list for "add" form ────────────────────────────────────────────────
const allEntities = ref([])
onMounted(async () => {
  const { data } = await api.get(`/api/v1/worlds/${props.worldId}/entities`, { per_page: 500 })
  allEntities.value = (data ?? []).filter(e => e.id !== props.entityId)
})

// ── Add form ──────────────────────────────────────────────────────────────────
const showAdd   = ref(false)
const addSaving = ref(false)
const addError  = ref('')
const addForm   = ref({ to_entity_id: '', rel_type: '', is_bidirectional: false, strength: '', notes: '' })

function openAdd() {
  addForm.value = { to_entity_id: '', rel_type: '', is_bidirectional: false, strength: '', notes: '' }
  addError.value = ''
  showAdd.value  = true
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
        <select v-model="addForm.to_entity_id" required>
          <option value="" disabled>Select entity…</option>
          <option v-for="e in allEntities" :key="e.id" :value="e.id">
            {{ e.name }} ({{ e.type }})
          </option>
        </select>
      </label>
      <label>
        Relationship type
        <input v-model="addForm.rel_type" type="text" maxlength="64" placeholder="e.g. ally of, child of" required />
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
              <RouterLink :to="`/worlds/${worldId}/entities/${counterpart(rel).id}`">
                {{ counterpart(rel).name }}
              </RouterLink>
              <span class="badge">{{ counterpart(rel).type }}</span>
              <span v-if="rel.strength" class="rel-strength">×{{ rel.strength }}</span>
              <span v-if="rel.bidirectional" class="badge badge-bi">↔</span>
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
