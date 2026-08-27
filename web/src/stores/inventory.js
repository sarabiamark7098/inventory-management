import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

import { api, ApiError } from '@/api/client'
import { calculateExpectedReturn, isOverdue, nowIso, toDateOnly, uuid } from '@/domain/loan'
import { canBeBorrowed, explainNotBorrowable } from '@/domain/constants'

const TABLES = ['offices', 'categories', 'equipment', 'borrowings']

/**
 * The single data path for the whole app.
 *
 * Every read and write goes straight to the API — there is no local database
 * behind this store, and nothing here survives a reload. The arrays are a
 * working copy of what the server last told us, kept in memory only so the
 * screens have something to render and filter without a request per keystroke.
 *
 * Writes return the saved row, which is applied over the working copy. That
 * matters more than it looks: the server normalises values on the way in and
 * reconciles equipment status from loan activity, so what came back is
 * frequently not what we sent.
 */
export const useInventoryStore = defineStore('inventory', () => {
  const offices = ref([])
  const categories = ref([])
  const equipment = ref([])
  const borrowings = ref([])
  const loading = ref(true)
  const error = ref(null)
  const lastLoadedAt = ref(null)

  const collections = { offices, categories, equipment, borrowings }

  /* ------------------------------- loading ---------------------------- */

  const byName = (a, b) => a.name.localeCompare(b.name)
  const byRecency = (a, b) => String(b.updatedAt).localeCompare(String(a.updatedAt))

  const sorters = {
    offices: byName,
    categories: byName,
    equipment: byRecency,
    borrowings: byRecency,
  }

  function apply(table, rows) {
    collections[table].value = [...rows].sort(sorters[table])
  }

  async function reload(table) {
    const response = await api.list(table)
    apply(table, response.data ?? [])
  }

  /**
   * Pulls the whole dataset down again.
   *
   * All four collections in parallel and replaced wholesale, rather than
   * merged: this is also the recovery path after a connection drop, and
   * merging would preserve rows another operator deleted while we were away.
   */
  async function refresh() {
    loading.value = true
    error.value = null
    try {
      await Promise.all(TABLES.map(reload))
      lastLoadedAt.value = nowIso()
    } catch (err) {
      error.value = describe(err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /** Loads once for a signed-in session. */
  async function start() {
    if (lastLoadedAt.value) return
    await refresh().catch(() => {
      /* the connection gate reports this; the store just stays empty */
    })
  }

  function stop() {
    for (const table of TABLES) collections[table].value = []
    archive.value = { equipment: [], categories: [], offices: [], borrowings: [] }
    lastLoadedAt.value = null
    loading.value = true
    error.value = null
  }

  function describe(err) {
    if (err instanceof ApiError) return err.firstValidationError() ?? err.message
    return err?.message ?? 'Something went wrong.'
  }

  /* -------------------------------- writes ---------------------------- */

  /** Applies a saved row over the working copy, inserting it if it is new. */
  function absorb(table, row) {
    if (!row) return null
    const list = collections[table].value
    const at = list.findIndex((item) => item.id === row.id)

    if (row.deletedAt) {
      if (at >= 0) list.splice(at, 1)
      return row
    }

    if (at >= 0) list[at] = row
    else list.push(row)
    apply(table, list)
    return row
  }

  /**
   * A 409 is not a failed write.
   *
   * The Status Lock Rule stores a disputed loan and flags it
   * `CONFLICT_PENDING_REVIEW` rather than dropping it — someone physically has
   * the device either way. So the row is absorbed before the error is
   * re-thrown, and the caller's error message is the operator's notification
   * that the booking needs adjudicating.
   */
  async function write(table, send) {
    try {
      const res = await send()
      return absorb(table, res.data)
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) absorb(table, err.body?.data)
      throw err
    }
  }

  const created = (table, payload) => write(table, () => api.create(table, payload))

  const saved = (table, id, payload) => write(table, () => api.save(table, id, payload))

  const removed = (table, id) =>
    api.remove(table, id).then(() => {
      const list = collections[table].value
      const at = list.findIndex((item) => item.id === id)
      if (at >= 0) list.splice(at, 1)
    })

  /* --------------------------- derived state -------------------------- */

  const officeById = computed(() => new Map(offices.value.map((o) => [o.id, o])))

  /** `divisionId` null means the office IS a division. */
  const divisions = computed(() => offices.value.filter((o) => !o.divisionId))

  const sectionsByDivision = computed(() => {
    const map = new Map()
    for (const office of offices.value) {
      if (!office.divisionId) continue
      if (!map.has(office.divisionId)) map.set(office.divisionId, [])
      map.get(office.divisionId).push(office)
    }
    return map
  })

  const officeLabel = (id) => officeById.value.get(id)?.name ?? null

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
   * records. The server enforces this too; this is the fast, specific message.
   */
  function assertSerialsAreFree(serials, ignoreId) {
    for (const serial of serials) {
      if (!serial) continue
      const clash = equipment.value.find(
        (row) =>
          row.id !== ignoreId &&
          (row.serialNumber === serial || row.monitorSerialNumber === serial),
      )
      if (clash) {
        throw new Error(`Serial number "${serial}" is already used by ${clash.assetTag}.`)
      }
    }
  }

  /* ------------------------------ offices ----------------------------- */

  async function createOffice(input) {
    const code = input.code?.trim().toUpperCase()
    if (!code) throw new Error('An office code is required.')
    if (!input.name?.trim()) throw new Error('An office name is required.')

    const clash = offices.value.find((row) => row.code === code)
    if (clash) throw new Error(`Office code "${code}" is already used by ${clash.name}.`)

    const ts = nowIso()
    return created('offices', {
      id: uuid(),
      code,
      name: input.name.trim(),
      // Null means this office is itself a division.
      divisionId: input.divisionId || null,
      createdAt: ts,
      updatedAt: ts,
      deletedAt: null,
    })
  }

  async function updateOffice(id, patch) {
    const current = officeById.value.get(id)
    if (!current) throw new Error('Office not found.')

    if (patch.divisionId === id) throw new Error('An office cannot be its own division.')

    // Demoting a division that still has sections would orphan them.
    if (!current.divisionId && patch.divisionId) {
      const sections = (sectionsByDivision.value.get(id) ?? []).length
      if (sections > 0) {
        throw new Error(
          `${current.name} has ${sections} section${sections === 1 ? '' : 's'} and cannot become a section itself.`,
        )
      }
    }

    const code = (patch.code ?? current.code).trim().toUpperCase()
    const clash = offices.value.find((row) => row.code === code && row.id !== id)
    if (clash) throw new Error(`Office code "${code}" is already used by ${clash.name}.`)

    return saved('offices', id, {
      ...current,
      code,
      ...(patch.name !== undefined ? { name: patch.name.trim() } : {}),
      ...(patch.divisionId !== undefined ? { divisionId: patch.divisionId || null } : {}),
      updatedAt: nowIso(),
    })
  }

  async function deleteOffice(id) {
    const current = officeById.value.get(id)
    if (!current) return

    // Refuse here rather than sending a request we know the server will refuse.
    const sections = (sectionsByDivision.value.get(id) ?? []).length
    if (sections > 0) {
      throw new Error(`${current.name} still has ${sections} section${sections === 1 ? '' : 's'}.`)
    }
    const assets = equipment.value.filter((e) => e.divisionId === id || e.sectionId === id).length
    if (assets > 0) {
      throw new Error(
        `${current.name} still has ${assets} asset${assets === 1 ? '' : 's'} assigned to it.`,
      )
    }

    return removed('offices', id)
  }

  /* ----------------------------- categories --------------------------- */

  async function createCategory(input) {
    const ts = nowIso()
    return created('categories', {
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
  }

  async function updateCategory(id, patch) {
    const current = categoryById.value.get(id)
    if (!current) throw new Error('Category not found.')

    return saved('categories', id, {
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
  }

  /** Soft delete: the category moves to the archive, it is not destroyed. */
  const deleteCategory = (id) => removed('categories', id)

  /* ----------------------------- equipment ---------------------------- */

  async function createEquipment(input) {
    const assetTag = input.assetTag.trim()
    if (equipment.value.some((row) => row.assetTag === assetTag)) {
      throw new Error(`Asset tag "${assetTag}" is already in use.`)
    }

    // Government accountability: say so in the form rather than letting the
    // server reject it with a less specific message.
    const parIssuedTo = input.parIssuedTo?.trim() || null
    if (!parIssuedTo) throw new Error('The PAR must name the officer it is issued to.')

    const divisionId = input.divisionId || null
    if (!divisionId) throw new Error('A division is required to register equipment.')

    const sectionId = input.sectionId || null
    if (sectionId) {
      const section = officeById.value.get(sectionId)
      if (section && section.divisionId !== divisionId) {
        throw new Error(`${section.name} is not a section of the selected division.`)
      }
    }

    const propertyNumber = input.propertyNumber?.trim() || null
    if (!propertyNumber) throw new Error('A property number is required to register equipment.')
    if (equipment.value.some((row) => row.propertyNumber === propertyNumber)) {
      throw new Error(`Property number "${propertyNumber}" is already in use.`)
    }

    const dateAcquired = toDateOnly(input.dateAcquired)
    if (!dateAcquired) throw new Error('An acquisition date is required to register equipment.')

    const serialNumber = input.serialNumber?.trim() || null
    const monitorSerialNumber = input.monitorSerialNumber?.trim() || null

    // A desktop set is one asset with two serialised parts.
    const category = input.categoryId ? categoryById.value.get(input.categoryId) : null
    if (category?.requiresMonitorSerial) {
      if (!serialNumber) throw new Error(`${category.name} needs a system unit serial number.`)
      if (!monitorSerialNumber) throw new Error(`${category.name} needs a monitor serial number.`)
    }
    if (serialNumber && serialNumber === monitorSerialNumber) {
      throw new Error('The system unit and monitor cannot share a serial number.')
    }
    assertSerialsAreFree([serialNumber, monitorSerialNumber], null)

    const ts = nowIso()
    return created('equipment', {
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
      divisionId,
      sectionId,
      province: input.province?.trim() || null,
      parIssuedTo,
      subParIssuedTo: input.subParIssuedTo?.trim() || null,
      actualUser: input.actualUser?.trim() || null,
      position: input.position?.trim() || null,
      adUsername: input.adUsername?.trim() || null,
      anydeskNumber: input.anydeskNumber?.trim() || null,
      specifications: input.specifications ?? {},
      remarks: input.remarks?.trim() || null,
      createdAt: ts,
      updatedAt: ts,
      deletedAt: null,
    })
  }

  async function updateEquipment(id, patch) {
    const current = equipmentById.value.get(id)
    if (!current) throw new Error('Equipment not found.')

    const doc = { ...current, ...patch, updatedAt: nowIso() }

    // The picker hands back a Date; the wire format is a calendar date string.
    if (patch.dateAcquired !== undefined) doc.dateAcquired = toDateOnly(patch.dateAcquired)

    for (const key of ['assetTag', 'modelName']) {
      if (typeof doc[key] === 'string') doc[key] = doc[key].trim()
    }
    for (const key of [
      'serialNumber',
      'brand',
      'location',
      'province',
      'propertyNumber',
      'parIssuedTo',
      'subParIssuedTo',
      'monitorSerialNumber',
      'remarks',
      'actualUser',
      'position',
      'adUsername',
      'anydeskNumber',
    ]) {
      if (typeof doc[key] === 'string') doc[key] = doc[key].trim() || null
    }

    if (doc.serialNumber && doc.serialNumber === doc.monitorSerialNumber) {
      throw new Error('The system unit and monitor cannot share a serial number.')
    }
    assertSerialsAreFree([doc.serialNumber, doc.monitorSerialNumber], id)

    // An edit must never silently drop the accountable document.
    if (current.parIssuedTo && !doc.parIssuedTo) doc.parIssuedTo = current.parIssuedTo
    if (current.divisionId && !doc.divisionId) doc.divisionId = current.divisionId
    if (current.propertyNumber && !doc.propertyNumber) doc.propertyNumber = current.propertyNumber
    if (current.dateAcquired && !doc.dateAcquired) doc.dateAcquired = current.dateAcquired

    return saved('equipment', id, doc)
  }

  /** Soft delete: the asset moves to the archive, where it can be destroyed. */
  const deleteEquipment = (id) => removed('equipment', id)

  /** Asset-tag / property-number / serial / QR lookup used by the scanner. */
  function findByCode(code) {
    const trimmed = code.trim()
    if (!trimmed) return null

    const match = equipment.value.find(
      (row) =>
        row.assetTag === trimmed ||
        row.propertyNumber === trimmed ||
        row.serialNumber === trimmed ||
        row.monitorSerialNumber === trimmed,
    )
    if (match) return match

    // QR payloads are minted as `ICT:<uuid>` by the label generator.
    const id = trimmed.startsWith('ICT:') ? trimmed.slice(4) : trimmed
    return equipmentById.value.get(id) ?? null
  }

  /* ----------------------------- borrowing ---------------------------- */

  function resolveLoanDays(equipmentDoc, override) {
    if (override !== undefined && override !== null) return Number(override)
    if (!equipmentDoc?.categoryId) return 7
    return categoryById.value.get(equipmentDoc.categoryId)?.defaultLoanDays ?? 7
  }

  async function checkout(input) {
    const asset = equipmentById.value.get(input.equipmentId)
    if (!asset) throw new Error('Equipment not found.')
    // Permanently issued, under repair, written off — out of the loan pool.
    if (!canBeBorrowed(asset.status)) {
      throw new Error(`${asset.assetTag} ${explainNotBorrowable(asset.status)}.`)
    }
    if (asset.status !== 'AVAILABLE') {
      throw new Error(
        `${asset.assetTag} is currently ${asset.status.toLowerCase()} and cannot be issued.`,
      )
    }

    const loanDays = resolveLoanDays(asset, input.loanDays)
    const issueDate = input.issueDate ?? nowIso()
    const ts = nowIso()

    const tx = await created('borrowings', {
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

    // The server sets the asset's status from the loan it just accepted — and
    // may refuse to, if another operator booked the same window first. Read it
    // back rather than assuming BORROWED.
    await reload('equipment')
    return tx
  }

  const requestLoan = (input) => checkout({ ...input, status: 'REQUESTED' })

  async function setBorrowingStatus(id, status) {
    const current = borrowings.value.find((tx) => tx.id === id)
    if (!current) throw new Error('Transaction not found.')

    const doc = await saved('borrowings', id, { ...current, status, updatedAt: nowIso() })
    await reload('equipment')
    return doc
  }

  const approveRequest = (id) => setBorrowingStatus(id, 'ISSUED')

  /**
   * Return inspection (US-3.3). Condition drives the asset's next status:
   * DAMAGED/POOR routes to MAINTENANCE, otherwise back to AVAILABLE.
   */
  async function processReturn(input) {
    const current = borrowings.value.find((tx) => tx.id === input.transactionId)
    if (!current) throw new Error('Transaction not found.')

    const inspection = input.inspectionNotes?.trim()
    await saved('borrowings', current.id, {
      ...current,
      status: 'RETURNED',
      actualReturnDate: input.actualReturnDate ?? nowIso(),
      notes: inspection
        ? [current.notes, `[Return inspection] ${inspection}`].filter(Boolean).join('\n')
        : current.notes,
      updatedAt: nowIso(),
    })

    if (current.equipmentId) {
      // Closing the loan frees the asset server-side; this is the inspector's
      // verdict on top of that, which the server has no way to know.
      const nextStatus = input.markLost
        ? 'LOST'
        : ['DAMAGED', 'POOR'].includes(input.condition)
          ? 'MAINTENANCE'
          : 'AVAILABLE'
      await updateEquipment(current.equipmentId, {
        status: nextStatus,
        condition: input.condition,
      })
    } else {
      await reload('equipment')
    }
  }

  /** Promotes past-due active loans to OVERDUE (US-3.4). */
  async function sweepOverdue() {
    const candidates = borrowings.value.filter(
      (tx) => ['ISSUED', 'APPROVED'].includes(tx.status) && isOverdue(tx),
    )

    for (const tx of candidates) {
      await setBorrowingStatus(tx.id, 'OVERDUE')
    }
    return candidates
  }

  /* ------------------------------- archive ---------------------------- */

  const archive = ref({ equipment: [], categories: [], offices: [], borrowings: [] })
  const archiveLoading = ref(false)

  const archivedCount = computed(
    () => Object.values(archive.value).reduce((sum, rows) => sum + rows.length, 0),
  )

  async function loadArchive() {
    archiveLoading.value = true
    try {
      archive.value = await api.archive()
    } finally {
      archiveLoading.value = false
    }
  }

  /**
   * Puts an archived asset back into the live register.
   *
   * The counterpart to the soft delete on the equipment screen, and the reason
   * archiving is safe to do: it is undoable by anyone who can write inventory.
   */
  async function restoreEquipment(id) {
    const result = await api.restoreEquipment(id)
    archive.value = {
      ...archive.value,
      equipment: archive.value.equipment.filter((row) => row.id !== id),
    }
    absorb('equipment', result.data)
    return result
  }

  /**
   * Destroys an archived asset permanently. Admin-only, and enforced as such
   * by the API — this is the one action in the system with no undo.
   */
  async function purgeEquipment(id) {
    const result = await api.purgeEquipment(id)
    archive.value = {
      ...archive.value,
      equipment: archive.value.equipment.filter((row) => row.id !== id),
    }
    // A purge detaches loan rows from the asset they referenced.
    await reload('borrowings')
    return result
  }

  return {
    offices,
    officeById,
    officeLabel,
    divisions,
    sectionsByDivision,
    createOffice,
    updateOffice,
    deleteOffice,
    categories,
    equipment,
    borrowings,
    loading,
    error,
    lastLoadedAt,
    categoryById,
    equipmentById,
    activeLoans,
    overdueLoans,
    pendingRequests,
    conflicts,
    stats,
    start,
    stop,
    refresh,
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
    archive,
    archiveLoading,
    archivedCount,
    loadArchive,
    restoreEquipment,
    purgeEquipment,
  }
})
