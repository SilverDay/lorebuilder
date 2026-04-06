<script setup>
import { ref } from 'vue'
import { api } from '@/api/client.js'

const email   = ref('')
const loading = ref(false)
const sent    = ref(false)
const error   = ref('')

async function submit() {
  loading.value = true
  error.value   = ''
  try {
    await api.post('/api/v1/auth/password/reset-request', { email: email.value.trim() })
    sent.value = true
  } catch (e) {
    error.value = e.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-form">
      <h1>Forgot password</h1>

      <template v-if="!sent">
        <p>Enter your email address and we'll send you a reset link.</p>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <form @submit.prevent="submit">
          <label>
            Email address
            <input v-model="email" type="email" autocomplete="email" required />
          </label>
          <button type="submit" class="btn btn-primary" :disabled="loading" style="margin-top:.25rem">
            {{ loading ? 'Sending…' : 'Send reset link' }}
          </button>
        </form>
      </template>

      <template v-else>
        <p class="form-success">
          If that email is registered, a reset link has been sent. Check your inbox
          — the link expires in one hour.
        </p>
      </template>

      <p><RouterLink to="/login">← Back to sign in</RouterLink></p>
    </div>
  </div>
</template>
