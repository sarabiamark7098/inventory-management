/**
 * Organisation email policy, mirrored from the API's
 * `config/accounts.allowed_email_domains`.
 *
 * This exists purely so the UI can say "use your @dswd.gov.ph address" before a
 * round-trip. The API enforces the same rule and is the only thing that counts —
 * never treat this as a security control.
 */
const configured = (import.meta.env.VITE_ALLOWED_EMAIL_DOMAINS ?? '')
  .split(',')
  .map((domain) => domain.trim().toLowerCase())
  .filter(Boolean)

export const ALLOWED_EMAIL_DOMAINS = configured

export function isAllowedEmail(email) {
  const value = String(email ?? '').trim().toLowerCase()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return false
  if (ALLOWED_EMAIL_DOMAINS.length === 0) return true

  const domain = value.slice(value.lastIndexOf('@') + 1)
  return ALLOWED_EMAIL_DOMAINS.includes(domain)
}

/** "@dswd.gov.ph" or "@a.gov.ph or @b.gov.ph" */
export function describeAllowedDomains() {
  const labelled = ALLOWED_EMAIL_DOMAINS.map((d) => `@${d}`)
  if (labelled.length === 0) return ''
  if (labelled.length === 1) return labelled[0]
  return `${labelled.slice(0, -1).join(', ')} or ${labelled.at(-1)}`
}

export function emailPlaceholder() {
  return ALLOWED_EMAIL_DOMAINS.length > 0
    ? `you@${ALLOWED_EMAIL_DOMAINS[0]}`
    : 'you@organisation.org'
}
