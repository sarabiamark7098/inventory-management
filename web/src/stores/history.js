import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, ApiError } from '@/api/client'

/**
 * The activity history.
 *
 * Kept out of the inventory store deliberately. Everything there is a working
 * copy of the whole collection, loaded once and filtered in the browser; the log
 * grows without bound and is filtered and paged by the server, so the two have
 * opposite shapes. Sharing a store would mean one of them pretending.
 */
export const useHistoryStore = defineStore('history', () => {
  const entries = ref([])
  const meta = ref({ page: 1, perPage: 50, total: 0, lastPage: 1 })
  const options = ref({ actors: [], entityTypes: [], operations: [] })
  const loading = ref(false)
  const error = ref(null)

  /** What the user has narrowed to. `null` everywhere means "everything". */
  const filters = ref({
    search: '',
    entityType: null,
    operation: null,
    actor: null,
    from: null,
    to: null,
  })

  const hasFilters = computed(() =>
    Boolean(
      filters.value.search ||
        filters.value.entityType ||
        filters.value.operation ||
        filters.value.actor ||
        filters.value.from ||
        filters.value.to,
    ),
  )

  /**
   * A date the server can compare against a UTC timestamp.
   *
   * `toISOString()` would be wrong here: at UTC+8 it turns the 15th into the
   * 14th, so "everything up to today" would silently drop today's activity.
   */
  function toDateParam(value) {
    if (!value) return null
    const d = new Date(value)
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
  }

  async function load(page = 1) {
    loading.value = true
    error.value = null
    try {
      const response = await api.history({
        page,
        perPage: meta.value.perPage,
        search: filters.value.search.trim() || null,
        entityType: filters.value.entityType,
        operation: filters.value.operation,
        actor: filters.value.actor,
        from: toDateParam(filters.value.from),
        to: toDateParam(filters.value.to),
      })

      entries.value = response.data ?? []
      meta.value = response.meta ?? meta.value
      options.value = response.filters ?? options.value
    } catch (err) {
      error.value = err instanceof ApiError ? (err.firstValidationError() ?? err.message) : err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /** Any filter change resets to page 1 — page 4 of the old result is meaningless. */
  const applyFilters = () => load(1)

  function clearFilters() {
    filters.value = {
      search: '',
      entityType: null,
      operation: null,
      actor: null,
      from: null,
      to: null,
    }
    return load(1)
  }

  /** One entry with its field-level changes, fetched on demand. */
  const entry = (id) => api.historyEntry(id).then((r) => r.entry)

  function reset() {
    entries.value = []
    meta.value = { page: 1, perPage: 50, total: 0, lastPage: 1 }
    options.value = { actors: [], entityTypes: [], operations: [] }
    error.value = null
  }

  return {
    entries,
    meta,
    options,
    filters,
    hasFilters,
    loading,
    error,
    load,
    applyFilters,
    clearFilters,
    entry,
    reset,
  }
})
