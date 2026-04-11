/**
 * Story Store — manages stories with auto-save and conflict detection.
 *
 * Security: No API keys or sensitive data stored here.
 * Only story content, metadata, and UI state.
 */

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client.js'

export const useStoryStore = defineStore('story', () => {
  const stories      = ref([])
  const currentStory = ref(null)
  const loading      = ref(false)
  const saving       = ref(false)
  const error        = ref('')
  const dirty        = ref(false)
  const lastSavedAt  = ref(null)

  // Auto-save timer
  let autoSaveTimer = null

  async function fetchStories(wid, params = {}) {
    loading.value = true
    error.value   = ''
    try {
      const { data, meta } = await api.get(`/api/v1/worlds/${wid}/stories`, params)
      stories.value = data ?? []
      return { data: stories.value, meta }
    } catch (e) {
      error.value = e.message || 'Failed to load stories.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchStory(wid, sid) {
    loading.value = true
    error.value   = ''
    try {
      const { data } = await api.get(`/api/v1/worlds/${wid}/stories/${sid}`)
      currentStory.value = data
      dirty.value        = false
      lastSavedAt.value  = data?.updated_at ?? null
      return data
    } catch (e) {
      error.value = e.message || 'Failed to load story.'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function createStory(wid, payload) {
    saving.value = true
    error.value  = ''
    try {
      const { data } = await api.post(`/api/v1/worlds/${wid}/stories`, payload)
      return data
    } catch (e) {
      error.value = e.message || 'Failed to create story.'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateStory(wid, sid, payload) {
    saving.value = true
    error.value  = ''
    try {
      // Include updated_at for conflict detection
      if (currentStory.value?.updated_at) {
        payload.updated_at = currentStory.value.updated_at
      }
      const { data } = await api.patch(`/api/v1/worlds/${wid}/stories/${sid}`, payload)
      // Update local state with server response
      if (currentStory.value && currentStory.value.id == sid) {
        Object.assign(currentStory.value, data)
      }
      dirty.value       = false
      lastSavedAt.value = data?.updated_at ?? new Date().toISOString()
      return data
    } catch (e) {
      if (e.status === 409) {
        error.value = 'Conflict: this story was modified elsewhere. Please reload.'
      } else {
        error.value = e.message || 'Failed to save story.'
      }
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteStory(wid, sid) {
    error.value = ''
    try {
      await api.delete(`/api/v1/worlds/${wid}/stories/${sid}`)
      stories.value = stories.value.filter(s => s.id != sid)
      if (currentStory.value?.id == sid) {
        currentStory.value = null
      }
    } catch (e) {
      error.value = e.message || 'Failed to delete story.'
      throw e
    }
  }

  /**
   * Mark content as dirty and (re)start the auto-save timer.
   */
  function markDirty() {
    dirty.value = true
    restartAutoSave()
  }

  /**
   * Perform an auto-save of the current story's content.
   */
  async function autoSave(wid, sid) {
    if (!dirty.value || saving.value || !currentStory.value) return
    const content = currentStory.value.content
    if (content == null) return
    try {
      await updateStory(wid, sid, { content })
    } catch {
      // Error already captured in updateStory
    }
  }

  function startAutoSave(wid, sid) {
    stopAutoSave()
    autoSaveTimer = setInterval(() => autoSave(wid, sid), 30000)
  }

  function restartAutoSave() {
    // No-op — the interval keeps ticking. Dirty flag controls whether save fires.
  }

  function stopAutoSave() {
    if (autoSaveTimer) {
      clearInterval(autoSaveTimer)
      autoSaveTimer = null
    }
  }

  function clear() {
    stopAutoSave()
    stories.value      = []
    currentStory.value = null
    loading.value      = false
    saving.value       = false
    error.value        = ''
    dirty.value        = false
    lastSavedAt.value  = null
  }

  return {
    stories,
    currentStory,
    loading,
    saving,
    error,
    dirty,
    lastSavedAt,
    fetchStories,
    fetchStory,
    createStory,
    updateStory,
    deleteStory,
    markDirty,
    startAutoSave,
    stopAutoSave,
    clear,
  }
})
