import { defineStore } from 'pinia'
import { liveQuery } from 'dexie'
import { ref, computed } from 'vue'

import { db } from '@/db/local'
import { enqueue } from '@/db/outbox'
import { deepClone } from '@/domain/clone'
import { calculateExpectedReturn, isOverdue, nowIso, toDateOnly, uuid } from '@/domain/loan'
import { canBeBorrowed, explainNotBorrowable } from '@/domain/constants'

/**
 * The single write path for the whole app.
 *
 * Reads are Dexie live queries, so any local write (or a document arriving from
 * the pull phase) re-renders every screen automatically. Writes always pair the
 * document with an outbox entry — see `db/outbox.js`.
 */
export const useInventoryStore = defineStore('inventory', () => {
  const categories = ref([])
  const equipment = ref([])
  const borrowings = ref([])
  const loading = ref(true)

  let subscriptions = []

  function start() {
    if (subscriptions.length) return

    const notDeleted = (row) => row.deletedAt == null

    subscriptions = [
      liveQuery(() => db.categories.filter(notDeleted).toArray()).subscribe({
        next: (rows) => {
          categories.value = rows.sort((a, b) => a.name.localeCompare(b.name))
          loading.value = false
        },
      }),
      liveQuery(() => db.equipment.filter(notDeleted).toArray()).subscribe({
        next: (rows) => {
          equipment.value = rows.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
        },
      }),
      liveQuery(() => db.borrowings.filter(notDeleted).toArray()).subscribe({
        next: (rows) => {
          borrowings.value = rows.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
        },
      }),
    ]
  }

  function stop() {
    subscriptions.forEach((s) => s.unsubscribe())
    subscriptions = []
  }

  /**
   * Strips Vue reactivity before a document touches IndexedDB.
   *
   * Form state arrives here as reactive Proxies, and BOTH IndexedDB's `put()`
   * and the outbox payload go through the structured clone algorithm, which
   * rejects a Proxy with `DataCloneError`. Every write goes through this, so a
   * component can hand over its form object without knowing any of that.
   */
  function persistable(doc) {
    return deepClone(doc) ?? doc
  }

  /* --------------------------- derived state -------------------------- */

  const categoryById = computed(() => new Map(categories.value.map((c) => [c.id, c])))
  const equipmentById = computed(() => new Map(equipment.value.map((e) => [e.id, e])))

  const activeLoans = computed(() =>
    borrowings.value.filter((tx) => ['ISSUED', 'APPROVED', 'OVERDUE'].includes(tx.status)),
  )
  const overdueLoans = computed(() => activeLoans.value.filter((tx) => isOverdue(tx)))
  const pendingRequests = computed(() => borrowings.value.filter((tx) => tx.status === 'REQUESTED'))
  const conflicts = computed(() =>
    borrowings.value.filter((tx) => tx.status === 'CONFLICT_PENDING_REVIEW'),
  )

  const stats = computed(() => {
    const byStatus = {}
    for (const item of equipment.value) {
      byStatus[item.status] = (byStatus[item.status] ?? 0) + 1
    }
    return {
      total: equipment.value.length,
      available: byStatus.AVAILABLE ?? 0,
      issued: byStatus.ISSUED ?? 0,
      maintenance: byStatus.MAINTENANCE ?? 0,
      lost: byStatus.LOST ?? 0,
      onLoan: activeLoans.value.length,
      overdue: overdueLoans.value.length,
      requests: pendingRequests.value.length,
      conflicts: conflicts.value.length,
    }
  })

  /**
   * Serials are checked across BOTH columns.
   *
   * A monitor's serial must not collide with another machine's system unit
   * serial either, or scanning one physical device would resolve to two
   * records. The server enforces the same rule; this just fails fast offline.
   */
  async function assertSerialsAreFree(serials, ignoreId) {
    for (const serial of serials) {
      if (!serial) continue
      const clash = await db.equipment
        .filter(
          (row) =>
            row.deletedAt == null &&
            row.id !== ignoreId &&
            (row.serialNumber === serial || row.monitorSerialNumber === serial),
        )
        .first()
      if (clash) {
        throw new Error(`Serial number "${serial}" is already used by ${clash.assetTag}.`)
      }
    }
  }

  /* ----------------------------- categories --------------------------- */

  async function createCategory(input) {
    const ts = nowIso()
    const doc = persistable({
      id: uuid(),
      name: input.name.trim(),
      description: input.description?.trim() || null,
      customFields: input.customFields ?? { fields: [] },
      defaultLoanDays: Number(input.defaultLoanDays) || 0,
      requiresMonitorSerial: Boolean(input.requiresMonitorSerial),
      createdAt: ts,
      updatedAt: ts,
      deletedAt: null,
    })
    await db.transaction('rw', [db.categories, db.sync_queue], async () => {
      await db.categories.add(doc)
      await enqueue({ table: 'categories', action: 'INSERT', docId: doc.id, payload: doc })
    })
    return doc
  }

  async function updateCategory(id, patch) {
    const current = await db.categories.get(id)
    if (!current) throw new Error('Category not found.')

    const doc = persistable({
      ...current,
      ...(patch.name !== undefined ? { name: patch.name.trim() } : {}),
      ...(patch.description !== undefined
        ? { description: patch.description?.trim() || null }
        : {}),
      ...(patch.customFields !== undefined ? { customFields: patch.customFields } : {}),
      ...(patch.defaultLoanDays !== undefined
        ? { defaultLoanDays: Number(patch.defaultLoanDays) || 0 }
        : {}),
      ...(patch.requiresMonitorSerial !== undefined
        ? { requiresMonitorSerial: Boolean(patch.requiresMonitorSerial) }
        : {}),
      updatedAt: nowIso(),
    })
    await db.transaction('rw', [db.categories, db.sync_queue], async () => {
      await db.categories.put(doc)
      await enqueue({ table: 'categories', action: 'UPDATE', docId: id, payload: doc })
    })
  }

  /** Soft delete, so other clients learn about the removal. */
  async function deleteCategory(id) {
    const current = await db.categories.get(id)
    if (!current) return
    const ts = nowIso()
    const doc = persistable({ ...current, deletedAt: ts, updatedAt: ts })
    await db.transaction('rw', [db.categories, db.sync_queue], async () => {
      await db.categories.put(doc)
      await enqueue({
        table: 'categories',
        action: 'DELETE',
        docId: id,
        payload: { id, deletedAt: ts, updatedAt: ts },
      })
    })
  }

  /* ----------------------------- equipment ---------------------------- */

  async function createEquipment(input) {
    const assetTag = input.assetTag.trim()
    const clash = await db.equipment
      .where('assetTag')
      .equals(assetTag)
      .filter((row) => row.deletedAt == null)
      .first()
    if (clash) throw new Error(`Asset tag "${assetTag}" is already in use.`)

    // Government accountability: refuse here rather than letting the asset sit
    // in the outbox and come back REJECTED long after the operator moved on.
    const parNumber = input.parNumber?.trim() || null
    if (!parNumber) throw new Error('A PAR number is required to register equipment.')

    // A number with nobody named on it does not establish accountability.
    const parIssuedTo = input.parIssuedTo?.trim() || null
    if (!parIssuedTo) throw new Error('The PAR must name the officer it is issued to.')

    const propertyNumber = input.propertyNumber?.trim() || null
    if (!propertyNumber) throw new Error('A property number is required to register equipment.')

    const dateAcquired = toDateOnly(input.dateAcquired)
    if (!dateAcquired) throw new Error('An acquisition date is required to register equipment.')

    const numberClash = await db.equipment
      .where('propertyNumber')
      .equals(propertyNumber)
      .filter((row) => row.deletedAt == null)
      .first()
    if (numberClash) throw new Error(`Property number "${propertyNumber}" is already in use.`)

    const serialNumber = input.serialNumber?.trim() || null
    const monitorSerialNumber = input.monitorSerialNumber?.trim() || null

    // A desktop set is one asset with two serialised parts.
    const category = input.categoryId ? await db.categories.get(input.categoryId) : null
    if (category?.requiresMonitorSerial) {
      if (!serialNumber) throw new Error(`${category.name} needs a system unit serial number.`)
      if (!monitorSerialNumber) throw new Error(`${category.name} needs a monitor serial number.`)
    }
    if (serialNumber && serialNumber === monitorSerialNumber) {
      throw new Error('The system unit and monitor cannot share a serial number.')
    }
    await assertSerialsAreFree([serialNumber, monitorSerialNumber], null)

    const ts = nowIso()
    const doc = persistable({
      id: uuid(),
      assetTag,
      propertyNumber,
      dateAcquired,
      serialNumber,
      monitorSerialNumber,
      categoryId: input.categoryId || null,
      modelName: input.modelName.trim(),
      brand: input.brand?.trim() || null,
      status: input.status ?? 'AVAILABLE',
      condition: input.condition ?? 'GOOD',
      location: input.location?.trim() || null,
      section: input.section?.trim() || null,
      division: input.division?.trim() || null,
      province: input.province?.trim() || null,
      parNumber,
      parIssuedTo,
      subParNumber: input.subParNumber?.trim() || null,
      subParIssuedTo: input.subParIssuedTo?.trim() || null,
      actualUser: input.actualUser?.trim() || null,
      position: input.position?.trim() || null,
      adUsername: input.adUsername?.trim() || null,
      anydeskNumber: input.anydeskNumber?.trim() || null,
      specifications: input.specifications ?? {},
      createdAt: ts,
      updatedAt: ts,
      deletedAt: null,
    })
    await db.transaction('rw', [db.equipment, db.sync_queue], async () => {
      await db.equipment.add(doc)
      await enqueue({ table: 'equipment', action: 'INSERT', docId: doc.id, payload: doc })
    })
    return doc
  }

  async function updateEquipment(id, patch) {
    const current = await db.equipment.get(id)
    if (!current) throw new Error('Equipment not found.')

    const doc = persistable({ ...current, ...patch, updatedAt: nowIso() })

    // The picker hands back a Date; the wire format is a calendar date string.
    if (patch.dateAcquired !== undefined) doc.dateAcquired = toDateOnly(patch.dateAcquired)

    for (const key of ['assetTag', 'modelName']) {
      if (typeof doc[key] === 'string') doc[key] = doc[key].trim()
    }
    for (const key of [
      'serialNumber',
      'brand',
      'location',
      'section',
      'division',
      'province',
      'propertyNumber',
      'parNumber',
      'parIssuedTo',
      'subParNumber',
      'subParIssuedTo',
      'monitorSerialNumber',
      'actualUser',
      'position',
      'adUsername',
      'anydeskNumber',
    ]) {
      if (typeof doc[key] === 'string') doc[key] = doc[key].trim() || null
    }

    // An edit must never silently drop the accountable document.
    if (doc.serialNumber && doc.serialNumber === doc.monitorSerialNumber) {
      throw new Error('The system unit and monitor cannot share a serial number.')
    }
    await assertSerialsAreFree([doc.serialNumber, doc.monitorSerialNumber], id)

    if (current.parNumber && !doc.parNumber) doc.parNumber = current.parNumber
    if (current.parIssuedTo && !doc.parIssuedTo) doc.parIssuedTo = current.parIssuedTo
    if (current.propertyNumber && !doc.propertyNumber) doc.propertyNumber = current.propertyNumber
    if (current.dateAcquired && !doc.dateAcquired) doc.dateAcquired = current.dateAcquired

    await db.transaction('rw', [db.equipment, db.sync_queue], async () => {
      await db.equipment.put(doc)
      await enqueue({ table: 'equipment', action: 'UPDATE', docId: id, payload: doc })
    })
  }

  async function deleteEquipment(id) {
    const current = await db.equipment.get(id)
    if (!current) return
    const ts = nowIso()
    const doc = persistable({ ...current, deletedAt: ts, updatedAt: ts })
    await db.transaction('rw', [db.equipment, db.sync_queue], async () => {
      await db.equipment.put(doc)
      await enqueue({
        table: 'equipment',
        action: 'DELETE',
        docId: id,
        payload: { id, deletedAt: ts, updatedAt: ts },
      })
    })
  }

  /** Asset-tag / serial / QR lookup used by the scanner. */
  async function findByCode(code) {
    const trimmed = code.trim()
    if (!trimmed) return null
    const alive = (row) => row?.deletedAt == null

    const byTag = await db.equipment.where('assetTag').equals(trimmed).filter(alive).first()
    if (byTag) return byTag

    const byProperty = await db.equipment
      .where('propertyNumber')
      .equals(trimmed)
      .filter(alive)
      .first()
    if (byProperty) return byProperty

    const bySerial = await db.equipment.where('serialNumber').equals(trimmed).filter(alive).first()
    if (bySerial) return bySerial

    const byMonitor = await db.equipment
      .where('monitorSerialNumber')
      .equals(trimmed)
      .filter(alive)
      .first()
    if (byMonitor) return byMonitor

    // QR payloads are minted as `ICT:<uuid>` by the label generator.
    const id = trimmed.startsWith('ICT:') ? trimmed.slice(4) : trimmed
    const byId = await db.equipment.get(id)
    return alive(byId) ? byId : null
  }

  /* ----------------------------- borrowing ---------------------------- */

  async function resolveLoanDays(equipmentDoc, override) {
    if (override !== undefined && override !== null) return Number(override)
    if (!equipmentDoc?.categoryId) return 7
    const category = await db.categories.get(equipmentDoc.categoryId)
    return category?.defaultLoanDays ?? 7
  }

  async function checkout(input) {
    const asset = await db.equipment.get(input.equipmentId)
    if (!asset || asset.deletedAt) throw new Error('Equipment not found.')
    // Permanently issued, under repair, written off — out of the loan pool.
    if (!canBeBorrowed(asset.status)) {
      throw new Error(`${asset.assetTag} ${explainNotBorrowable(asset.status)}.`)
    }
    if (asset.status !== 'AVAILABLE') {
      throw new Error(
        `${asset.assetTag} is currently ${asset.status.toLowerCase()} and cannot be issued.`,
      )
    }

    const loanDays = await resolveLoanDays(asset, input.loanDays)
    const issueDate = input.issueDate ?? nowIso()
    const ts = nowIso()

    const tx = persistable({
      id: uuid(),
      equipmentId: asset.id,
      borrowerName: input.borrowerName.trim(),
      borrowerEmail: input.borrowerEmail.trim(),
      issueDate,
      expectedReturnDate:
        input.expectedReturnDate ?? calculateExpectedReturn(issueDate, loanDays).toISOString(),
      actualReturnDate: null,
      status: input.status ?? 'ISSUED',
      notes: input.notes?.trim() || null,
      conflictReason: null,
      createdAt: ts,
      updatedAt: ts,
      deletedAt: null,
    })

    await db.transaction('rw', [db.borrowings, db.sync_queue], async () => {
      await db.borrowings.add(tx)
      await enqueue({ table: 'borrowings', action: 'INSERT', docId: tx.id, payload: tx })
    })

    if (tx.status !== 'REQUESTED') {
      await updateEquipment(asset.id, { status: 'BORROWED' })
    }
    return tx
  }

  function requestLoan(input) {
    return checkout({ ...input, status: 'REQUESTED' })
  }

  async function setBorrowingStatus(id, status) {
    const current = await db.borrowings.get(id)
    if (!current) throw new Error('Transaction not found.')
    const doc = persistable({ ...current, status, updatedAt: nowIso() })
    await db.transaction('rw', [db.borrowings, db.sync_queue], async () => {
      await db.borrowings.put(doc)
      await enqueue({ table: 'borrowings', action: 'UPDATE', docId: id, payload: doc })
    })
    return doc
  }

  async function approveRequest(id) {
    const doc = await setBorrowingStatus(id, 'ISSUED')
    if (doc.equipmentId) await updateEquipment(doc.equipmentId, { status: 'BORROWED' })
  }

  /**
   * Return inspection (US-3.3). Condition drives the asset's next status:
   * DAMAGED/POOR routes to MAINTENANCE, otherwise back to AVAILABLE.
   */
  async function processReturn(input) {
    const current = await db.borrowings.get(input.transactionId)
    if (!current) throw new Error('Transaction not found.')

    const inspection = input.inspectionNotes?.trim()
    const doc = persistable({
      ...current,
      status: 'RETURNED',
      actualReturnDate: input.actualReturnDate ?? nowIso(),
      notes: inspection
        ? [current.notes, `[Return inspection] ${inspection}`].filter(Boolean).join('\n')
        : current.notes,
      updatedAt: nowIso(),
    })

    await db.transaction('rw', [db.borrowings, db.sync_queue], async () => {
      await db.borrowings.put(doc)
      await enqueue({
        table: 'borrowings',
        action: 'UPDATE',
        docId: doc.id,
        payload: doc,
      })
    })

    if (current.equipmentId) {
      const nextStatus = input.markLost
        ? 'LOST'
        : ['DAMAGED', 'POOR'].includes(input.condition)
          ? 'MAINTENANCE'
          : 'AVAILABLE'
      await updateEquipment(current.equipmentId, {
        status: nextStatus,
        condition: input.condition,
      })
    }
  }

  /** Promotes past-due active loans to OVERDUE and queues the change (US-3.4). */
  async function sweepOverdue() {
    const candidates = await db.borrowings
      .where('status')
      .anyOf('ISSUED', 'APPROVED')
      .filter((tx) => tx.deletedAt == null && isOverdue(tx))
      .toArray()

    for (const tx of candidates) {
      await setBorrowingStatus(tx.id, 'OVERDUE')
    }
    return candidates
  }

  return {
    categories,
    equipment,
    borrowings,
    loading,
    categoryById,
    equipmentById,
    activeLoans,
    overdueLoans,
    pendingRequests,
    conflicts,
    stats,
    start,
    stop,
    createCategory,
    updateCategory,
    deleteCategory,
    createEquipment,
    updateEquipment,
    deleteEquipment,
    findByCode,
    checkout,
    requestLoan,
    approveRequest,
    setBorrowingStatus,
    processReturn,
    sweepOverdue,
  }
})
