<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useTheme } from '@/composables/useTheme.js'
import AppNav from '@/components/AppNav.vue'
import ToastContainer from '@/components/ToastContainer.vue'
import SearchModal from '@/components/SearchModal.vue'

const auth  = useAuthStore()
const route = useRoute()
const { effective, toggle } = useTheme()

const showSearch = ref(false)

const currentWorldId = computed(() => route.params?.wid ?? null)

const themeIcon = computed(() => effective() === 'dark' ? '🌙' : '☀')

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

function openSearch() {
  if (auth.isAuthenticated && currentWorldId.value) {
    showSearch.value = true
  }
}
</script>

<template>
  <AppNav @search="openSearch" />
  <RouterView />
  <ToastContainer />
  <SearchModal
    v-if="showSearch && currentWorldId"
    :worldId="currentWorldId"
    @close="showSearch = false"
  />
  <button
    v-if="!auth.isAuthenticated"
    class="theme-toggle"
    aria-label="Toggle theme"
    @click="toggle"
  >{{ themeIcon }}</button>
</template>
