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
      <button v-if="wid" class="btn btn-ghost btn-sm" @click="emit('search')" aria-label="Search entities (Ctrl+K)">Search</button>
      <RouterLink v-if="!isAccount" to="/account/settings" class="btn btn-ghost btn-sm">Account</RouterLink>
      <button class="btn btn-ghost btn-sm" @click="signOut">Sign out</button>
      <button class="btn btn-ghost btn-sm app-nav__theme" @click="toggle" :aria-label="'Toggle theme'">{{ themeIcon }}</button>
    </div>
  </nav>
</template>
