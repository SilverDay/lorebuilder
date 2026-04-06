/**
 * Auth Store — session user state
 *
 * Holds the authenticated user object returned by GET /api/v1/auth/me.
 * No API keys or secrets are ever stored here.
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, request } from '@/api/client.js'

export const useAuthStore = defineStore('auth', () => {
  const user    = ref(null)   // { id, username, display_name, totp_enabled }
  const loading = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  async function fetchMe() {
    loading.value = true
    try {
      // silent: true — a 401 here means "not logged in", not "session expired".
      // Without silent, the client would do window.location.href = '/login?expired=1'
      // inside the router guard, causing an infinite reload loop.
      const { data } = await request('/api/v1/auth/me', { silent: true })
      user.value = data
    } catch {
      user.value = null
    } finally {
      loading.value = false
    }
  }

  async function login(username, password, totpCode = null) {
    const body = { username, password }
    if (totpCode) body.totp_code = totpCode
    const { data } = await api.post('/api/v1/auth/login', body)
    user.value = data
    return data
  }

  async function logout() {
    await api.post('/api/v1/auth/logout')
    user.value = null
  }

  function clear() {
    user.value = null
  }

  return { user, loading, isAuthenticated, fetchMe, login, logout, clear }
})
