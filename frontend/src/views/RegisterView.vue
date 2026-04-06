<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client.js'

const router   = useRouter()
const username = ref('')
const email    = ref('')
const password = ref('')
const confirm  = ref('')
const error    = ref('')
const loading  = ref(false)
const success  = ref(false)

async function submit() {
  error.value = ''
  if (password.value !== confirm.value) {
    error.value = 'Passwords do not match.'
    return
  }
  loading.value = true
  try {
    await api.post('/api/v1/auth/register', {
      username: username.value.trim(),
      email:    email.value.trim(),
      password: password.value,
    })
    success.value = true
  } catch (e) {
    error.value = e.message || 'Registration failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <form v-if="!success" class="auth-form" @submit.prevent="submit">
      <h1>Create account</h1>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <label>
        Username
        <input v-model="username" type="text" autocomplete="username" required minlength="3" maxlength="64" />
      </label>

      <label>
        Email
        <input v-model="email" type="email" autocomplete="email" required />
      </label>

      <label>
        Password <small>(min. 12 characters)</small>
        <input v-model="password" type="password" autocomplete="new-password" required minlength="12" />
      </label>

      <label>
        Confirm password
        <input v-model="confirm" type="password" autocomplete="new-password" required />
      </label>

      <button type="submit" :disabled="loading">
        {{ loading ? 'Creating account…' : 'Create account' }}
      </button>

      <p><a href="/login">Already have an account? Sign in</a></p>
    </form>

    <div v-else class="auth-form">
      <h1>Check your email</h1>
      <p>We've sent a verification link to {{ email }}. Click it to activate your account.</p>
      <p><a href="/login">Back to sign in</a></p>
    </div>
  </div>
</template>
