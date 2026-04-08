<script setup>
/**
 * WorldSettingsView — edit world metadata.
 *
 * Loads current world via GET /api/v1/worlds/:wid and patches via
 * PATCH /api/v1/worlds/:wid (Guard: admin).
 */
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useWorldStore } from '@/stores/world.js'
import { api } from '@/api/client.js'

const route  = useRoute()
const worlds = useWorldStore()
const wid    = route.params.wid

const loading  = ref(true)
const saving   = ref(false)
const error    = ref('')
const success  = ref('')

const name             = ref('')
const slug             = ref('')
const description      = ref('')
const genre            = ref('')
const tone             = ref('')
const eraSystem        = ref('')
const contentWarnings  = ref('')
const status           = ref('active')

onMounted(async () => {
  loading.value = true
  error.value   = ''
  try {
    if (!worlds.currentWorld || worlds.currentWorldId !== Number(wid)) {
      await worlds.loadWorld(wid)
    }
    const { data } = await api.get(`/api/v1/worlds/${wid}`)
    name.value            = data.name ?? ''
    slug.value            = data.slug ?? ''
    description.value     = data.description ?? ''
    genre.value           = data.genre ?? ''
    tone.value            = data.tone ?? ''
    eraSystem.value       = data.era_system ?? ''
    contentWarnings.value = data.content_warnings ?? ''
    status.value          = data.status ?? 'active'
  } catch (e) {
    error.value = e.message || 'Failed to load world settings.'
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value  = true
  error.value   = ''
  success.value = ''
  try {
    await api.patch(`/api/v1/worlds/${wid}`, {
      name:             name.value.trim(),
      description:      description.value.trim() || null,
      genre:            genre.value.trim() || null,
      tone:             tone.value.trim() || null,
      era_system:       eraSystem.value.trim() || null,
      content_warnings: contentWarnings.value.trim() || null,
      status:           status.value,
    })
    // Update world store so the dashboard reflects the change
    if (worlds.currentWorld) {
      worlds.currentWorld.name   = name.value.trim()
      worlds.currentWorld.status = status.value
    }
    success.value = 'World settings saved.'
  } catch (e) {
    error.value = e.message || 'Failed to save world settings.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>World Settings</h1>
    </header>

    <p v-if="loading" class="loading">Loading…</p>
    <p v-else-if="error && loading" class="form-error" role="alert">{{ error }}</p>

    <template v-else>
      <form class="settings-form" @submit.prevent="save">
        <section class="settings-section">
          <h2>General</h2>

          <label>
            World name
            <input v-model="name" type="text" required maxlength="255" :disabled="saving" />
          </label>

          <label>
            Slug <small>(read-only)</small>
            <input :value="slug" type="text" disabled />
          </label>

          <label>
            Description
            <textarea v-model="description" rows="3" maxlength="1000" :disabled="saving"></textarea>
          </label>
        </section>

        <section class="settings-section">
          <h2>Narrative</h2>

          <label>
            Genre <small>(e.g. dark fantasy, sci-fi, cyberpunk)</small>
            <input v-model="genre" type="text" maxlength="128" :disabled="saving" />
          </label>

          <label>
            Tone <small>(e.g. gritty, heroic, whimsical)</small>
            <input v-model="tone" type="text" maxlength="128" :disabled="saving" />
          </label>

          <label>
            Era system <small>(e.g. "Ages", "Epochs", "Centuries")</small>
            <input v-model="eraSystem" type="text" maxlength="128" :disabled="saving" />
          </label>

          <label>
            Content warnings <small>(comma-separated)</small>
            <input v-model="contentWarnings" type="text" maxlength="500" :disabled="saving" />
          </label>
        </section>

        <section class="settings-section">
          <h2>Status</h2>

          <label>
            World status
            <select v-model="status" :disabled="saving">
              <option value="active">Active</option>
              <option value="archived">Archived</option>
            </select>
          </label>
        </section>

        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <p v-if="success" class="form-success" role="status">{{ success }}</p>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Saving…' : 'Save Settings' }}
          </button>
        </div>
      </form>
    </template>
  </div>
</template>
