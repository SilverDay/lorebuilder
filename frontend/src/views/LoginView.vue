<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { ApiError, ErrorCode } from '@/api/client.js'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const username  = ref('')
const password  = ref('')
const totpCode  = ref('')
const needsTotp = ref(false)
const error     = ref('')
const loading   = ref(false)

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    await auth.login(
      username.value.trim(),
      password.value,
      needsTotp.value ? totpCode.value.trim() : null
    )
    const redirect = route.query.redirect ?? '/worlds'
    router.push(redirect)
  } catch (e) {
    if (e instanceof ApiError && e.code === ErrorCode.AUTH_TOTP_REQUIRED) {
      needsTotp.value = true
      error.value     = 'Two-factor authentication required.'
    } else {
      error.value = e.message || 'Login failed.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <form class="auth-form" @submit.prevent="submit">
      <h1>LoreBuilder</h1>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <label>
        Username or email
        <input v-model="username" type="text" autocomplete="username" required />
      </label>

      <label>
        Password
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>

      <label v-if="needsTotp">
        Authenticator Code
        <input v-model="totpCode" type="text" inputmode="numeric" pattern="[0-9]{6}"
               autocomplete="one-time-code" placeholder="6-digit code" />
      </label>

      <button type="submit" :disabled="loading">
        {{ loading ? 'Signing in…' : 'Sign in' }}
      </button>

      <p><RouterLink to="/forgot-password">Forgot your password?</RouterLink></p>
      <p><RouterLink to="/register">Create an account</RouterLink></p>
    </form>
  </div>
</template>
