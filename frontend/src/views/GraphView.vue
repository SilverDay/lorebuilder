<script setup>
/**
 * GraphView — vis-network entity relationship graph.
 *
 * Features:
 * - Nodes coloured by entity type
 * - Edge label = rel_type; width scaled by strength
 * - Physics toggle (force-directed ↔ static)
 * - Click node → navigate to EntityDetailView
 * - Bidirectional edges rendered with arrows on both ends
 * - Filter by entity type (toggle chips)
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Network, DataSet } from 'vis-network/standalone'
import { api } from '@/api/client.js'

const route   = useRoute()
const router  = useRouter()
const wid     = route.params.wid

const container  = ref(null)
const loading    = ref(true)
const error      = ref('')
const physics    = ref(true)
let   network    = null

// Raw graph data kept for re-filtering
let rawNodes = []
let rawEdges = []
let visNodes = null
let visEdges = null

// ── Colour palette keyed by entity type ──────────────────────────────────────
const TYPE_COLORS = {
  Character: { background: '#4A90A4', border: '#2c6b7f', font: '#ffffff' },
  Location:  { background: '#7BAF5F', border: '#4d7a38', font: '#ffffff' },
  Event:     { background: '#E08A4B', border: '#a85e24', font: '#ffffff' },
  Faction:   { background: '#9B59B6', border: '#6c3483', font: '#ffffff' },
  Artefact:  { background: '#C0392B', border: '#8e1b10', font: '#ffffff' },
  Creature:  { background: '#E74C3C', border: '#b03a2e', font: '#ffffff' },
  Concept:   { background: '#7F8C8D', border: '#555f60', font: '#ffffff' },
  StoryArc:  { background: '#F39C12', border: '#b07d0d', font: '#ffffff' },
  Timeline:  { background: '#1ABC9C', border: '#0e8c72', font: '#ffffff' },
  Race:      { background: '#8B5CF6', border: '#5b21b6', font: '#ffffff' },
}
const DEFAULT_COLOR = { background: '#95A5A6', border: '#6d7f80', font: '#ffffff' }

function nodeColor(type) {
  return TYPE_COLORS[type] ?? DEFAULT_COLOR
}

// ── Type filter ──────────────────────────────────────────────────────────────
// Track which types exist in the data and which are active
const availableTypes = ref([])            // sorted list of types present
const activeTypes    = reactive(new Set()) // toggled-on types

function toggleType(type) {
  if (activeTypes.has(type)) {
    activeTypes.delete(type)
  } else {
    activeTypes.add(type)
  }
  applyFilter()
}

function showAll() {
  availableTypes.value.forEach(t => activeTypes.add(t))
  applyFilter()
}

function hideAll() {
  activeTypes.clear()
  applyFilter()
}

const allActive = computed(() => activeTypes.size === availableTypes.value.length)
const noneActive = computed(() => activeTypes.size === 0)

function applyFilter() {
  if (!visNodes || !visEdges) return

  // Determine visible node IDs
  const visibleIds = new Set()
  const nodesToAdd = []
  const nodeIdsToRemove = []

  for (const n of rawNodes) {
    const visible = activeTypes.has(n.type)
    const exists  = visNodes.get(n.id) !== null
    if (visible) {
      visibleIds.add(n.id)
      if (!exists) nodesToAdd.push(mapNode(n))
    } else if (exists) {
      nodeIdsToRemove.push(n.id)
    }
  }

  // Determine visible edges (both endpoints must be visible)
  const edgesToAdd = []
  const edgeIdsToRemove = []

  for (const e of rawEdges) {
    const visible = visibleIds.has(e.from) && visibleIds.has(e.to)
    const exists  = visEdges.get(e.id) !== null
    if (visible && !exists) {
      edgesToAdd.push(mapEdge(e))
    } else if (!visible && exists) {
      edgeIdsToRemove.push(e.id)
    }
  }

  // Batch updates
  if (nodeIdsToRemove.length) visNodes.remove(nodeIdsToRemove)
  if (edgeIdsToRemove.length) visEdges.remove(edgeIdsToRemove)
  if (nodesToAdd.length)      visNodes.add(nodesToAdd)
  if (edgesToAdd.length)      visEdges.add(edgesToAdd)
}

// ── Node/Edge mapping helpers ────────────────────────────────────────────────
function mapNode(n) {
  return {
    id:    n.id,
    label: n.label,
    title: `${n.type} — ${n.status}`,
    color: nodeColor(n.type),
    font:  { color: nodeColor(n.type).font },
    shape: 'box',
  }
}

function mapEdge(e) {
  return {
    id:     e.id,
    from:   e.from,
    to:     e.to,
    label:  e.label,
    title:  e.label,
    width:  Math.max(1, Math.round((e.strength ?? 5) / 2)),
    arrows: e.is_bidirectional ? 'to, from' : 'to',
    font:   { size: 10, align: 'middle' },
  }
}

onMounted(async () => {
  loading.value = true
  error.value   = ''

  let graphData
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/graph`)
    graphData = data
  } catch (e) {
    error.value   = e.message || 'Failed to load graph.'
    loading.value = false
    return
  }

  loading.value = false

  // Stash raw data for filtering
  rawNodes = graphData.nodes ?? []
  rawEdges = graphData.edges ?? []

  // Discover types present in data and activate all
  const types = [...new Set(rawNodes.map(n => n.type))].sort()
  availableTypes.value = types
  types.forEach(t => activeTypes.add(t))

  visNodes = new DataSet(rawNodes.map(mapNode))
  visEdges = new DataSet(rawEdges.map(mapEdge))

  const options = {
    physics: {
      enabled:   physics.value,
      barnesHut: { gravitationalConstant: -8000, springLength: 150 },
    },
    interaction: { hover: true, tooltipDelay: 150 },
    edges: {
      smooth:     { type: 'continuous' },
      color:      { color: '#848484', highlight: '#333' },
      font:       { background: 'white' },
    },
    nodes: {
      borderWidth: 2,
      shadow: { enabled: true, size: 4 },
    },
  }

  await new Promise(resolve => setTimeout(resolve, 0))  // let DOM settle

  network = new Network(container.value, { nodes: visNodes, edges: visEdges }, options)

  // Click node → EntityDetailView
  network.on('click', params => {
    if (params.nodes.length === 1) {
      router.push(`/worlds/${wid}/entities/${params.nodes[0]}`)
    }
  })
})

onBeforeUnmount(() => {
  network?.destroy()
  network = null
})

function togglePhysics() {
  physics.value = !physics.value
  network?.setOptions({ physics: { enabled: physics.value } })
}
</script>

<template>
  <div class="graph-page">
    <header class="page-header">
      <h1>Entity Graph</h1>
      <div class="page-header-actions">
        <button class="btn btn-secondary" @click="togglePhysics">
          {{ physics ? 'Freeze layout' : 'Animate layout' }}
        </button>
      </div>
    </header>

    <!-- Type filter chips -->
    <div v-if="availableTypes.length" class="graph-filters">
      <span class="graph-filters-label">Filter:</span>
      <button
        v-for="t in availableTypes"
        :key="t"
        class="graph-chip"
        :class="{ 'graph-chip--off': !activeTypes.has(t) }"
        :style="activeTypes.has(t)
          ? { background: nodeColor(t).background, borderColor: nodeColor(t).border, color: nodeColor(t).font }
          : {}"
        @click="toggleType(t)"
      >{{ t }}</button>
      <button class="btn btn-ghost btn-sm" :disabled="allActive" @click="showAll">All</button>
      <button class="btn btn-ghost btn-sm" :disabled="noneActive" @click="hideAll">None</button>
    </div>

    <p v-if="loading" class="loading">Loading graph…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <div
      v-show="!loading && !error"
      ref="container"
      class="graph-container"
      role="img"
      aria-label="Entity relationship graph"
    ></div>
  </div>
</template>
