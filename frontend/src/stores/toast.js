/**
 * Toast Store — UI notifications
 *
 * Used by api/client.js to surface rate-limit countdowns and generic errors.
 */

import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useToastStore = defineStore('toast', () => {
  const toasts = ref([])
  let   nextId = 1

  function add(message, type = 'info', duration = 4000) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    if (duration > 0) {
      setTimeout(() => remove(id), duration)
    }
    return id
  }

  function addRateLimit(retryAfter = null) {
    const msg = retryAfter
      ? `Rate limited. Please wait ${retryAfter} second${retryAfter === 1 ? '' : 's'} before retrying.`
      : 'Rate limited. Please slow down.'
    return add(msg, 'warning', (retryAfter ?? 5) * 1000 + 1000)
  }

  function remove(id) {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }

  return { toasts, add, addRateLimit, remove }
})
