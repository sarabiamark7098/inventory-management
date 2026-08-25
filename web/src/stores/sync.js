import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

import { api, ApiError } from '@/api/client'
import { db, getMeta, setMeta } from '@/db/local'
import {
  acknowledge,
  claimBatch,
  countByStatus,
  markFailed,
  pendingDocIds,
  releaseStale,
  requeue,
} from '@/db/outbox'
import { uuid } from '@/domain/loan'
import { useAuthStore } from './auth'
import { useInventoryStore } from './inventory'

const POLL_INTERVAL_MS = 30_000
const PUSH_BATCH_SIZE = 100

/**
 * Background sync engine (Epic 4).
 *
 * Runs one push -> pull cycle on load, whenever connectivity returns, when the
 * tab regains focus, and on a slow poll.
 */
export const useSyncStore = defineStore('sync', () => {
  const connection = ref(navigator.onLine ? 'ONLINE' : 'OFFLINE')
  const pending = ref(0)
  const failed = ref(0)
  const lastSyncedAt = ref(null)
  const lastError = ref(null)

  let running = false
  let started = false
  let timer = null
  let listeners = []

  /** Drives the status badge (US-4.4). */
  const badge = computed(() => {
    if (connection.value === 'SYNCING') {
      return { label: 'Syncing', severity: 'info', icon: 'pi pi-sync pi-spin' }
    }
    if (connection.value === 'OFFLINE') {
      return {
        label: pending.value > 0 ? `Offline · ${pending.value} queued` : 'Offline',
        severity: 'secondary',
        icon: 'pi pi-cloud-off',
      }
    }
    // Red is reserved for changes the server actively refused — those need a
    // human. A merely unreachable server still shows the pending count.
    if (failed.value > 0) {
      return {
        label: `Sync error [${failed.value}]`,
        severity: 'danger',
        icon: 'pi pi-exclamation-triangle',
      }
    }
    if (pending.value > 0) {
      return {
        label: `Sync pending [${pending.value}]`,
        severity: 'warn',
        icon: 'pi pi-cloud-upload',
      }
    }
    if (connection.value === 'ERROR') {
      return { label: 'Sync error', severity: 'danger', icon: 'pi pi-exclamation-triangle' }
    }
    return { label: 'Online', severity: 'success', icon: 'pi pi-cloud' }
  })

  async function getClientId() {
    let id = await getMeta('clientId')
    if (!id) {
      id = uuid()
      await setMeta('clientId', id)
    }
    return id
  }

  async function refreshCounters() {
    pending.value = await countByStatus(['PENDING', 'IN_FLIGHT'])
    failed.value = await countByStatus(['FAILED'])
  }

  /* ------------------------------------------------------------------ */
  /* Reconciliation                                                      */
  /* ------------------------------------------------------------------ */

  /**
   * Writes a server document into the local store.
   *
   * `force` is used for push responses (the server just adjudicated this exact
   * document). During a pull we skip documents that still have queued local
   * edits — the "local override" half of US-4.3.
   */
  async function applyServerDocument(table, doc, force) {
    const local = await db[table].get(doc.id)
    if (!local) {
      await db[table].put(doc)
      return
    }
    if (!force) {
      // Last-Write-Wins on UTC timestamps.
      if (new Date(doc.updatedAt) <= new Date(local.updatedAt)) return
    }
    await db[table].put(doc)
  }

  /**
   * Applies a pull payload, returning the `updatedAt` of the earliest document
   * it deliberately skipped (or null if it skipped nothing).
   *
   * The caller needs that value: a document skipped because it has unsynced
   * local edits must NOT be left behind the checkpoint, or the server version
   * is never offered again and the two copies silently diverge forever.
   */
  async function applyPull(payload) {
    let earliestSkipped = null

    for (const table of ['categories', 'equipment', 'borrowings']) {
      const docs = payload[table] ?? []
      if (docs.length === 0) continue

      const dirty = await pendingDocIds(table)
      for (const doc of docs) {
        // A server-flagged conflict always wins, even over unsynced local edits,
        // so the operator sees the contested record immediately.
        const isConflict = table === 'borrowings' && doc.status === 'CONFLICT_PENDING_REVIEW'
        if (dirty.has(doc.id) && !isConflict) {
          if (earliestSkipped === null || doc.updatedAt < earliestSkipped) {
            earliestSkipped = doc.updatedAt
          }
          continue
        }
        await applyServerDocument(table, doc, isConflict)
      }
    }

    return earliestSkipped
  }

  /* ------------------------------------------------------------------ */
  /* Phases                                                              */
  /* ------------------------------------------------------------------ */

  async function pushPhase() {
    await releaseStale()
    const batch = await claimBatch(PUSH_BATCH_SIZE)
    if (batch.length === 0) return

    const ids = batch.map((e) => e.id)
    let response
    try {
      response = await api.push({
        clientId: await getClientId(),
        changes: batch.map(({ id, table, action, docId, payload, clientTimestamp }) => ({
          id,
          table,
          action,
          docId,
          payload,
          clientTimestamp,
        })),
      })
    } catch (err) {
      const transient = err instanceof ApiError ? err.isTransient : true
      // Transient failures keep the changes queued; only a 4xx needs a human.
      await (transient ? requeue(ids, err.message) : markFailed(ids, err.message))
      throw err
    }

    const settled = []
    const rejected = []
    for (const result of response.results) {
      if (result.status === 'REJECTED') rejected.push(result.changeId)
      else settled.push(result.changeId)

      if (result.document) {
        await applyServerDocument(result.table, result.document, true)
      }
    }

    await acknowledge(settled)
    if (rejected.length > 0) {
      const reason =
        response.results.find((r) => r.status === 'REJECTED')?.message ??
        'Server rejected the change'
      await markFailed(rejected, reason)
    }
  }

  async function pullPhase() {
    const since = await getMeta('lastSyncedAt')
    const payload = await api.pull(since)
    const earliestSkipped = await applyPull(payload)

    /*
     * Rewind the checkpoint to just before anything we skipped, so the next
     * cycle offers those documents again once the local edits have drained.
     * Advancing past them would strand the server's version permanently.
     */
    const checkpoint = earliestSkipped
      ? new Date(new Date(earliestSkipped).getTime() - 1).toISOString()
      : payload.serverTime

    await setMeta('lastSyncedAt', checkpoint)
    return payload.serverTime
  }

  /** One full push -> pull cycle. Concurrent calls share the same run. */
  async function synchronize() {
    if (running) return
    running = true

    try {
      if (!navigator.onLine) {
        connection.value = 'OFFLINE'
        await refreshCounters()
        return
      }

      connection.value = 'SYNCING'
      lastError.value = null

      // Promote past-due loans before pushing, so the server sees them too.
      await useInventoryStore().sweepOverdue()

      // Borrowers have read-only access to the catalogue; pushing on their
      // behalf would just collect 403s every cycle.
      if (useAuthStore().canWriteInventory) await pushPhase()

      const serverTime = await pullPhase()

      await refreshCounters()
      lastSyncedAt.value = serverTime
      connection.value = 'ONLINE'
    } catch (err) {
      await refreshCounters()
      lastError.value = err?.message ?? String(err)
      connection.value = navigator.onLine ? 'ERROR' : 'OFFLINE'
    } finally {
      running = false
    }
  }

  /** Clears FAILED entries back to PENDING so the next cycle retries them. */
  async function retryFailed() {
    await db.sync_queue
      .where('status')
      .equals('FAILED')
      .modify({ status: 'PENDING', lastError: null })
    await refreshCounters()
    await synchronize()
  }

  function start() {
    if (started) return
    started = true

    const onOnline = () => {
      connection.value = 'ONLINE'
      synchronize()
    }
    const onOffline = () => {
      connection.value = 'OFFLINE'
    }
    const onVisible = () => {
      if (document.visibilityState === 'visible') synchronize()
    }

    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    document.addEventListener('visibilitychange', onVisible)
    listeners = [
      () => window.removeEventListener('online', onOnline),
      () => window.removeEventListener('offline', onOffline),
      () => document.removeEventListener('visibilitychange', onVisible),
    ]

    getMeta('lastSyncedAt').then((value) => {
      lastSyncedAt.value = value
    })

    refreshCounters()
    synchronize()
    timer = setInterval(synchronize, POLL_INTERVAL_MS)
  }

  function stop() {
    listeners.forEach((off) => off())
    listeners = []
    if (timer) clearInterval(timer)
    timer = null
    started = false
  }

  return {
    connection,
    pending,
    failed,
    lastSyncedAt,
    lastError,
    badge,
    start,
    stop,
    synchronize,
    retryFailed,
    refreshCounters,
  }
})
