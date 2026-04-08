<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useWorldStore } from '@/stores/world.js'
import { useAuthStore } from '@/stores/auth.js'

const router = useRouter()
const worlds = useWorldStore()
const auth   = useAuthStore()

onMounted(() => worlds.fetchWorlds())

function open(world) {
  router.push(`/worlds/${world.id}`)   // → Dashboard
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Your Worlds</h1>
      <div class="page-header-actions">
        <RouterLink to="/worlds/new" class="btn btn-primary">New world</RouterLink>
        <RouterLink to="/account/settings" class="btn btn-ghost">Account</RouterLink>
        <button class="btn btn-ghost" @click="auth.logout().then(() => $router.push('/login'))">
          Sign out
        </button>
      </div>
    </header>

    <ul v-if="worlds.worlds.length" class="world-list">
      <li v-for="w in worlds.worlds" :key="w.id" class="world-card" @click="open(w)">
        <div class="world-card-title">{{ w.name }}</div>
        <div class="world-card-meta">
          <span v-if="w.genre" class="badge">{{ w.genre }}</span>
          <span class="badge badge-role">{{ w.membership?.role }}</span>
        </div>
        <p v-if="w.description" class="world-card-desc">{{ w.description }}</p>
      </li>
    </ul>

    <p v-else class="empty-state">No worlds yet. Create your first one!</p>
  </div>
</template>
