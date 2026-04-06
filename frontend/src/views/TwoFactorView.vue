<script setup>
import { ref, onMounted } from 'vue'
import QRCode from 'qrcode'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

// ── State ─────────────────────────────────────────────────────────────────────
const enabled     = ref(false)
const loading     = ref(true)
const error       = ref('')

// Setup flow
const step        = ref('idle')   // idle | setup | confirm | done
const qrDataUrl   = ref('')
const secret      = ref('')
const confirmCode = ref('')
const setupSaving = ref(false)
const setupError  = ref('')

// Disable flow
const showDisable    = ref(false)
const disablePass    = ref('')
const disableCode    = ref('')
const disableSaving  = ref(false)
const disableError   = ref('')

// ── Load current state ────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    // fetchMe populates auth.user; re-fetch to get fresh totp_enabled
    await auth.fetchMe()
    enabled.value = !!auth.user?.totp_enabled
  } catch (e) {
    error.value = e.message || 'Failed to load account status.'
  } finally {
    loading.value = false
  }
})

// ── Enable flow ───────────────────────────────────────────────────────────────
async function startSetup() {
  setupError.value = ''
  setupSaving.value = true
  try {
    const { data } = await api.post('/api/v1/auth/totp/setup', {})
    secret.value   = data.secret
    qrDataUrl.value = await QRCode.toDataURL(data.uri, { width: 220, margin: 2 })
    step.value     = 'setup'
  } catch (e) {
    setupError.value = e.message || 'Failed to start setup.'
  } finally {
    setupSaving.value = false
  }
}

async function confirmSetup() {
  if (!confirmCode.value.trim()) return
  setupError.value  = ''
  setupSaving.value = true
  try {
    await api.post('/api/v1/auth/totp/confirm', { code: confirmCode.value.trim() })
    enabled.value    = true
    step.value       = 'done'
    secret.value     = ''   // clear secret from memory
    qrDataUrl.value  = ''
    confirmCode.value = ''
    await auth.fetchMe()
  } catch (e) {
    setupError.value = e.message || 'Invalid code. Please try again.'
  } finally {
    setupSaving.value = false
  }
}

function cancelSetup() {
  step.value        = 'idle'
  secret.value      = ''
  qrDataUrl.value   = ''
  confirmCode.value = ''
  setupError.value  = ''
}

// ── Disable flow ──────────────────────────────────────────────────────────────
async function disableTotp() {
  disableError.value  = ''
  disableSaving.value = true
  try {
    await api.delete('/api/v1/auth/totp', {
      password: disablePass.value,
      code:     disableCode.value.trim(),
    })
    enabled.value   = false
    showDisable.value = false
    disablePass.value = ''
    disableCode.value = ''
    await auth.fetchMe()
  } catch (e) {
    disableError.value = e.message || 'Incorrect password or code.'
  } finally {
    disableSaving.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-form" style="max-width:460px">
      <h1>Two-factor authentication</h1>

      <p v-if="loading" class="muted">Loading…</p>
      <p v-else-if="error" class="form-error" role="alert">{{ error }}</p>

      <template v-else>

        <!-- ── Status banner ── -->
        <div class="totp-status" :class="enabled ? 'totp-status--on' : 'totp-status--off'">
          <span class="totp-status__dot"></span>
          <span>{{ enabled ? '2FA is enabled' : '2FA is not enabled' }}</span>
        </div>

        <!-- ── Enabled: show disable option ── -->
        <template v-if="enabled && step !== 'done'">
          <p class="muted">
            Your account is protected with a time-based one-time password (TOTP).
            You will be asked for a code every time you sign in.
          </p>

          <template v-if="!showDisable">
            <button class="btn btn-danger" @click="showDisable = true">
              Disable two-factor authentication
            </button>
          </template>

          <template v-else>
            <p class="muted" style="margin-top:.5rem">
              Enter your current password and authenticator code to disable 2FA.
            </p>
            <form @submit.prevent="disableTotp">
              <label>
                Current password
                <input v-model="disablePass" type="password" autocomplete="current-password" required />
              </label>
              <label>
                Authenticator code
                <input v-model="disableCode" type="text" inputmode="numeric"
                       pattern="[0-9]{6}" maxlength="6" placeholder="6-digit code"
                       autocomplete="one-time-code" required />
              </label>
              <p v-if="disableError" class="form-error" role="alert">{{ disableError }}</p>
              <div class="form-actions">
                <button type="button" class="btn btn-ghost" @click="showDisable = false">Cancel</button>
                <button type="submit" class="btn btn-danger" :disabled="disableSaving">
                  {{ disableSaving ? 'Disabling…' : 'Confirm disable' }}
                </button>
              </div>
            </form>
          </template>
        </template>

        <!-- ── Not enabled: setup flow ── -->
        <template v-else-if="!enabled">

          <!-- idle -->
          <template v-if="step === 'idle'">
            <p class="muted">
              Add an extra layer of security. You'll need an authenticator app
              (such as Google Authenticator, Aegis, or 1Password) on your phone.
            </p>
            <p v-if="setupError" class="form-error" role="alert">{{ setupError }}</p>
            <button class="btn btn-primary" :disabled="setupSaving" @click="startSetup">
              {{ setupSaving ? 'Generating…' : 'Set up two-factor authentication' }}
            </button>
          </template>

          <!-- show QR code -->
          <template v-else-if="step === 'setup'">
            <p class="muted">
              Scan this QR code with your authenticator app, then enter the
              6-digit code it shows to confirm.
            </p>
            <div class="totp-qr">
              <img :src="qrDataUrl" alt="TOTP QR code" width="220" height="220" />
            </div>
            <details class="totp-manual">
              <summary>Can't scan? Enter the key manually</summary>
              <code class="totp-secret">{{ secret }}</code>
            </details>
            <form @submit.prevent="confirmSetup" style="margin-top:1rem">
              <label>
                Confirmation code
                <input v-model="confirmCode" type="text" inputmode="numeric"
                       pattern="[0-9]{6}" maxlength="6" placeholder="6-digit code"
                       autocomplete="one-time-code" required autofocus />
              </label>
              <p v-if="setupError" class="form-error" role="alert">{{ setupError }}</p>
              <div class="form-actions" style="margin-top:.5rem">
                <button type="button" class="btn btn-ghost" @click="cancelSetup">Cancel</button>
                <button type="submit" class="btn btn-primary" :disabled="setupSaving">
                  {{ setupSaving ? 'Verifying…' : 'Activate 2FA' }}
                </button>
              </div>
            </form>
          </template>

        </template>

        <!-- ── Success ── -->
        <template v-if="step === 'done'">
          <p class="form-success">
            Two-factor authentication is now active. You will be asked for a
            code on every sign-in.
          </p>
        </template>

      </template>

      <p style="margin-top:1rem">
        <RouterLink to="/worlds">← Back to my worlds</RouterLink>
      </p>
    </div>
  </div>
</template>
