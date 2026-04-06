/**
 * LoreBuilder API Client
 *
 * Centralised fetch wrapper. All backend calls go through here.
 *
 * Responsibilities:
 * - Attach X-CSRF-Token header automatically on every state-changing request
 * - Handle 401 → redirect to /login
 * - Handle 429 → emit a rate-limit event with retry_after seconds
 * - Never store API keys in localStorage, sessionStorage, or Pinia
 * - Return { data, meta } on success; throw ApiError on failure
 */

import { useToastStore } from '@/stores/toast.js'

/** Machine-readable error codes returned by the backend. */
export const ErrorCode = Object.freeze({
  AUTH_REQUIRED:       'AUTH_REQUIRED',
  AUTH_INVALID:        'AUTH_INVALID',
  AUTH_TOTP_REQUIRED:  'AUTH_TOTP_REQUIRED',
  FORBIDDEN:           'FORBIDDEN',
  NOT_FOUND:           'NOT_FOUND',
  CONFLICT:            'CONFLICT',
  VALIDATION_ERROR:    'VALIDATION_ERROR',
  RATE_LIMITED:        'RATE_LIMITED',
  AI_KEY_MISSING:      'AI_KEY_MISSING',
  AI_KEY_INVALID:      'AI_KEY_INVALID',
  AI_BUDGET_EXCEEDED:  'AI_BUDGET_EXCEEDED',
  INTERNAL_ERROR:      'INTERNAL_ERROR',
})

export class ApiError extends Error {
  /**
   * @param {string} message       Human-readable message from backend
   * @param {string} code          Machine-readable error code (ErrorCode.*)
   * @param {number} status        HTTP status code
   * @param {string|null} field    Field name for VALIDATION_ERROR
   * @param {number|null} retryAfter  Seconds until retry (RATE_LIMITED)
   */
  constructor(message, code, status, field = null, retryAfter = null) {
    super(message)
    this.name       = 'ApiError'
    this.code       = code
    this.status     = status
    this.field      = field
    this.retryAfter = retryAfter
  }
}

// ─── CSRF Token Management ────────────────────────────────────────────────────

let _csrfToken = null

/**
 * Fetch a fresh CSRF token from GET /api/v1/auth/csrf.
 * Cached in module scope; refreshed on 401 (re-login).
 */
export async function fetchCsrfToken() {
  const res  = await fetch('/api/v1/auth/csrf', { credentials: 'same-origin' })
  const body = await res.json()
  _csrfToken = body?.data?.token ?? null
  return _csrfToken
}

/**
 * Return the cached CSRF token, fetching it first if not yet loaded.
 */
async function getCsrfToken() {
  if (!_csrfToken) {
    await fetchCsrfToken()
  }
  return _csrfToken
}

/** Invalidate the cached CSRF token (call after logout or 401). */
export function invalidateCsrfToken() {
  _csrfToken = null
}

// ─── Core Request Function ────────────────────────────────────────────────────

const STATE_CHANGING = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

/**
 * Make an authenticated API request.
 *
 * @param {string} path     URL path (e.g. '/api/v1/worlds')
 * @param {object} options  Fetch-like options (method, body, params)
 * @returns {Promise<{data: any, meta: any}>}
 * @throws {ApiError}
 */
export async function request(path, options = {}) {
  const method  = (options.method ?? 'GET').toUpperCase()
  const headers = { 'Content-Type': 'application/json', ...(options.headers ?? {}) }

  // Attach CSRF token to all state-changing requests
  if (STATE_CHANGING.has(method)) {
    const token = await getCsrfToken()
    if (token) {
      headers['X-CSRF-Token'] = token
    }
  }

  // Append query string params if provided
  let url = path
  if (options.params && Object.keys(options.params).length > 0) {
    const qs = new URLSearchParams(
      Object.fromEntries(
        Object.entries(options.params).filter(([, v]) => v !== null && v !== undefined)
      )
    )
    url = `${path}?${qs}`
  }

  const fetchOpts = {
    method,
    headers,
    credentials: 'same-origin',
  }

  if (options.body !== undefined) {
    fetchOpts.body = JSON.stringify(options.body)
  }

  let res
  try {
    res = await fetch(url, fetchOpts)
  } catch (networkErr) {
    throw new ApiError('Network error — check your connection.', 'NETWORK_ERROR', 0)
  }

  // Parse body (may be empty on 204)
  let body = null
  const contentType = res.headers.get('content-type') ?? ''
  if (res.status !== 204 && contentType.includes('application/json')) {
    try {
      body = await res.json()
    } catch {
      // Response was not valid JSON
    }
  }

  // ── 401 — session expired → redirect to login ─────────────────────────────
  if (res.status === 401) {
    invalidateCsrfToken()
    window.location.href = '/login?expired=1'
    throw new ApiError('Session expired. Please log in again.', ErrorCode.AUTH_REQUIRED, 401)
  }

  // ── 429 — rate limited → show toast with countdown ────────────────────────
  if (res.status === 429) {
    const retryAfter = body?.retry_after ?? null
    const toast = useToastStore()
    toast.addRateLimit(retryAfter)
    throw new ApiError(
      body?.error ?? 'Too many requests.',
      ErrorCode.RATE_LIMITED,
      429,
      null,
      retryAfter
    )
  }

  // ── Other errors ──────────────────────────────────────────────────────────
  if (!res.ok) {
    const message    = body?.error     ?? `HTTP ${res.status}`
    const code       = body?.code      ?? 'UNKNOWN_ERROR'
    const field      = body?.field     ?? null
    const retryAfter = body?.retry_after ?? null
    throw new ApiError(message, code, res.status, field, retryAfter)
  }

  return {
    data: body?.data ?? null,
    meta: body?.meta ?? null,
  }
}

// ─── Typed Resource Helpers ───────────────────────────────────────────────────

export const api = {
  get:    (path, params = {})        => request(path, { method: 'GET', params }),
  post:   (path, body = {})          => request(path, { method: 'POST',   body }),
  put:    (path, body = {})          => request(path, { method: 'PUT',    body }),
  patch:  (path, body = {})          => request(path, { method: 'PATCH',  body }),
  delete: (path)                     => request(path, { method: 'DELETE' }),
}
