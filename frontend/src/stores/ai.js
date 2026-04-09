/**
 * AI Store — wraps AI assist calls and caches session history.
 *
 * Security: API keys are NEVER stored here. The store only holds
 * response text, token counts, and session metadata returned by the server.
 */

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client.js'

export const useAiStore = defineStore('ai', () => {
  const loading   = ref(false)
  const lastResult = ref(null)   // { text, session_id, prompt_tokens, completion_tokens, total_tokens, model }
  const error     = ref('')

  /**
   * Send an AI assist request.
   * @param {string|number} wid       World ID
   * @param {string}        mode      One of: entity_assist, arc_synthesiser, world_overview, custom
   * @param {string}        prompt    User's request text
   * @param {number|null}   entityId  Entity in focus, or null for world-level
   * @returns {object}  Response data (also stored in lastResult)
   */
  async function assist(wid, mode, prompt, entityId = null) {
    loading.value = true
    error.value   = ''
    try {
      const body = { mode, user_prompt: prompt }
      if (entityId) body.entity_id = entityId
      const { data } = await api.post(`/api/v1/worlds/${wid}/ai/assist`, body)
      lastResult.value = data
      return data
    } catch (e) {
      error.value = e.message || 'AI request failed.'
      throw e
    } finally {
      loading.value = false
    }
  }

  /**
   * Send a world-level consistency check request.
   */
  async function consistencyCheck(wid, prompt = '') {
    loading.value = true
    error.value   = ''
    try {
      const body = prompt ? { user_prompt: prompt } : {}
      const { data } = await api.post(`/api/v1/worlds/${wid}/ai/consistency-check`, body)
      lastResult.value = data
      return data
    } catch (e) {
      error.value = e.message || 'Consistency check failed.'
      throw e
    } finally {
      loading.value = false
    }
  }

  /**
   * Preview the assembled prompt without calling the AI.
   */
  async function previewPrompt(wid, mode, prompt, entityId = null) {
    loading.value = true
    error.value   = ''
    try {
      const body = { mode, user_prompt: prompt }
      if (entityId) body.entity_id = entityId
      const { data } = await api.post(`/api/v1/worlds/${wid}/ai/preview-prompt`, body)
      return data
    } catch (e) {
      error.value = e.message || 'Preview failed.'
      throw e
    } finally {
      loading.value = false
    }
  }

  function clearResult() {
    lastResult.value = null
    error.value      = ''
  }

  return { loading, lastResult, error, assist, consistencyCheck, previewPrompt, clearResult }
})
