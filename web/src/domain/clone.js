/**
 * Deep-copies a value read out of a Pinia store before it is edited in a form.
 *
 * `structuredClone` cannot be used here: everything in the store is wrapped in
 * Vue reactive Proxies, and the structured clone algorithm rejects a Proxy with
 * `DataCloneError`. That failure is silent in an event handler — it throws
 * before the dialog is ever opened — so it must not creep back in.
 *
 * A JSON round-trip is safe for this data specifically: every value here is
 * synced over HTTP as JSON, so it is already limited to JSON-representable
 * types. Dates are ISO strings by convention (see `domain/loan.js`).
 */
export function deepClone(value, fallback = null) {
  if (value === null || value === undefined) return fallback

  try {
    return JSON.parse(JSON.stringify(value))
  } catch {
    return fallback
  }
}
