import { onScopeDispose, ref, watch } from 'vue'

import { debounce } from '@/domain/async'

/**
 * A read-only ref that trails `source`, settling once it stops changing.
 *
 * Bind the input to the source ref so typing stays instant, and filter on the
 * value this returns so the list is only rebuilt once the typing stops. On the
 * screens that filter in memory, the request is not the expensive part — the
 * filter pass plus a full re-render of a table of several hundred rows is, and
 * that is what runs on every keystroke otherwise.
 *
 * Clearing is not debounced. Emptying the box means "show me everything again",
 * and making someone wait for that reads as a hung UI rather than a considered
 * delay.
 */
export function useDebounced(source, ms = 200) {
  const settled = ref(source.value)

  const push = debounce((value) => {
    settled.value = value
  }, ms)

  watch(source, (value) => {
    if (value === '' || value === null || value === undefined) {
      push.cancel()
      settled.value = value
      return
    }
    push(value)
  })

  onScopeDispose(() => push.cancel())

  return settled
}
