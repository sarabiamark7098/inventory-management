import Dexie from 'dexie'

/**
 * Local-first store (IndexedDB via Dexie).
 *
 * Every screen reads and writes here, never the network — that is what makes
 * the app work offline (US-4.1). The `sync_queue` table is the outbox that the
 * sync engine drains once connectivity returns.
 */
export const db = new Dexie('ict_inventory')

db.version(1).stores({
  categories: 'id, name, updatedAt, deletedAt',
  equipment: 'id, assetTag, serialNumber, categoryId, status, updatedAt, deletedAt',
  borrowings: 'id, equipmentId, status, expectedReturnDate, updatedAt, deletedAt',
  sync_queue: 'id, table, docId, status, clientTimestamp',
  meta: 'key',
})

/* ------------------------------------------------------------------ */
/* Checkpoint + client identity                                        */
/* ------------------------------------------------------------------ */

export async function getMeta(key, fallback = null) {
  const row = await db.meta.get(key)
  return row ? row.value : fallback
}

export async function setMeta(key, value) {
  await db.meta.put({ key, value })
}

/** Wipes local data. Used by "reset local store" in the sync panel. */
export async function resetLocal() {
  await db.transaction(
    'rw',
    [db.categories, db.equipment, db.borrowings, db.sync_queue, db.meta],
    async () => {
      await Promise.all([
        db.categories.clear(),
        db.equipment.clear(),
        db.borrowings.clear(),
        db.sync_queue.clear(),
        db.meta.clear(),
      ])
    },
  )
}
