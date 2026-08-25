/**
 * Thin HTTP client for the Laravel API project.
 *
 * The two projects are deployed separately, so the base URL is configuration
 * (`VITE_API_URL`) rather than a same-origin assumption.
 */
const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/$/, '')

export const TOKEN_STORAGE_KEY = 'ict.auth.token'

export class ApiError extends Error {
  constructor(message, status, body) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }

  /** 5xx and network failures are worth retrying; 4xx needs a human. */
  get isTransient() {
    return this.status === 0 || this.status >= 500
  }

  get isUnauthenticated() {
    return this.status === 401
  }

  /** Laravel validation errors: { field: [messages] }. */
  get validationErrors() {
    return this.body && typeof this.body === 'object' ? (this.body.errors ?? null) : null
  }

  firstValidationError() {
    const errors = this.validationErrors
    if (!errors) return null
    const first = Object.values(errors)[0]
    return Array.isArray(first) ? first[0] : first
  }
}

function readToken() {
  try {
    return localStorage.getItem(TOKEN_STORAGE_KEY)
  } catch {
    return null
  }
}

/**
 * Called when the API rejects our token. The auth store registers a handler so
 * a revoked or expired token drops the user back to the sign-in screen instead
 * of leaving the app in a half-broken state.
 */
let onUnauthenticated = null

export function setUnauthenticatedHandler(handler) {
  onUnauthenticated = handler
}

async function request(path, options = {}) {
  const token = readToken()

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...options.headers,
      },
    })
  } catch (cause) {
    // Offline, DNS failure, CORS rejection — all indistinguishable here.
    throw new ApiError(cause?.message ?? 'Network request failed', 0, null)
  }

  const text = await response.text()
  let body = null
  if (text) {
    try {
      body = JSON.parse(text)
    } catch {
      body = text
    }
  }

  if (!response.ok) {
    const message =
      (body && typeof body === 'object' && (body.error || body.message)) ||
      `Request failed with status ${response.status}`
    const error = new ApiError(message, response.status, body)

    if (error.isUnauthenticated && token) onUnauthenticated?.()

    throw error
  }

  return body
}

const json = (method) => (path, payload) =>
  request(path, { method, body: JSON.stringify(payload ?? {}) })

export const api = {
  health: () => request('/health'),

  // Auth
  requestCode: (email) => json('POST')('/auth/request-code', { email }),
  verifyCode: (email, code, deviceName) =>
    json('POST')('/auth/verify-code', { email, code, deviceName }),
  me: () => request('/auth/me'),
  updateProfile: (name) => json('PUT')('/auth/profile', { name }),
  logout: () => json('POST')('/auth/logout'),

  // Accounts (admin only)
  users: (params = {}) => {
    const query = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    ).toString()
    return request(`/users${query ? `?${query}` : ''}`)
  },
  createUser: (payload) => json('POST')('/users', payload),
  updateUser: (id, payload) => json('PUT')(`/users/${id}`, payload),
  deleteUser: (id) => request(`/users/${id}`, { method: 'DELETE' }),

  // Sync
  push: (payload) => json('POST')('/sync/push', payload),
  pull: (lastSyncedAt) =>
    request(
      lastSyncedAt
        ? `/sync/pull?last_synced_at=${encodeURIComponent(lastSyncedAt)}`
        : '/sync/pull',
    ),

  stats: () => request('/stats'),
}
