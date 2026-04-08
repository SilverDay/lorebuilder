<script setup>
/**
 * AccountSettingsView — user profile, email change, security links, account deletion.
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { api } from '@/api/client.js'

const router  = useRouter()
const auth    = useAuthStore()

const loading       = ref(true)
const error         = ref('')
const profile       = ref(null)

// Display name edit
const displayName   = ref('')
const savingName    = ref(false)
const nameSuccess   = ref('')
const nameError     = ref('')

// Email change
const newEmail      = ref('')
const emailPassword = ref('')
const savingEmail   = ref(false)
const emailSuccess  = ref('')
const emailError    = ref('')

// Account deletion
const deletePassword = ref('')
const deleting       = ref(false)
const deleteError    = ref('')
const showDeleteConfirm = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get('/api/v1/users/me')
    profile.value     = data
    displayName.value = data.display_name ?? ''
  } catch (e) {
    error.value = e.message || 'Failed to load profile.'
  } finally {
    loading.value = false
  }
})

async function saveName() {
  savingName.value  = true
  nameError.value   = ''
  nameSuccess.value = ''
  try {
    await api.patch('/api/v1/users/me', { display_name: displayName.value.trim() })
    nameSuccess.value = 'Display name updated.'
    if (auth.user) auth.user.display_name = displayName.value.trim()
  } catch (e) {
    nameError.value = e.message || 'Failed to update display name.'
  } finally {
    savingName.value = false
  }
}

async function requestEmailChange() {
  savingEmail.value  = true
  emailError.value   = ''
  emailSuccess.value = ''
  try {
    const { data } = await api.post('/api/v1/users/me/email', {
      new_email: newEmail.value.trim(),
      password:  emailPassword.value,
    })
    emailSuccess.value = data.message || 'Verification email sent.'
    newEmail.value      = ''
    emailPassword.value = ''
  } catch (e) {
    emailError.value = e.message || 'Failed to request email change.'
  } finally {
    savingEmail.value = false
  }
}

async function deleteAccount() {
  deleting.value   = true
  deleteError.value = ''
  try {
    await api.delete('/api/v1/users/me', { password: deletePassword.value })
    router.push('/login')
  } catch (e) {
    deleteError.value = e.message || 'Failed to delete account.'
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="page page-narrow">
    <header class="page-header">
      <h1>Account Settings</h1>
      <button class="btn btn-ghost" @click="router.back()">← Back</button>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-else-if="profile">
      <!-- Profile Section -->
      <section class="settings-section">
        <h2>Profile</h2>
        <form class="settings-form" @submit.prevent="saveName">
          <label>
            Username <small>(read-only)</small>
            <input :value="profile.username" type="text" disabled />
          </label>

          <label>
            Display name
            <input v-model="displayName" type="text" required maxlength="128" :disabled="savingName" />
          </label>

          <p v-if="nameError" class="form-error" role="alert">{{ nameError }}</p>
          <p v-if="nameSuccess" class="form-success" role="status">{{ nameSuccess }}</p>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary" :disabled="savingName">
              {{ savingName ? 'Saving…' : 'Update Name' }}
            </button>
          </div>
        </form>
      </section>

      <!-- Email Section -->
      <section class="settings-section">
        <h2>Email</h2>
        <p class="settings-info">
          Current email: <strong>{{ profile.email }}</strong>
          <span v-if="profile.email_verified" class="badge badge-success">Verified</span>
          <span v-else class="badge badge-archived">Unverified</span>
        </p>

        <form class="settings-form" @submit.prevent="requestEmailChange">
          <label>
            New email address
            <input v-model="newEmail" type="email" maxlength="254" :disabled="savingEmail" />
          </label>

          <label>
            Current password <small>(required to change email)</small>
            <input v-model="emailPassword" type="password" autocomplete="current-password" :disabled="savingEmail" />
          </label>

          <p v-if="emailError" class="form-error" role="alert">{{ emailError }}</p>
          <p v-if="emailSuccess" class="form-success" role="status">{{ emailSuccess }}</p>

          <div class="form-actions">
            <button
              type="submit"
              class="btn btn-primary"
              :disabled="savingEmail || !newEmail.trim() || !emailPassword"
            >
              {{ savingEmail ? 'Sending…' : 'Change Email' }}
            </button>
          </div>
        </form>
      </section>

      <!-- Security Section -->
      <section class="settings-section">
        <h2>Security</h2>
        <div class="settings-links">
          <RouterLink to="/account/password" class="btn btn-secondary">Change Password</RouterLink>
          <RouterLink to="/account/2fa" class="btn btn-secondary">
            {{ profile.totp_enabled ? 'Manage 2FA' : 'Enable 2FA' }}
          </RouterLink>
        </div>
      </section>

      <!-- Danger Zone -->
      <section class="settings-section settings-danger">
        <h2>Danger Zone</h2>
        <p class="settings-info">
          Deleting your account removes you from all worlds and cannot be undone.
        </p>

        <button
          v-if="!showDeleteConfirm"
          class="btn btn-danger"
          @click="showDeleteConfirm = true"
        >
          Delete My Account
        </button>

        <form v-else class="settings-form" @submit.prevent="deleteAccount">
          <label>
            Confirm with your password
            <input
              v-model="deletePassword"
              type="password"
              autocomplete="current-password"
              :disabled="deleting"
            />
          </label>

          <p v-if="deleteError" class="form-error" role="alert">{{ deleteError }}</p>

          <div class="form-actions">
            <button
              type="submit"
              class="btn btn-danger"
              :disabled="deleting || !deletePassword"
            >
              {{ deleting ? 'Deleting…' : 'Permanently Delete Account' }}
            </button>
            <button type="button" class="btn btn-ghost" @click="showDeleteConfirm = false">
              Cancel
            </button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<style scoped>
.settings-links {
  display: flex;
  gap: .75rem;
  flex-wrap: wrap;
}

.settings-info {
  font-size: .875rem;
  color: var(--color-muted);
  margin-bottom: .75rem;
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-wrap: wrap;
}

.settings-danger {
  border: 1px solid rgba(248, 81, 73, .3);
  border-radius: var(--radius-lg);
  padding: 1.25rem;
}

.settings-danger h2 {
  color: var(--color-danger);
}
</style>
