<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const wid = computed(() => route.params.wid ?? null)

async function signOut() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <nav v-if="auth.isAuthenticated" class="app-nav">
    <RouterLink to="/worlds" class="app-nav__brand">LoreBuilder</RouterLink>
    <div class="app-nav__links">
      <RouterLink v-if="wid" :to="`/worlds/${wid}`" class="btn btn-ghost btn-sm">Dashboard</RouterLink>
      <RouterLink to="/account/settings" class="btn btn-ghost btn-sm">Account</RouterLink>
      <button class="btn btn-ghost btn-sm" @click="signOut">Sign out</button>
    </div>
  </nav>
</template>
