<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { api } from '@/api/client.js'
import EntityMeta from '@/components/EntityMeta.vue'
import NotesList from '@/components/NotesList.vue'
import RelationshipList from '@/components/RelationshipList.vue'
import AiPanel from '@/components/ai/AiPanel.vue'

const route  = useRoute()
const wid    = computed(() => route.params.wid)
const eid    = computed(() => route.params.eid)

const STATUS_COLOR = {
  draft:     'badge-draft',
  published: 'badge-published',
  archived:  'badge-archived',
}

const entity        = ref(null)
const notes         = ref([])
const relationships = ref([])
const openPoints    = ref([])
const loading       = ref(true)
const error         = ref('')

// ── Tags ──────────────────────────────────────────────────────────────────────
const worldTags      = ref([])
const editingTags    = ref(false)
const tagDraft       = ref([])   // array of tag IDs
const savingTags     = ref(false)
const tagError       = ref('')

function startTagEdit() {
  tagDraft.value = (entity.value.tags ?? []).map(t => t.id)
  tagError.value = ''
  editingTags.value = true
}

function cancelTagEdit() {
  editingTags.value = false
}

function toggleTag(tagId) {
  const idx = tagDraft.value.indexOf(tagId)
  if (idx === -1) tagDraft.value.push(tagId)
  else tagDraft.value.splice(idx, 1)
}

async function saveTags() {
  savingTags.value = true
  tagError.value   = ''
  try {
    await api.put(
      `/api/v1/worlds/${wid.value}/entities/${eid.value}/tags`,
      { tag_ids: tagDraft.value }
    )
    editingTags.value = false
    await load()
  } catch (e) {
    tagError.value = e.message || 'Failed to save tags.'
  } finally {
    savingTags.value = false
  }
}

// ── Open Points ───────────────────────────────────────────────────────────────
const STATUSES       = ['open', 'in_progress', 'resolved', 'wont_fix']
const STATUS_LABELS  = { open: 'Open', in_progress: 'In Progress', resolved: 'Resolved', wont_fix: "Won't Fix" }
const PRIORITIES     = ['low', 'medium', 'high', 'critical']
const PRIORITY_LABELS = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' }

const showAddPoint     = ref(false)
const pointForm        = ref({ title: '', description: '', priority: 'medium', status: 'open' })
const savingPoint      = ref(false)
const pointError       = ref('')

// Inline editing for open points
const editingPointId   = ref(null)
const editPointForm    = ref({})
const savingPointEdit  = ref(false)
const editPointError   = ref('')

async function addOpenPoint() {
  if (!pointForm.value.title.trim()) return
  savingPoint.value = true
  pointError.value  = ''
  try {
    await api.post(`/api/v1/worlds/${wid.value}/open-points`, {
      title:       pointForm.value.title.trim(),
      description: pointForm.value.description.trim() || null,
      priority:    pointForm.value.priority,
      status:      pointForm.value.status,
      entity_id:   parseInt(eid.value, 10),
    })
    pointForm.value = { title: '', description: '', priority: 'medium', status: 'open' }
    showAddPoint.value = false
    await loadOpenPoints()
  } catch (e) {
    pointError.value = e.message || 'Failed to add open point.'
  } finally {
    savingPoint.value = false
  }
}

function startEditPoint(pt) {
  editingPointId.value = pt.id
  editPointForm.value = {
    title:       pt.title,
    description: pt.description ?? '',
    status:      pt.status,
    priority:    pt.priority,
    resolution:  pt.resolution ?? '',
  }
  editPointError.value = ''
}

function cancelEditPoint() {
  editingPointId.value = null
}

async function saveEditPoint() {
  savingPointEdit.value = true
  editPointError.value  = ''
  try {
    await api.patch(`/api/v1/worlds/${wid.value}/open-points/${editingPointId.value}`, {
      title:       editPointForm.value.title.trim(),
      description: editPointForm.value.description.trim() || null,
      status:      editPointForm.value.status,
      priority:    editPointForm.value.priority,
      resolution:  editPointForm.value.resolution.trim() || null,
    })
    editingPointId.value = null
    await loadOpenPoints()
  } catch (e) {
    editPointError.value = e.message || 'Failed to save.'
  } finally {
    savingPointEdit.value = false
  }
}

async function deleteOpenPoint(id) {
  if (!confirm('Delete this open point?')) return
  try {
    await api.delete(`/api/v1/worlds/${wid.value}/open-points/${id}`)
    await loadOpenPoints()
  } catch (e) {
    pointError.value = e.message || 'Failed to delete.'
  }
}

