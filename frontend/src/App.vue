<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useTheme } from '@/composables/useTheme.js'
import ToastContainer from '@/components/ToastContainer.vue'
import SearchModal from '@/components/SearchModal.vue'

const auth  = useAuthStore()
const route = useRoute()
const { theme, cycle } = useTheme()

const showSearch = ref(false)

const currentWorldId = computed(() => route.params?.wid ?? null)

const themeIcon = computed(() => {
  if (theme.value === 'light') return '☀'
  if (theme.value === 'dark') return '🌙'
  return '⚙'
})

const themeLabel = computed(() => {
  if (theme.value === 'light') return 'Theme: Light — click to switch'
  if (theme.value === 'dark') return 'Theme: Dark — click to switch'
  return 'Theme: System — click to switch'
})

function onKeydown(e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
    e.preventDefault()
    if (auth.isAuthenticated && currentWorldId.value) {
      showSearch.value = true
    }
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <RouterView />
  <ToastContainer />
  <SearchModal
    v-if="showSearch && currentWorldId"
    :worldId="currentWorldId"
    @close="showSearch = false"
  />
  <button
    class="theme-toggle"
    :aria-label="themeLabel"
    @click="cycle"
  >{{ themeIcon }}</button>
</template>
