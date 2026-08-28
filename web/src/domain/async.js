/**
 * Timing helpers for work that gets triggered faster than it can usefully run.
 *
 * Three different problems live here, and they are not interchangeable — using
 * the wrong one is how a "fix" for repeated work turns into a stale screen:
 *
 * - `debounce`  — a human is driving. Wait until they stop.
 * - `coalesce`  — the same reload was asked for twice while one was running.
 *                 Share the run rather than doubling the traffic.
 * - `sequencer` — several runs are legitimately in flight. Make sure the newest
 *                 one wins regardless of what order the answers come back in.
 */

/**
 * Delays `fn` until `ms` has passed without another call.
 *
 * Only the last set of arguments is used, which is the point: while someone is
 * typing, every intermediate value is a value they are already done with.
 */
export function debounce(fn, ms = 250) {
  let timer = null

  const debounced = (...args) => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      timer = null
      fn(...args)
    }, ms)
  }

  /** Drop a pending call — on unmount, or when something else supersedes it. */
  debounced.cancel = () => {
    if (timer) clearTimeout(timer)
    timer = null
  }

  /** Run the pending call now instead of waiting out the delay. */
  debounced.flush = (...args) => {
    debounced.cancel()
    fn(...args)
  }

  debounced.pending = () => timer !== null

  return debounced
}

/**
 * Collapses overlapping calls of an async function into a single run.
 *
 * Callers that arrive while one is in flight get that same promise, so they
 * still await a real result — they just do not start a second copy of the work.
 *
 * This is for reloads triggered by events rather than by typing: a reconnect
 * and a click on "Refresh" landing together should not fetch the whole dataset
 * twice, and debouncing them would only add a delay to something that already
 * needed to happen once.
 */
export function coalesce(fn) {
  let inFlight = null

  const run = (...args) => {
    if (inFlight) return inFlight

    inFlight = Promise.resolve()
      .then(() => fn(...args))
      .finally(() => {
        inFlight = null
      })

    return inFlight
  }

  run.busy = () => inFlight !== null

  return run
}

/**
 * Hands each call a token that reports whether it is still the newest.
 *
 * Debouncing reduces overlapping requests but cannot prevent them: a slow
 * request issued at keystroke three can still land after the fast one issued at
 * keystroke five. Without this, the screen would settle on the older answer and
 * show results for a search the box no longer contains.
 *
 *     const nextLoad = sequencer()
 *
 *     async function load() {
 *       const isCurrent = nextLoad()
 *       const rows = await api.list()
 *       if (!isCurrent()) return   // a newer load owns the screen now
 *       items.value = rows
 *     }
 */
export function sequencer() {
  let generation = 0

  return () => {
    const mine = ++generation
    return () => mine === generation
  }
}
