<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const router = useRouter()

const current  = ref('')
const password = ref('')
const confirm  = ref('')
const loading  = ref(false)
const done     = ref(false)
const error    = ref('')

async function submit() {
  if (password.value !== confirm.value) {
    error.value = 'New passwords do not match.'
    return
  }
  loading.value = true
  error.value   = ''
  try {
    await api.post('/api/v1/auth/password/change', {
      current_password: current.value,
      new_password:     password.value,
    })
    done.value = true
  } catch (e) {
    error.value = e.message || 'Failed to change password.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-form">
      <h1>Change password</h1>

      <template v-if="done">
        <p class="form-success">Password updated successfully.</p>
        <button class="btn btn-ghost" @click="router.back()">← Go back</button>
      </template>

      <template v-else>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <form @submit.prevent="submit">
          <label>
            Current password
            <input v-model="current" type="password" autocomplete="current-password" required />
          </label>
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
            {{ loading ? 'Saving…' : 'Update password' }}
          </button>
        </form>
      </template>

      <p><RouterLink to="/account/settings">← Back to account</RouterLink></p>
    </div>
  </div>
</template>
