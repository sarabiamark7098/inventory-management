import { onBeforeUnmount, ref } from 'vue'

/**
 * Reactive media query.
 *
 * Used to swap wide data tables for card lists on phones. Doing that with a
 * real breakpoint rather than CSS alone means the table markup is never even
 * rendered on a small screen, so there is nothing to scroll sideways.
 */
export function useMediaQuery(query) {
  const matches = ref(false)

  if (typeof window !== 'undefined' && window.matchMedia) {
    const list = window.matchMedia(query)
    matches.value = list.matches

    const onChange = (event) => {
      matches.value = event.matches
    }
    list.addEventListener('change', onChange)
    onBeforeUnmount(() => list.removeEventListener('change', onChange))
  }

  return matches
}

/** Phones and small tablets in portrait. */
export function useIsMobile() {
  return useMediaQuery('(max-width: 767px)')
}
