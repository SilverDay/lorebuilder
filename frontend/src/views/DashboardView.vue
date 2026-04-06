<script setup>
/**
 * DashboardView — world overview.
 *
 * Shows:
 * - Entity counts by type (stat cards)
 * - Arc status summary
 * - Recent audit activity (last 10 events)
 * - Quick links to graph, timeline, arcs, entity list
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useWorldStore } from '@/stores/world.js'
import { api } from '@/api/client.js'

const route  = useRoute()
const router = useRouter()
const worlds = useWorldStore()
const wid    = route.params.wid

const stats   = ref(null)
const loading = ref(false)
const error   = ref('')

// Aggregate entity counts to one total per type (across statuses)
const entityByType = computed(() => {
  if (!stats.value) return []
  const map = {}
  for (const row of stats.value.entity_counts ?? []) {
    if (!map[row.type]) map[row.type] = 0
    map[row.type] += Number(row.count)
  }
  return Object.entries(map).map(([type, count]) => ({ type, count }))
    .sort((a, b) => b.count - a.count)
})

const arcByStatus = computed(() => stats.value?.arc_summary ?? [])
const recentActivity = computed(() => stats.value?.recent_activity ?? [])

// Load world metadata if not already in store
onMounted(async () => {
  loading.value = true
  error.value   = ''
  try {
    if (!worlds.currentWorld || worlds.currentWorldId !== Number(wid)) {
      await worlds.loadWorld(wid)
    }
    const { data } = await api.get(`/api/v1/worlds/${wid}/stats`)
    stats.value = data
  } catch (e) {
    error.value = e.message || 'Failed to load dashboard.'
  } finally {
    loading.value = false
  }
})

const ARC_STATUS_LABEL = {
  seed:          'Seed',
  rising_action: 'Rising Action',
  climax:        'Climax',
  resolution:    'Resolution',
  complete:      'Complete',
  abandoned:     'Abandoned',
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>{{ worlds.currentWorld?.name ?? 'Dashboard' }}</h1>
      <div class="page-header-actions">
        <RouterLink :to="`/worlds/${wid}/entities`" class="btn btn-secondary">Entities</RouterLink>
        <RouterLink :to="`/worlds/${wid}/graph`" class="btn btn-secondary">Graph</RouterLink>
        <RouterLink :to="`/worlds/${wid}/timeline`" class="btn btn-secondary">Timeline</RouterLink>
        <RouterLink :to="`/worlds/${wid}/arcs`" class="btn btn-secondary">Story Arcs</RouterLink>
        <RouterLink :to="`/worlds/${wid}/members`" class="btn btn-ghost">Members</RouterLink>
        <RouterLink :to="`/worlds/${wid}/export`" class="btn btn-ghost">Export</RouterLink>
        <RouterLink :to="`/worlds/${wid}/settings/ai`" class="btn btn-ghost">AI Settings</RouterLink>
        <RouterLink to="/worlds" class="btn btn-ghost">All worlds</RouterLink>
      </div>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else-if="stats">
      <!-- Entity counts -->
      <section class="dashboard-section">
        <h2>Entities</h2>
        <div class="stat-cards">
          <div v-for="row in entityByType" :key="row.type" class="stat-card">
            <div class="stat-value">{{ row.count }}</div>
            <div class="stat-label">{{ row.type }}</div>
          </div>
          <div v-if="!entityByType.length" class="empty-state">No entities yet.</div>
        </div>
      </section>

      <!-- Arc health -->
      <section class="dashboard-section">
        <h2>Story Arc Status</h2>
        <div class="stat-cards">
          <div v-for="row in arcByStatus" :key="row.status" class="stat-card">
            <div class="stat-value">{{ row.count }}</div>
            <div class="stat-label">{{ ARC_STATUS_LABEL[row.status] ?? row.status }}</div>
          </div>
          <div v-if="!arcByStatus.length" class="empty-state">No arcs yet.</div>
        </div>
      </section>

      <!-- Recent activity -->
      <section class="dashboard-section">
        <h2>Recent Activity</h2>
        <ul v-if="recentActivity.length" class="activity-list">
          <li v-for="entry in recentActivity" :key="entry.created_at + entry.action" class="activity-entry">
            <span class="activity-actor">{{ entry.actor_name ?? 'System' }}</span>
            <span class="activity-action">{{ entry.action }}</span>
            <span v-if="entry.target_type" class="activity-target">
              {{ entry.target_type }} #{{ entry.target_id }}
            </span>
            <span class="activity-time">{{ entry.created_at }}</span>
          </li>
        </ul>
        <p v-else class="empty-state">No activity yet.</p>
      </section>
    </template>
  </div>
</template>
