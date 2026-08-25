/**
 * Shared vocabulary. Mirrors `api/app/Domain/InventoryStatus.php` — if you change
 * one, change the other.
 */

export const EQUIPMENT_STATUSES = [
  'AVAILABLE',
  'BORROWED',
  'MAINTENANCE',
  'DECOMMISSIONED',
  'LOST',
]

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
  AVAILABLE: ['BORROWED', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
  BORROWED: ['AVAILABLE', 'MAINTENANCE', 'LOST'],
  MAINTENANCE: ['AVAILABLE', 'DECOMMISSIONED', 'LOST'],
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
