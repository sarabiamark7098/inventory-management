import { db } from './local'
import { nowIso, uuid } from '@/domain/loan'

/**
 * Outbox pattern.
 *
 * Every local mutation writes its document AND appends an entry here in the same
 * Dexie transaction, so a change can never be applied locally without also being
 * scheduled for the server.
 */

export async function enqueue({ table, action, docId, payload }) {
  const entry = {
    id: uuid(),
    table,
    action,
    docId,
    payload,
    clientTimestamp: nowIso(),
    status: 'PENDING',
    attempts: 0,
    lastError: null,
  }
  await db.sync_queue.add(entry)
  return entry
}

/** Oldest-first batch the engine may push right now. */
export async function claimBatch(limit = 100) {
  const entries = await db.sync_queue
    .where('status')
    .anyOf('PENDING', 'FAILED')
    .sortBy('clientTimestamp')

  const batch = entries.slice(0, limit)
  if (batch.length === 0) return []

  await db.sync_queue
    .where('id')
    .anyOf(batch.map((e) => e.id))
    .modify({ status: 'IN_FLIGHT' })

  return batch
}

export async function acknowledge(ids) {
  if (ids.length === 0) return
  await db.sync_queue.bulkDelete(ids)
}

async function patch(ids, status, error) {
  if (ids.length === 0) return
  await db.sync_queue
    .where('id')
    .anyOf(ids)
    .modify((entry) => {
      entry.status = status
      entry.attempts = (entry.attempts ?? 0) + 1
      entry.lastError = error ? String(error).slice(0, 500) : null
    })
}

/**
 * The server refused these on their merits. They stay FAILED until an operator
 * retries, because replaying them unchanged will just fail again.
 */
export function markFailed(ids, error) {
  return patch(ids, 'FAILED', error)
}

/**
 * The server was unreachable or errored. Nothing is wrong with the changes, so
 * they return to PENDING and the next cycle picks them up automatically.
 */
export function requeue(ids, error) {
  return patch(ids, 'PENDING', error)
}

/** Releases IN_FLIGHT entries orphaned by a tab that closed mid-push. */
export async function releaseStale(olderThanMs = 60_000) {
  const cutoff = new Date(Date.now() - olderThanMs).toISOString()
  await db.sync_queue
    .where('status')
    .equals('IN_FLIGHT')
    .and((entry) => entry.clientTimestamp < cutoff)
    .modify({ status: 'PENDING' })
}

export async function countByStatus(statuses) {
  return db.sync_queue.where('status').anyOf(statuses).count()
}

/** Document ids with unsynced local edits — these win over incoming pull data. */
export async function pendingDocIds(table) {
  const entries = await db.sync_queue.where('table').equals(table).toArray()
  return new Set(entries.map((e) => e.docId))
}
