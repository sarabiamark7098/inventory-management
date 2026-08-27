import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, setReachabilityHandler } from '@/api/client'

/** How often to re-probe while the server is known to be unreachable. */
const RETRY_MS = 5000

/** How often to confirm the server is still there while everything is fine. */
const HEARTBEAT_MS = 30000

/**
 * Whether the API is reachable right now.
 *
 * The app is online-only: every screen reads and writes straight through the
 * API, so losing the connection means losing the application, and the honest
 * thing to do is say so rather than let someone type into a form that cannot
 * be saved.
 *
 * `navigator.onLine` alone is not enough — it reports whether a network
 * interface is up, not whether our server answers, and it is cheerfully `true`
 * on a captive-portal wifi or when the API host is down. So the source of
 * truth is a real request to /health, with `navigator.onLine` used only as a
 * free early warning that saves us waiting for a fetch to time out.
 */
export const useConnectionStore = defineStore('connection', () => {
  // Optimistic at boot: assume reachable until a request says otherwise, so a
  // healthy start does not flash the offline screen.
  const reachable = ref(true)
  const checking = ref(false)
  const lastCheckedAt = ref(null)
  const wasEverConnected = ref(false)

  let timer = null
  let listenersBound = false

  const online = computed(() => reachable.value)

  const badge = computed(() => {
    if (checking.value && !reachable.value) {
      return { label: 'Reconnecting…', icon: 'pi pi-spin pi-sync', severity: 'warn' }
    }
    return reachable.value
      ? { label: 'Online', icon: 'pi pi-wifi', severity: 'success' }
      : { label: 'Offline', icon: 'pi pi-exclamation-triangle', severity: 'danger' }
  })

  /**
   * Records what an ordinary request just proved about reachability.
   *
   * Every API call reports through here, which is why the heartbeat can be as
   * slow as it is: while someone is using the app, their own traffic is a far
   * better signal than a timer.
   */
  function observe(isReachable) {
    lastCheckedAt.value = new Date().toISOString()
    if (isReachable) wasEverConnected.value = true

    if (reachable.value !== isReachable) {
      reachable.value = isReachable
      schedule()
    }
  }

  /** Probes /health directly. Used on boot, by the retry loop, and by the UI. */
  async function check() {
    if (checking.value) return reachable.value
    checking.value = true
    try {
      await api.health()
      observe(true)
    } catch {
      // `observe(false)` already fired from the client's own error path for a
      // network failure; an HTTP error means the server answered, so it is up.
    } finally {
      checking.value = false
    }
    return reachable.value
  }

  function schedule() {
    if (timer) clearTimeout(timer)
    timer = setTimeout(
      async () => {
        await check()
        schedule()
      },
      reachable.value ? HEARTBEAT_MS : RETRY_MS,
    )
  }

  function start() {
    setReachabilityHandler(observe)

    if (!listenersBound && typeof window !== 'undefined') {
      // The browser telling us the interface went down is instant; believe it
      // for the pessimistic direction only, and confirm the optimistic one.
      window.addEventListener('offline', () => observe(false))
      window.addEventListener('online', () => check())
      listenersBound = true
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      reachable.value = false
    }

    check()
    schedule()
  }

  function stop() {
    if (timer) clearTimeout(timer)
    timer = null
  }

  return { reachable, online, checking, lastCheckedAt, wasEverConnected, badge, check, start, stop }
})
