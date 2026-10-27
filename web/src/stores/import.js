import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, ApiError } from '@/api/client'

/**
 * Bulk CSV import, one profile per kind of ICT equipment.
 *
 * The store holds a two-step flow, and the steps are deliberately not
 * collapsible: `preview` validates the file and reports every row's verdict
 * without writing, and only `commit` writes. The same FormData is sent to
 * both, so what the operator approved is what gets imported.
 *
 * The report lives here rather than in the view because it survives a profile
 * switch — an operator who previews a laptop file, looks at the desktop
 * columns, then comes back has not lost their results.
 */
export const useImportStore = defineStore('import', () => {
  const profiles = ref([])
  const maxRows = ref(0)
  const profileName = ref(null)
  const report = ref(null)
  const loading = ref(false)
  const committing = ref(false)
  const error = ref(null)

  /** The defaults applied to rows that leave a column blank. */
  const defaults = ref({
    division: null,
    section: null,
    province: null,
    status: 'ISSUED',
    condition: null,
    parFallback: false,
  })

  const file = ref(null)

  /**
   * Cell edits made on the review step, keyed by line then column label.
   *
   * They are sent with the file and applied server-side, so a corrected row is
   * read and validated again exactly as it was the first time. Nothing here is
   * a finished record — correcting a cell cannot talk the server out of a rule.
   */
  const corrections = ref({})

  const correctionCount = computed(() =>
    Object.values(corrections.value).reduce((n, cells) => n + Object.keys(cells).length, 0),
  )

  const profile = computed(
    () => profiles.value.find((p) => p.name === profileName.value) ?? null,
  )

  /** Only the columns the template offers; the combined ones are not advertised. */
  const templateColumns = computed(
    () => profile.value?.columns.filter((column) => column.inTemplate) ?? [],
  )

  const requiredColumns = computed(
    () => templateColumns.value.filter((column) => column.required),
  )

  /**
   * What the report on screen was produced from.
   *
   * Corrections and the form defaults both change the outcome, so a report
   * taken before either changed no longer describes what importing would do.
   * Comparing against this is what lets the screen insist on a re-check —
   * `correctionCount > 0` cannot, because corrections stay in the request and
   * would keep the button disabled forever after the first re-check.
   */
  const previewedWith = ref(null)

  const signature = computed(() =>
    JSON.stringify({ defaults: defaults.value, corrections: corrections.value }),
  )

  const stale = computed(
    () => !!report.value && !report.value.committed && previewedWith.value !== signature.value,
  )

  /** Whether the previewed report has anything worth writing. */
  const canCommit = computed(
    () =>
      !!report.value &&
      !report.value.fatal &&
      !report.value.committed &&
      !stale.value &&
      report.value.summary.ready > 0,
  )

  async function load() {
    if (profiles.value.length) return

    loading.value = true
    try {
      const response = await api.importProfiles()
      profiles.value = response.data ?? []
      maxRows.value = response.maxRows ?? 0
      profileName.value ??= profiles.value[0]?.name ?? null
    } catch (err) {
      error.value = describe(err)
      throw err
    } finally {
      loading.value = false
    }
  }

  function selectProfile(name) {
    if (name === profileName.value) return
    profileName.value = name
    // The report describes a file read against the previous profile's columns.
    report.value = null
  }

  function selectFile(selected) {
    file.value = selected ?? null
    report.value = null
    error.value = null
    // Corrections are keyed by line number, which means nothing once the file
    // changes — keeping them would silently rewrite unrelated rows.
    corrections.value = {}
  }

  function reset() {
    file.value = null
    report.value = null
    error.value = null
    corrections.value = {}
  }

  /** Records an edit, or drops it when the value matches the file again. */
  function correct(line, column, value, original) {
    const cells = { ...(corrections.value[line] ?? {}) }

    if (value === (original ?? '')) delete cells[column]
    else cells[column] = value

    const next = { ...corrections.value }
    if (Object.keys(cells).length) next[line] = cells
    else delete next[line]

    corrections.value = next
  }

  function clearCorrections(line = null) {
    if (line === null) {
      corrections.value = {}
      return
    }

    const next = { ...corrections.value }
    delete next[line]
    corrections.value = next
  }

  function body() {
    const form = new FormData()
    form.append('file', file.value)

    for (const [key, value] of Object.entries(defaults.value)) {
      if (value === null || value === '' || value === false) continue
      form.append(key, value === true ? '1' : value)
    }

    if (correctionCount.value) {
      form.append('corrections', JSON.stringify(corrections.value))
    }

    return form
  }

  async function preview() {
    if (!file.value) throw new Error('Choose a CSV file first.')

    loading.value = true
    error.value = null
    report.value = null

    const sent = signature.value

    try {
      report.value = await api.previewImport(profileName.value, body())
      previewedWith.value = sent
      return report.value
    } catch (err) {
      // A fatal report is a 422 whose body is still the full report: the
      // header problems are in it, and they are what the operator needs.
      if (err instanceof ApiError && err.status === 422 && err.body?.headers) {
        report.value = err.body
        previewedWith.value = sent
        return report.value
      }
      error.value = describe(err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function commit() {
    if (!canCommit.value) throw new Error('Preview the file first.')

    committing.value = true
    error.value = null

    try {
      report.value = await api.importEquipment(profileName.value, body())
      return report.value
    } catch (err) {
      error.value = describe(err)
      throw err
    } finally {
      committing.value = false
    }
  }

  /**
   * Downloads the sample CSV.
   *
   * The file arrives as a blob because the endpoint sits behind the bearer
   * token, so it is handed to a temporary object URL rather than a plain link.
   */
  async function downloadTemplate(headersOnly = false) {
    const { blob, filename } = await api.importTemplate(profileName.value, headersOnly)

    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)

    return filename
  }

  function describe(err) {
    if (err instanceof ApiError) return err.firstValidationError() ?? err.message
    return err?.message ?? 'Something went wrong.'
  }

  return {
    profiles,
    maxRows,
    profileName,
    profile,
    templateColumns,
    requiredColumns,
    report,
    defaults,
    file,
    corrections,
    correctionCount,
    stale,
    loading,
    committing,
    error,
    canCommit,
    load,
    selectProfile,
    selectFile,
    reset,
    correct,
    clearCorrections,
    preview,
    commit,
    downloadTemplate,
  }
})
