import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, ApiError, TOKEN_STORAGE_KEY, setUnauthenticatedHandler } from '@/api/client'

/**
 * Passwordless authentication.
 *
 * Sign-in is a two step flow: request a one-time code by email, then exchange
 * the code for a bearer token. The token is persisted so a reload does not
 * force a new code, and cleared whenever the API rejects it.
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref(readStoredToken())
  const user = ref(null)
  const status = ref('IDLE') // IDLE | CODE_SENT
  const pendingEmail = ref('')
  const loading = ref(false)
  const error = ref(null)
  const notice = ref(null)
  const restoring = ref(true)

  const isAuthenticated = computed(() => Boolean(token.value && user.value))
  const isAdmin = computed(() => user.value?.role === 'ADMIN')
  const canWriteInventory = computed(() => Boolean(user.value?.canWriteInventory))
  const initials = computed(() =>
    (user.value?.name ?? '?')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join(''),
  )

  function readStoredToken() {
    try {
      return localStorage.getItem(TOKEN_STORAGE_KEY)
    } catch {
      return null
    }
  }

  function persistToken(value) {
    token.value = value
    try {
      if (value) localStorage.setItem(TOKEN_STORAGE_KEY, value)
      else localStorage.removeItem(TOKEN_STORAGE_KEY)
    } catch {
      /* private browsing; the session simply won't survive a reload */
    }
  }

  function describe(err) {
    if (err instanceof ApiError) return err.firstValidationError() ?? err.message
    return err?.message ?? 'Something went wrong.'
  }

  /* ------------------------------------------------------------------ */
  /* Sign-in flow                                                        */
  /* ------------------------------------------------------------------ */

  async function requestCode(email) {
    loading.value = true
    error.value = null
    notice.value = null
    try {
      const response = await api.requestCode(email.trim().toLowerCase())
      pendingEmail.value = email.trim().toLowerCase()
      status.value = 'CODE_SENT'
      notice.value = response?.message ?? 'Check your email for the sign-in code.'
      return true
    } catch (err) {
      error.value = describe(err)
      return false
    } finally {
      loading.value = false
    }
  }

  async function verifyCode(code) {
    loading.value = true
    error.value = null
    try {
      const response = await api.verifyCode(pendingEmail.value, code.trim(), 'web')
      persistToken(response.token)
      user.value = response.user
      status.value = 'IDLE'
      notice.value = null
      return true
    } catch (err) {
      error.value = describe(err)
      return false
    } finally {
      loading.value = false
    }
  }

  function restart() {
    status.value = 'IDLE'
    pendingEmail.value = ''
    error.value = null
    notice.value = null
  }

  /* ------------------------------------------------------------------ */
  /* Session                                                             */
  /* ------------------------------------------------------------------ */

  /** Re-validates a persisted token on boot. */
  async function restore() {
    restoring.value = true
    try {
      if (!token.value) return
      const response = await api.me()
      user.value = response.user
    } catch (err) {
      // A dead token is cleared. A network failure keeps it, so someone whose
      // connection blinked lands back on their session rather than being made
      // to fetch a new emailed code.
      if (err instanceof ApiError && !err.isTransient) {
        persistToken(null)
        user.value = null
      }
    } finally {
      restoring.value = false
    }
  }

  async function logout() {
    try {
      if (token.value) await api.logout()
    } catch {
      /* revoking server-side is best effort; the local token goes either way */
    }
    persistToken(null)
    user.value = null
    restart()

    // No inventory data to wipe: the store is memory only and App.vue clears
    // it as soon as `isAuthenticated` goes false.
  }

  /** Called by the API client when the server rejects our token mid-session. */
  function onTokenRejected() {
    persistToken(null)
    user.value = null
  }

  async function updateProfile(name) {
    loading.value = true
    error.value = null
    try {
      const response = await api.updateProfile(name)
      user.value = response.user
      return true
    } catch (err) {
      error.value = describe(err)
      return false
    } finally {
      loading.value = false
    }
  }

  setUnauthenticatedHandler(onTokenRejected)

  return {
    token,
    user,
    status,
    pendingEmail,
    loading,
    error,
    notice,
    restoring,
    isAuthenticated,
    isAdmin,
    canWriteInventory,
    initials,
    requestCode,
    verifyCode,
    restart,
    restore,
    logout,
    updateProfile,
  }
})
