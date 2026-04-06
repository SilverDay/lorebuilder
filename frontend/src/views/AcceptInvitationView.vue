<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const token  = route.params.token

const invite  = ref(null)
const error   = ref('')
const loading = ref(false)

onMounted(async () => {
  try {
    const { data } = await api.get(`/api/v1/invitations/${token}`)
    invite.value = data
  } catch (e) {
    error.value = e.message || 'Invitation is invalid or has expired.'
  }
})

async function accept() {
  if (!auth.isAuthenticated) {
    router.push(`/login?redirect=/invitations/${token}`)
    return
  }
  loading.value = true
  try {
    await api.post(`/api/v1/invitations/${token}/accept`)
    router.push('/worlds')
  } catch (e) {
    error.value = e.message || 'Failed to accept invitation.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-form">
      <h1>World Invitation</h1>

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <template v-else-if="invite">
        <p>You have been invited to join <strong>{{ invite.world_name }}</strong> as <strong>{{ invite.role }}</strong>.</p>
        <button class="btn btn-primary" :disabled="loading" @click="accept">
          {{ loading ? 'Joining…' : 'Accept invitation' }}
        </button>
        <p v-if="!auth.isAuthenticated" class="hint">
          You'll need to sign in first.
        </p>
      </template>

      <p v-else class="loading">Checking invitation…</p>
    </div>
  </div>
</template>
