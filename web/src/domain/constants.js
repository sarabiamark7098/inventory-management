/**
 * Shared vocabulary. Mirrors `api/app/Domain/InventoryStatus.php` — if you change
 * one, change the other.
 */

export const EQUIPMENT_STATUSES = [
  'AVAILABLE',
  'BORROWED',
  'ISSUED',
  'MAINTENANCE',
  'DECOMMISSIONED',
  'LOST',
]

/**
 * Equipment states that put an asset out of the loan pool entirely.
 *
 * `ISSUED` means permanently handed to a named user on a PAR — a staff member's
 * own workstation, not shared stock — so it can never be booked out. `BORROWED`
 * is merely busy until the loan ends, so a future loan is still legitimate.
 *
 * Note the collision: `ISSUED` is also a *loan* status meaning "handed over for
 * this loan". Separate vocabularies; never compare one to the other.
 */
export const NON_BORROWABLE_STATUSES = ['ISSUED', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST']

export function canBeBorrowed(equipmentStatus) {
  return !NON_BORROWABLE_STATUSES.includes(equipmentStatus)
}

export function explainNotBorrowable(equipmentStatus) {
  return (
    {
      ISSUED: 'is permanently issued to a user and is not part of the loan pool',
      MAINTENANCE: 'is under maintenance',
      DECOMMISSIONED: 'has been decommissioned',
      LOST: 'is recorded as lost',
    }[equipmentStatus] ?? 'cannot be borrowed'
  )
}

export const EQUIPMENT_CONDITIONS = ['NEW', 'GOOD', 'FAIR', 'POOR', 'DAMAGED']

export const BORROWING_STATUSES = [
  'REQUESTED',
  'APPROVED',
  'ISSUED',
  'RETURNED',
  'OVERDUE',
  'REJECTED',
  'CANCELLED',
  'CONFLICT_PENDING_REVIEW',
]

/** Loan states that physically hold a device. */
export const ACTIVE_LOAN_STATUSES = ['APPROVED', 'ISSUED', 'OVERDUE']

export const CUSTOM_FIELD_TYPES = ['text', 'number', 'boolean', 'date', 'select']

/**
 * Legal equipment status transitions (US-2.3). Same-to-same is always allowed so
 * idempotent syncs never trip validation.
 */
export const EQUIPMENT_TRANSITIONS = {
  AVAILABLE: ['BORROWED', 'ISSUED', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
  // An asset on loan must be returned before it can be permanently issued.
  BORROWED: ['AVAILABLE', 'MAINTENANCE', 'LOST'],
  ISSUED: ['AVAILABLE', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
  MAINTENANCE: ['AVAILABLE', 'ISSUED', 'DECOMMISSIONED', 'LOST'],
  DECOMMISSIONED: ['AVAILABLE'],
  LOST: ['AVAILABLE', 'MAINTENANCE', 'DECOMMISSIONED'],
}

export function canTransitionEquipment(from, to) {
  if (from === to) return true
  return (EQUIPMENT_TRANSITIONS[from] ?? []).includes(to)
}

/** PrimeVue Tag severities, so status colour is decided in exactly one place. */
export const EQUIPMENT_STATUS_SEVERITY = {
  AVAILABLE: 'success',
  BORROWED: 'info',
  ISSUED: 'contrast',
  MAINTENANCE: 'warn',
  DECOMMISSIONED: 'secondary',
  LOST: 'danger',
}

export const CONDITION_SEVERITY = {
  NEW: 'success',
  GOOD: 'success',
  FAIR: 'warn',
  POOR: 'warn',
  DAMAGED: 'danger',
}

export const BORROWING_STATUS_SEVERITY = {
  REQUESTED: 'warn',
  APPROVED: 'info',
  ISSUED: 'info',
  RETURNED: 'success',
  OVERDUE: 'danger',
  REJECTED: 'secondary',
  CANCELLED: 'secondary',
  CONFLICT_PENDING_REVIEW: 'contrast',
}

export const BORROWING_STATUS_LABEL = {
  CONFLICT_PENDING_REVIEW: 'CONFLICT',
}

/**
 * Government property accountability (Philippine public-sector inventory).
 *
 * PAR (Property Acknowledgement Receipt) names the officer accountable for an
 * asset; a Sub-PAR covers items reassigned onward from that officer, so it is
 * optional. Both are recorded per asset and are what auditors reconcile against.
 *
 * The lists below are suggestions for the UI only — the API stores whatever
 * string is sent, so an office can type a unit that is not listed here.
 */
export const PROVINCES = [
  'Davao de Oro',
  'Davao del Norte',
  'Davao del Sur',
  'Davao Occidental',
  'Davao Oriental',
  'Davao City',
]

export const DIVISIONS = [
  'Office of the Regional Director',
  'Administrative Division',
  'Financial Management Division',
  'Policy and Plans Division',
  'Promotive Services Division',
  'Protective Services Division',
  'Disaster Response Management Division',
]

export const SECTIONS = [
  'Information and Communications Technology Section',
  'General Services Section',
  'Human Resource Management and Development Section',
  'Property and Supply Section',
  'Records and Archives Section',
  'Accounting Section',
  'Budget Section',
  'Cash Section',
  'Social Marketing Section',
  'Planning and Monitoring Section',
]