async function loadOpenPoints() {
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid.value}/open-points`, { entity_id: eid.value, per_page: 100 })
    openPoints.value = data ?? []
  } catch { /* non-critical */ }
}

// ── Lore body rendering ──────────────────────────────────────────────────────
function renderMarkdown(content) {
  if (!content) return ''
  return DOMPurify.sanitize(marked.parse(content))
}

// ── Main load ─────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  error.value   = ''
  try {
    const [entityRes, notesRes, relsRes] = await Promise.all([
      api.get(`/api/v1/worlds/${wid.value}/entities/${eid.value}`),
      api.get(`/api/v1/worlds/${wid.value}/entities/${eid.value}/notes`),
      api.get(`/api/v1/worlds/${wid.value}/relationships`, { entity_id: eid.value }),
    ])
    entity.value        = entityRes.data
    notes.value         = notesRes.data ?? []
    relationships.value = relsRes.data  ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load entity.'
  } finally {
    loading.value = false
  }
  // Non-blocking secondary loads
  loadOpenPoints()
  api.get(`/api/v1/worlds/${wid.value}/tags`).then(r => { worldTags.value = r.data ?? [] }).catch(() => {})
}

onMounted(load)
watch(() => route.params.eid, () => {
  // Reset edit states when navigating to a different entity
  editingTags.value    = false
  editingPointId.value = null
  showAddPoint.value   = false
  load()
})
</script>

<template>
  <div class="page">
    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else-if="entity">
      <header class="page-header">
        <nav class="entity-nav">
          <RouterLink :to="`/worlds/${wid}/entities`" class="btn btn-ghost btn-sm">← All entities</RouterLink>
          <RouterLink :to="`/worlds/${wid}/entities/new`" class="btn btn-primary btn-sm">+ New entity</RouterLink>
        </nav>
      </header>

      <div class="entity-detail-grid">
        <!-- Left panel: attributes -->
        <aside class="panel panel-meta">
          <EntityMeta :entity="entity" :world-id="wid" :entity-id="eid" @refresh="load" />
        </aside>

        <!-- Centre panel: main content -->
        <main class="panel panel-notes">

          <!-- 1) Name + type/status badges + edit button -->
          <div class="entity-header-row">
            <div>
              <h1 class="entity-title">{{ entity.name }}</h1>
              <div class="meta-badges">
                <span class="badge badge-type">{{ entity.type }}</span>
                <span class="badge" :class="STATUS_COLOR[entity.status]">{{ entity.status }}</span>
              </div>
            </div>
            <RouterLink :to="`/worlds/${wid}/entities/${eid}/edit`" class="btn btn-secondary">
              Edit
            </RouterLink>
          </div>

          <!-- 2) Tags (editable) -->
          <div class="detail-section">
            <div class="detail-section-header">
              <h2>Tags</h2>
              <button v-if="!editingTags" class="btn-icon" title="Edit tags" aria-label="Edit tags" @click="startTagEdit">✏</button>
            </div>

            <template v-if="!editingTags">
              <div v-if="entity.tags?.length" class="tag-list">
                <span
                  v-for="tag in entity.tags"
                  :key="tag.id"
                  class="tag"
                  :style="{ backgroundColor: tag.color }"
                >{{ tag.name }}</span>
              </div>
              <p v-else class="muted" style="font-size:.85rem">No tags.</p>
            </template>

            <template v-else>
              <div class="tag-picker">
                <button
                  v-for="tag in worldTags"
                  :key="tag.id"
                  type="button"
                  class="tag tag-selectable"
                  :class="{ 'tag-selected': tagDraft.includes(tag.id) }"
                  :style="{ backgroundColor: tagDraft.includes(tag.id) ? tag.color : 'transparent', borderColor: tag.color, color: tagDraft.includes(tag.id) ? '#fff' : 'var(--color-text)' }"
                  @click="toggleTag(tag.id)"
                >{{ tag.name }}</button>
              </div>
              <p v-if="!worldTags.length" class="muted" style="font-size:.85rem">No tags defined for this world.</p>
              <p v-if="tagError" class="form-error" role="alert">{{ tagError }}</p>
              <div class="form-actions" style="margin-top:.5rem">
                <button type="button" class="btn btn-ghost btn-sm" @click="cancelTagEdit">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="savingTags" @click="saveTags">
                  {{ savingTags ? 'Saving…' : 'Save tags' }}
                </button>
              </div>
            </template>
          </div>

          <!-- 3) Short Summary -->
          <div v-if="entity.short_summary" class="detail-section">
            <h2>Summary</h2>
            <p class="entity-summary-text">{{ entity.short_summary }}</p>
          </div>

          <!-- 4) Lore Body -->
          <div v-if="entity.lore_body" class="detail-section">
            <h2>Lore</h2>
            <!-- eslint-disable-next-line vue/no-v-html -- sanitised by DOMPurify -->
            <div class="lore-body" v-html="renderMarkdown(entity.lore_body)"></div>
          </div>

          <!-- 5) Notes -->
          <div class="detail-section">
            <NotesList :notes="notes" :world-id="wid" :entity-id="eid" @refresh="load" />
          </div>

          <!-- 6) Open Points -->
          <div class="detail-section">
            <div class="detail-section-header">
              <h2>Open Points</h2>
              <button class="btn-icon" title="Add open point" aria-label="Add open point" @click="showAddPoint = !showAddPoint">＋</button>
            </div>

            <!-- Add form -->
            <div v-if="showAddPoint" class="open-point-add-form">
              <label>
                Title / Question *
                <input v-model="pointForm.title" type="text" required maxlength="512"
                       placeholder="What needs to be answered or resolved?" />
              </label>
              <label>
                Description
                <textarea v-model="pointForm.description" rows="2" maxlength="4000"
                          placeholder="Additional context…"></textarea>
              </label>
              <div class="form-row-inline">
                <label>
                  Priority
                  <select v-model="pointForm.priority">
                    <option v-for="p in PRIORITIES" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
                  </select>
                </label>
                <label>
                  Status
                  <select v-model="pointForm.status">
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
                  </select>
                </label>
              </div>
              <p v-if="pointError" class="form-error" role="alert">{{ pointError }}</p>
              <div class="form-actions">
                <button type="button" class="btn btn-ghost btn-sm" @click="showAddPoint = false">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="savingPoint || !pointForm.title.trim()" @click="addOpenPoint">
                  {{ savingPoint ? 'Saving…' : 'Add' }}
                </button>
              </div>
            </div>

            <!-- List -->
            <div v-if="openPoints.length" class="open-points-list">
              <div v-for="pt in openPoints" :key="pt.id" class="open-point-card" :class="'op-' + pt.priority">

                <!-- Read mode -->
                <template v-if="editingPointId !== pt.id">
                  <div class="op-header">
                    <span class="op-title">{{ pt.title }}</span>
                    <div class="op-badges">
                      <span class="badge" :class="'badge-op-' + pt.priority">{{ PRIORITY_LABELS[pt.priority] }}</span>
                      <span class="badge" :class="'badge-op-' + pt.status">{{ STATUS_LABELS[pt.status] }}</span>
                    </div>
                  </div>
                  <p v-if="pt.description" class="op-desc">{{ pt.description }}</p>
                  <p v-if="pt.resolution" class="op-resolution"><strong>Resolution:</strong> {{ pt.resolution }}</p>
                  <footer class="op-footer">
                    <span>{{ pt.creator_name }}</span>
                    <span>{{ pt.created_at }}</span>
                    <button class="btn btn-ghost btn-sm" @click="startEditPoint(pt)">Edit</button>
                    <button class="btn btn-ghost btn-sm" @click="deleteOpenPoint(pt.id)">Delete</button>
                  </footer>
                </template>

                <!-- Edit mode -->
                <template v-else>
                  <label>
                    Title
                    <input v-model="editPointForm.title" type="text" maxlength="512" />
                  </label>
                  <label>
                    Description
                    <textarea v-model="editPointForm.description" rows="2" maxlength="4000"></textarea>
                  </label>
                  <div class="form-row-inline">
                    <label>
                      Priority
                      <select v-model="editPointForm.priority">
                        <option v-for="p in PRIORITIES" :key="p" :value="p">{{ PRIORITY_LABELS[p] }}</option>
                      </select>
                    </label>
                    <label>
                      Status
                      <select v-model="editPointForm.status">
                        <option v-for="s in STATUSES" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
                      </select>
                    </label>
                  </div>
                  <label v-if="editPointForm.status === 'resolved'">
                    Resolution
                    <textarea v-model="editPointForm.resolution" rows="2" maxlength="4000"
                              placeholder="How was this resolved?"></textarea>
                  </label>
                  <p v-if="editPointError" class="form-error" role="alert">{{ editPointError }}</p>
                  <div class="form-actions">
                    <button type="button" class="btn btn-ghost btn-sm" @click="cancelEditPoint">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="savingPointEdit" @click="saveEditPoint">
                      {{ savingPointEdit ? 'Saving…' : 'Save' }}
                    </button>
                  </div>
                </template>
              </div>
            </div>
            <p v-else class="muted" style="font-size:.85rem">No open points for this entity.</p>
          </div>

        </main>

        <!-- Right panel: relationships -->
        <aside class="panel panel-rels">
          <RelationshipList :relationships="relationships" :entity-id="Number(eid)" :world-id="wid"
            @refresh="load" />
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
