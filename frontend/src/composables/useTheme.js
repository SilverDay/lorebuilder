/**
 * useTheme — reactive theme composable.
 *
 * Options: 'light', 'dark', 'system' (default).
 * Persisted to localStorage. System follows prefers-color-scheme.
 */
import { ref, watchEffect } from 'vue'

const STORAGE_KEY = 'lorebuilder-theme'
const VALID = ['light', 'dark', 'system']

const theme = ref(loadStored())

function loadStored() {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return VALID.includes(v) ? v : 'system'
  } catch {
    return 'system'
  }
}

function resolveEffective(pref) {
  if (pref === 'system') {
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
  }
  return pref
}

function apply(effective) {
  if (effective === 'light') {
    document.documentElement.setAttribute('data-theme', 'light')
  } else {
    document.documentElement.removeAttribute('data-theme')
  }
}

// React to changes
watchEffect(() => {
  const effective = resolveEffective(theme.value)
  apply(effective)
  try {
    localStorage.setItem(STORAGE_KEY, theme.value)
  } catch { /* quota exceeded, ignore */ }
})

// Listen for OS theme changes when mode is 'system'
if (typeof window !== 'undefined') {
  window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
    if (theme.value === 'system') {
      apply(resolveEffective('system'))
    }
  })
}

export function useTheme() {
  function cycle() {
    const order = ['system', 'dark', 'light']
    const idx = order.indexOf(theme.value)
    theme.value = order[(idx + 1) % order.length]
  }

  return {
    theme,
    cycle,
  }
}
