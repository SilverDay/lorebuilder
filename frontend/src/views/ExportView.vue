<script setup>
import { ref }      from 'vue'
import { useRoute } from 'vue-router'
import { api }      from '@/api/client.js'

const route = useRoute()
const wid   = route.params.wid

const format      = ref('json')
const exporting   = ref(false)
const exportError = ref('')

const importing   = ref(false)
const importFile  = ref(null)
const importMsg   = ref('')
const importError = ref('')
const importStats = ref(null)

async function doExport() {
  exporting.value   = true
  exportError.value = ''
  try {
    // GET is CSRF-exempt; use fetch directly to get a blob (api wrapper always parses JSON)
    const res = await fetch(`/api/v1/worlds/${wid}/export?format=${format.value}`, {
      credentials: 'same-origin',
    })
    if (!res.ok) {
      const json = await res.json().catch(() => ({}))
      throw new Error(json.error || `Export failed (${res.status})`)
    }
    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    const ext  = format.value === 'json' ? 'json' : 'md'
    a.href     = url
    a.download = `world-export.${ext}`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    exportError.value = e.message || 'Export failed.'
  } finally {
    exporting.value = false
  }
}

function onFileChange(e) {
  importFile.value = e.target.files?.[0] ?? null
}

async function doImport() {
  if (!importFile.value) return
  importing.value   = true
  importError.value = ''
  importMsg.value   = ''
  importStats.value = null

  try {
    const text = await importFile.value.text()
    const { data } = await api.post(`/api/v1/worlds/${wid}/import`, JSON.parse(text))
    importMsg.value   = 'Import complete.'
    importStats.value = data
  } catch (e) {
    importError.value = e.message || 'Import failed.'
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Export / Import</h1>
      <RouterLink :to="`/worlds/${wid}`" class="btn btn-ghost">← Dashboard</RouterLink>
    </header>

    <!-- Export section -->
    <section class="settings-section">
      <h2>Export World</h2>
      <p class="muted">Downloads a complete snapshot of all entities, relationships, timelines, arcs and notes.</p>

      <form class="settings-form" @submit.prevent="doExport">
        <label>
          Format
          <select v-model="format">
            <option value="json">JSON (LoreBuilder format — can be re-imported)</option>
            <option value="markdown">Markdown (human-readable, one section per entity)</option>
          </select>
        </label>
        <p v-if="exportError" class="form-error" role="alert">{{ exportError }}</p>
        <button type="submit" class="btn btn-primary" :disabled="exporting">
          {{ exporting ? 'Preparing…' : 'Download Export' }}
        </button>
      </form>
    </section>

    <!-- Import section -->
    <section class="settings-section">
      <h2>Import from JSON</h2>
      <p class="muted">
        Imports entities, relationships, timelines, arcs and notes from a LoreBuilder JSON export.
        Existing data is preserved — the import always adds new records.
      </p>

      <form class="settings-form" @submit.prevent="doImport">
        <label>
          JSON export file
          <input type="file" accept=".json,application/json" @change="onFileChange" :disabled="importing" />
        </label>
        <p v-if="importError" class="form-error" role="alert">{{ importError }}</p>
        <p v-if="importMsg"   class="form-success" role="status">{{ importMsg }}</p>

        <div v-if="importStats" class="import-stats">
          <strong>Imported:</strong>
          {{ importStats.entities }} entities ·
          {{ importStats.relationships }} relationships ·
          {{ importStats.notes }} notes ·
          {{ importStats.timelines }} timelines ·
          {{ importStats.arcs }} arcs ·
          {{ importStats.tags }} tags
        </div>

        <button
          type="submit"
          class="btn btn-secondary"
          :disabled="importing || !importFile"
        >
          {{ importing ? 'Importing…' : 'Import' }}
        </button>
      </form>
    </section>
  </div>
</template>
