<script setup>
/**
 * EntityMeta — type badge, status, attributes table, tags.
 * Attributes are editable inline via PUT /entities/:id/attributes.
 */
import { ref } from 'vue'
import { api } from '@/api/client.js'

const props = defineProps({
  entity:   { type: Object, required: true },
  worldId:  { type: [String, Number], required: true },
  entityId: { type: [String, Number], required: true },
})

const emit = defineEmits(['refresh'])

const STATUS_COLOR = {
  draft:     'badge-draft',
  published: 'badge-published',
  archived:  'badge-archived',
}

const ATTR_TYPES = ['string', 'integer', 'boolean', 'date', 'markdown']

// ── Attribute editing ─────────────────────────────────────────────────────────
const editing  = ref(false)
const saving   = ref(false)
const saveError = ref('')
const draft    = ref([])

function startEdit() {
  draft.value = (props.entity.attributes ?? []).map(a => ({ ...a }))
  saveError.value = ''
  editing.value   = true
}

function cancelEdit() {
  editing.value = false
}

function addRow() {
  draft.value.push({
    attr_key:   '',
    attr_value: '',
    data_type:  'string',
    sort_order: draft.value.length,
  })
}

function removeRow(i) {
  draft.value.splice(i, 1)
}

function moveUp(i) {
  if (i === 0) return
  const tmp = draft.value[i - 1]
  draft.value[i - 1] = draft.value[i]
  draft.value[i]     = tmp
}

function moveDown(i) {
  if (i === draft.value.length - 1) return
  const tmp = draft.value[i + 1]
  draft.value[i + 1] = draft.value[i]
  draft.value[i]     = tmp
}

async function saveAttrs() {
  saveError.value = ''
  // Drop blank keys
  const payload = draft.value
    .filter(a => a.attr_key.trim() !== '')
    .map((a, i) => ({
      attr_key:   a.attr_key.trim(),
      attr_value: a.attr_value,
      data_type:  a.data_type || 'string',
      sort_order: i,
    }))

  saving.value = true
  try {
    await api.put(
      `/api/v1/worlds/${props.worldId}/entities/${props.entityId}/attributes`,
      { attributes: payload }
    )
    editing.value = false
    emit('refresh')
  } catch (e) {
    saveError.value = e.message || 'Failed to save attributes.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section>
    <div class="meta-badges">
      <span class="badge badge-type">{{ entity.type }}</span>
      <span class="badge" :class="STATUS_COLOR[entity.status]">{{ entity.status }}</span>
    </div>

    <p v-if="entity.short_summary" class="entity-summary">{{ entity.short_summary }}</p>

    <!-- Attributes -->
    <div class="attr-section-header">
      <h3>Attributes</h3>
      <button v-if="!editing" class="btn-icon" title="Edit attributes" @click="startEdit">✏</button>
    </div>

    <!-- Read mode -->
    <template v-if="!editing">
      <table v-if="entity.attributes?.length" class="attr-table">
        <tbody>
          <tr v-for="attr in entity.attributes" :key="attr.attr_key">
            <th>{{ attr.attr_key }}</th>
            <td>{{ attr.attr_value }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted" style="font-size:.85rem">No attributes.</p>
    </template>

    <!-- Edit mode -->
    <template v-else>
      <div class="attr-edit-list">
        <div v-for="(row, i) in draft" :key="i" class="attr-edit-row">
          <input v-model="row.attr_key"   class="attr-edit-key"   placeholder="Key"   maxlength="64" />
          <input v-model="row.attr_value" class="attr-edit-value" placeholder="Value" maxlength="4000" />
          <select v-model="row.data_type" class="attr-edit-type">
            <option v-for="t in ATTR_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
          <span class="attr-edit-actions">
            <button type="button" class="btn-icon" title="Move up"   @click="moveUp(i)"">↑</button>
            <button type="button" class="btn-icon" title="Move down" @click="moveDown(i)">↓</button>
            <button type="button" class="btn-icon btn-icon--danger" title="Remove" @click="removeRow(i)">✕</button>
          </span>
        </div>
      </div>

      <button type="button" class="btn btn-ghost btn-sm attr-add-btn" @click="addRow">+ Add attribute</button>

      <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>
      <div class="form-actions" style="margin-top:.5rem">
        <button type="button" class="btn btn-ghost btn-sm" @click="cancelEdit">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="saveAttrs">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </template>

    <!-- Tags -->
    <template v-if="entity.tags?.length">
      <h3>Tags</h3>
      <div class="tag-list">
        <span
          v-for="tag in entity.tags"
          :key="tag.id"
          class="tag"
          :style="{ backgroundColor: tag.color }"
        >{{ tag.name }}</span>
      </div>
    </template>
  </section>
</template>
