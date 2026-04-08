<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useTheme } from '@/composables/useTheme.js'
import ToastContainer from '@/components/ToastContainer.vue'
import SearchModal from '@/components/SearchModal.vue'

const auth  = useAuthStore()
const route = useRoute()
const { theme, effective, toggle } = useTheme()

const showSearch = ref(false)

const currentWorldId = computed(() => route.params?.wid ?? null)

const themeIcon = computed(() => {
  return effective() === 'dark' ? '🌙' : '☀'
})

const themeLabel = computed(() => {
  return effective() === 'dark'
    ? 'Theme: Dark — click to switch to light'
    : 'Theme: Light — click to switch to dark'
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
    @click="toggle"
  >{{ themeIcon }}</button>
</template>
