<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client.js'

const router  = useRouter()
const name    = ref('')
const slug    = ref('')
const genre   = ref('')
const tone    = ref('')
const desc    = ref('')
const error   = ref('')
const loading = ref(false)

// Auto-generate slug from name
function onNameInput() {
  slug.value = name.value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64)
}

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    const { data } = await api.post('/api/v1/worlds', {
      name:        name.value.trim(),
      slug:        slug.value.trim(),
      description: desc.value.trim() || null,
      genre:       genre.value.trim() || null,
      tone:        tone.value.trim()  || null,
    })
    router.push(`/worlds/${data.id}`)
  } catch (e) {
    error.value = e.message || 'Failed to create world.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page page-narrow">
    <h1>Create a new world</h1>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <form @submit.prevent="submit">
      <label>
        World name
        <input v-model="name" type="text" required maxlength="255" @input="onNameInput" />
      </label>

      <label>
        URL slug
        <input v-model="slug" type="text" required maxlength="128"
               pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" />
      </label>

      <label>
        Description
        <textarea v-model="desc" rows="3" maxlength="1000"></textarea>
      </label>

      <label>
        Genre <small>(e.g. dark fantasy, sci-fi)</small>
        <input v-model="genre" type="text" maxlength="128" />
      </label>

      <label>
        Tone <small>(e.g. gritty, heroic)</small>
        <input v-model="tone" type="text" maxlength="128" />
      </label>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? 'Creating…' : 'Create world' }}
        </button>
        <RouterLink to="/worlds" class="btn btn-ghost">Cancel</RouterLink>
      </div>
    </form>
  </div>
</template>
