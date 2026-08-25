import { ACTIVE_LOAN_STATUSES } from './constants'

const MS_PER_DAY = 24 * 60 * 60 * 1000

/** Auto-calculated return date from the category default loan duration (US-1.3). */
export function calculateExpectedReturn(issueDate, loanDays) {
  const start = issueDate instanceof Date ? issueDate : new Date(issueDate)
  return new Date(start.getTime() + Math.max(0, loanDays) * MS_PER_DAY)
}

export function isActiveLoan(status) {
  return ACTIVE_LOAN_STATUSES.includes(status)
}

export function isOverdue(tx, now = new Date()) {
  if (!isActiveLoan(tx.status)) return false
  return new Date(tx.expectedReturnDate).getTime() < now.getTime()
}

/** Negative once the loan is past due. */
export function daysUntilDue(expectedReturnDate, now = new Date()) {
  return Math.ceil((new Date(expectedReturnDate).getTime() - now.getTime()) / MS_PER_DAY)
}

export function loansOverlap(a, b) {
  const aStart = new Date(a.issueDate).getTime()
  const aEnd = new Date(a.expectedReturnDate).getTime()
  const bStart = new Date(b.issueDate).getTime()
  const bEnd = new Date(b.expectedReturnDate).getTime()
  return aStart <= bEnd && bStart <= aEnd
}

export function uuid() {
  const c = globalThis.crypto
  if (c?.randomUUID) return c.randomUUID()

  // Fallback for insecure (plain-HTTP LAN) contexts where randomUUID is absent.
  const bytes = new Uint8Array(16)
  if (c?.getRandomValues) c.getRandomValues(bytes)
  else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)

  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'))
  return [
    hex.slice(0, 4).join(''),
    hex.slice(4, 6).join(''),
    hex.slice(6, 8).join(''),
    hex.slice(8, 10).join(''),
    hex.slice(10, 16).join(''),
  ].join('-')
}

export function nowIso() {
  return new Date().toISOString()
}

export function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString()
}

export function formatDateTime(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

/**
 * Formats a Date as `YYYY-MM-DD` using its **local** parts.
 *
 * `toISOString()` converts to UTC first, which in Philippine time (UTC+8) turns
 * a date picked as the 15th into the 14th. Acquisition dates are calendar days,
 * so they must never move.
 */
export function toDateOnly(value) {
  if (!value) return null
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return null
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Parses `YYYY-MM-DD` back into a local Date for the date picker. */
export function fromDateOnly(value) {
  if (!value) return null
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return null
  return new Date(y, m - 1, d)
}
