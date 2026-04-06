/**
 * World Store — current world + membership cache
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/api/client.js'

export const useWorldStore = defineStore('world', () => {
  const worlds       = ref([])     // list of worlds the user is a member of
  const currentWorld = ref(null)   // { id, slug, name, genre, tone, … }
  const membership   = ref(null)   // current user's role in currentWorld

  const currentWorldId = computed(() => currentWorld.value?.id ?? null)

  async function fetchWorlds() {
    const { data } = await api.get('/api/v1/worlds')
    worlds.value = data ?? []
    return worlds.value
  }

  async function loadWorld(worldId) {
    const { data } = await api.get(`/api/v1/worlds/${worldId}`)
    currentWorld.value = data
    membership.value   = data?.membership ?? null
    return data
  }

  function setWorld(world) {
    currentWorld.value = world
    membership.value   = world?.membership ?? null
  }

  function clear() {
    worlds.value       = []
    currentWorld.value = null
    membership.value   = null
  }

  return {
    worlds,
    currentWorld,
    membership,
    currentWorldId,
    fetchWorlds,
    loadWorld,
    setWorld,
    clear,
  }
})
