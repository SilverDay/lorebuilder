<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const route  = useRoute()
const router = useRouter()

const token    = ref('')
const password = ref('')
const confirm  = ref('')
const loading  = ref(false)
const done     = ref(false)
const error    = ref('')

onMounted(() => {
  token.value = route.query.token ?? ''
  if (!token.value) error.value = 'No reset token found. Please request a new reset link.'
})

async function submit() {
  if (password.value !== confirm.value) {
    error.value = 'Passwords do not match.'
    return
  }
  loading.value = true
  error.value   = ''
  try {
    await api.post('/api/v1/auth/password/reset', {
      token:    token.value,
      password: password.value,
    })
    done.value = true
    setTimeout(() => router.push('/login'), 3000)
  } catch (e) {
    error.value = e.message || 'This link is invalid or has expired.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-form">
      <h1>Reset password</h1>

      <template v-if="done">
        <p class="form-success">Password updated. Redirecting you to sign in…</p>
      </template>

      <template v-else>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <form v-if="token" @submit.prevent="submit">
          <label>
            New password
            <input v-model="password" type="password" autocomplete="new-password"
                   required minlength="12" />
            <small>Minimum 12 characters.</small>
          </label>
          <label>
            Confirm new password
            <input v-model="confirm" type="password" autocomplete="new-password" required />
          </label>
          <button type="submit" class="btn btn-primary" :disabled="loading" style="margin-top:.25rem">
            {{ loading ? 'Saving…' : 'Set new password' }}
          </button>
        </form>
      </template>

      <p><RouterLink to="/login">← Back to sign in</RouterLink></p>
    </div>
  </div>
</template>
