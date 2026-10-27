/**
 * HTTP client for the Laravel API project.
 *
 * The two projects are deployed separately, so the base URL is configuration
 * (`VITE_API_URL`) rather than a same-origin assumption.
 *
 * This is the app's only data source. There is no local cache behind it: a
 * failed request is a failed operation, and the caller is expected to say so
 * rather than pretend the write landed.
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

  /** Status 0 is our marker for "the request never reached the server". */
  get isOffline() {
    return this.status === 0
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

/**
 * Called whenever a request proves the server is or is not reachable. The
 * connection store registers a handler, so ordinary traffic keeps the
 * indicator honest without a separate polling loop doing the same work.
 */
let onReachability = null

export function setReachabilityHandler(handler) {
  onReachability = handler
}

async function request(path, options = {}) {
  const token = readToken()

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        // FormData sets its own Content-Type, with the multipart boundary in
        // it. Declaring JSON here would make the upload unparseable.
        ...(options.body && !(options.body instanceof FormData)
          ? { 'Content-Type': 'application/json' }
          : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...options.headers,
      },
    })
  } catch (cause) {
    // Offline, DNS failure, CORS rejection — all indistinguishable here.
    onReachability?.(false)
    throw new ApiError(
      'Cannot reach the server. Check your connection and try again.',
      0,
      { cause: cause?.message ?? null },
    )
  }

  onReachability?.(true)

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

/**
 * Fetches a file the API serves behind the bearer token.
 *
 * A plain link cannot be used: the browser would send no Authorization header
 * and get a 401 back as a downloaded file. So the body is read as a blob and
 * handed to a temporary object URL instead.
 */
async function download(path, fallbackName) {
  const token = readToken()

  const response = await fetch(`${BASE_URL}${path}`, {
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  }).catch((cause) => {
    onReachability?.(false)
    throw new ApiError('Cannot reach the server. Check your connection and try again.', 0, {
      cause: cause?.message ?? null,
    })
  })

  onReachability?.(true)

  if (!response.ok) {
    if (response.status === 401 && token) onUnauthenticated?.()
    throw new ApiError(`Download failed with status ${response.status}`, response.status, null)
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)

  return { blob: await response.blob(), filename: match ? decodeURIComponent(match[1]) : fallbackName }
}

const query = (params) => {
  const search = new URLSearchParams(
    Object.entries(params ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== ''),
  ).toString()
  return search ? `?${search}` : ''
}

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
  users: (params) => request(`/users${query(params)}`),
  createUser: (payload) => json('POST')('/users', payload),
  updateUser: (id, payload) => json('PUT')(`/users/${id}`, payload),
  deleteUser: (id) => request(`/users/${id}`, { method: 'DELETE' }),

  // Inventory. `list` returns the collection; the write verbs return the saved
  // row under `data`, so a caller can update one record without a refetch.
  list: (table, params) => request(`/${table}${query(params)}`),
  create: (table, payload) => json('POST')(`/${table}`, payload),
  save: (table, id, payload) => json('PUT')(`/${table}/${id}`, payload),
  remove: (table, id) => request(`/${table}/${id}`, { method: 'DELETE' }),

  // Archive: soft-deleted records, put back or disposed of for good.
  archive: () => request('/archive'),
  restoreEquipment: (id) => json('POST')(`/archive/equipment/${id}/restore`),
  purgeEquipment: (id) => request(`/archive/equipment/${id}`, { method: 'DELETE' }),

  stats: () => request('/stats'),

  // Bulk import. `preview` is a dry run; `importEquipment` is the write. Both
  // take the same FormData, so the preview the operator approved is the thing
  // that gets sent.
  importProfiles: () => request('/import/profiles'),
  importTemplate: (profile, headersOnly = false) =>
    download(
      `/import/${profile}/template${headersOnly ? '?headersOnly=1' : ''}`,
      `ict-inventory-${profile}-sample.csv`,
    ),
  previewImport: (profile, form) => request(`/import/${profile}/preview`, { method: 'POST', body: form }),
  importEquipment: (profile, form) => request(`/import/${profile}`, { method: 'POST', body: form }),

  // Activity history. Paged and filtered server-side: unlike the inventory
  // collections this one grows without bound.
  history: (params) => request(`/history${query(params)}`),
  historyEntry: (id) => request(`/history/${id}`),
}
