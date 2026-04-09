<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useWorldStore } from '@/stores/world.js'
import { useTheme } from '@/composables/useTheme.js'

const emit = defineEmits(['search'])

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const worlds = useWorldStore()
const { effective, toggle } = useTheme()

const wid = computed(() => route.params.wid ?? null)

const isDashboard = computed(() => route.name === 'Dashboard')
const isAccount   = computed(() =>
  ['AccountSettings', 'ChangePassword', 'TwoFactor'].includes(route.name)
)

const themeIcon = computed(() => effective() === 'dark' ? '🌙' : '☀')

async function signOut() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <nav v-if="auth.isAuthenticated" class="app-nav">
    <RouterLink to="/worlds" class="app-nav__brand"><img :src="'/images/lorebuilder-logo.jpg'" alt="LoreBuilder" class="app-nav__logo" />LoreBuilder</RouterLink>
    <div class="app-nav__links">
      <RouterLink v-if="wid && !isDashboard" :to="`/worlds/${wid}`" class="btn btn-ghost btn-sm">Dashboard</RouterLink>
      <RouterLink v-if="isAccount && worlds.currentWorldId" :to="`/worlds/${worlds.currentWorldId}`" class="btn btn-ghost btn-sm">Dashboard</RouterLink>
      <RouterLink v-if="isAccount && !worlds.currentWorldId" to="/worlds" class="btn btn-ghost btn-sm">Worlds</RouterLink>
      <RouterLink v-if="!isAccount" to="/account/settings" class="btn btn-ghost btn-sm">Account</RouterLink>
      <button class="btn btn-ghost btn-sm" @click="signOut">Sign out</button>
      <button class="btn btn-ghost btn-sm app-nav__theme" @click="toggle" :aria-label="'Toggle theme'">{{ themeIcon }}</button>
    </div>
  </nav>

  <!-- World sub-navigation -->
  <nav v-if="auth.isAuthenticated && wid" class="world-subnav" aria-label="World sections">
    <RouterLink :to="`/worlds/${wid}/entities`"    class="world-subnav__link">Entities</RouterLink>
    <RouterLink :to="`/worlds/${wid}/graph`"        class="world-subnav__link">Graph</RouterLink>
    <RouterLink :to="`/worlds/${wid}/timeline`"     class="world-subnav__link">Timeline</RouterLink>
    <RouterLink :to="`/worlds/${wid}/arcs`"         class="world-subnav__link">Story Arcs</RouterLink>
    <RouterLink :to="`/worlds/${wid}/notes`"        class="world-subnav__link">Notes</RouterLink>
    <RouterLink :to="`/worlds/${wid}/open-points`"  class="world-subnav__link">Open Points</RouterLink>
    <button class="world-subnav__link world-subnav__search" @click="emit('search')" aria-label="Search entities (Ctrl+K)">Search</button>
  </nav>
</template>
