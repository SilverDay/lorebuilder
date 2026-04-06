<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'

const route   = useRoute()
const router  = useRouter()
const wid     = route.params.wid
const eid     = route.params.eid

const name    = ref('')
const type    = ref('Character')
const status  = ref('draft')
const summary = ref('')
const lore    = ref('')
const error   = ref('')
const loading = ref(false)

const TYPES = ['Character','Location','Event','Faction','Artefact','Creature','Concept','StoryArc','Timeline','Race']

onMounted(async () => {
  const { data } = await api.get(`/api/v1/worlds/${wid}/entities/${eid}`)
  name.value    = data.name          ?? ''
  type.value    = data.type          ?? 'Character'
  status.value  = data.status        ?? 'draft'
  summary.value = data.short_summary ?? ''
  lore.value    = data.lore_body     ?? ''
})

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    await api.patch(`/api/v1/worlds/${wid}/entities/${eid}`, {
      name:          name.value.trim(),
      type:          type.value,
      status:        status.value,
      short_summary: summary.value.trim() || null,
      lore_body:     lore.value.trim()    || null,
    })
    router.push(`/worlds/${wid}/entities/${eid}`)
  } catch (e) {
    error.value = e.message || 'Failed to save changes.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page page-narrow">
    <h1>Edit entity</h1>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <form @submit.prevent="submit">
      <label>
        Name
        <input v-model="name" type="text" required maxlength="255" />
      </label>

      <label>
        Type
        <select v-model="type">
          <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
        </select>
      </label>

      <label>
        Status
        <select v-model="status">
          <option value="draft">Draft</option>
          <option value="published">Published</option>
          <option value="archived">Archived</option>
        </select>
      </label>

      <label>
        Short summary
        <input v-model="summary" type="text" maxlength="512" />
      </label>

      <label>
        Lore body <small>(Markdown)</small>
        <textarea v-model="lore" rows="8"></textarea>
      </label>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? 'Saving…' : 'Save changes' }}
        </button>
        <RouterLink :to="`/worlds/${wid}/entities/${eid}`" class="btn btn-ghost">Cancel</RouterLink>
      </div>
    </form>
  </div>
</template>
