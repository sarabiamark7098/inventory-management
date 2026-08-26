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

// v2 adds government property accountability fields. Existing rows are left in
// place; the new indexes simply have no value for them until an operator fills
// the numbers in, which is why the PAR requirement applies to new assets only.
db.version(2).stores({
  equipment:
    'id, assetTag, serialNumber, categoryId, status, parNumber, parIssuedTo, subParNumber, subParIssuedTo, province, division, section, updatedAt, deletedAt',
})

// v3 adds the official property number (indexed so the scanner can resolve it)
// and the acquisition date.
db.version(3).stores({
  equipment:
    'id, assetTag, propertyNumber, dateAcquired, serialNumber, categoryId, status, parNumber, parIssuedTo, subParNumber, subParIssuedTo, province, division, section, updatedAt, deletedAt',
})

// v4 adds the monitor serial (desktops are one asset with two serialised parts)
// and the deployment details support staff search by.
// v5 adds the office register (divisions and their sections) and swaps the
// free-text division/section on equipment for references into it.
db.version(5).stores({
  offices: 'id, code, name, divisionId, updatedAt, deletedAt',
  equipment:
    'id, assetTag, propertyNumber, dateAcquired, serialNumber, monitorSerialNumber, categoryId, status, divisionId, sectionId, parIssuedTo, province, actualUser, adUsername, anydeskNumber, updatedAt, deletedAt',
})

db.version(4).stores({
  equipment:
    'id, assetTag, propertyNumber, dateAcquired, serialNumber, monitorSerialNumber, categoryId, status, parNumber, parIssuedTo, subParNumber, subParIssuedTo, province, division, section, actualUser, adUsername, anydeskNumber, updatedAt, deletedAt',
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
    [db.offices, db.categories, db.equipment, db.borrowings, db.sync_queue, db.meta],
    async () => {
      await Promise.all([
        db.offices.clear(),
        db.categories.clear(),
        db.equipment.clear(),
        db.borrowings.clear(),
        db.sync_queue.clear(),
        db.meta.clear(),
      ])
    },
  )
}
