<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import { useImportStore } from '@/stores/import'
import { useInventoryStore } from '@/stores/inventory'
import { useIsMobile } from '@/composables/useBreakpoint'
import { EQUIPMENT_STATUSES, EQUIPMENT_CONDITIONS, PROVINCES } from '@/domain/constants'

const store = useImportStore()
const inventory = useInventoryStore()
const toast = useToast()
const isMobile = useIsMobile()

const fileInput = ref(null)
const columnsOpen = ref(false)

onMounted(() => store.load().catch(report))

function report(err) {
  toast.add({
    severity: 'error',
    summary: 'Import failed',
    detail: err?.message ?? 'Unknown error',
    life: 7000,
  })
}

/* ------------------------------- the form ------------------------------- */

const divisionOptions = computed(() =>
  inventory.divisions.map((d) => ({ label: d.name, value: d.code ?? d.id })),
)

const sectionOptions = computed(() => {
  const division = inventory.divisions.find(
    (d) => (d.code ?? d.id) === store.defaults.division,
  )
  if (!division) return []

  return (inventory.sectionsByDivision.get(division.id) ?? []).map((s) => ({
    label: s.name,
    value: s.code ?? s.id,
  }))
})

// A section belongs to one division, so changing the division invalidates it.
watch(
  () => store.defaults.division,
  () => {
    store.defaults.section = null
  },
)

function pickFile(event) {
  store.selectFile(event.target.files?.[0] ?? null)
}

function clearFile() {
  store.reset()
  if (fileInput.value) fileInput.value.value = ''
}

async function download(headersOnly) {
  try {
    const name = await store.downloadTemplate(headersOnly)
    toast.add({ severity: 'success', summary: `Downloaded ${name}`, life: 4000 })
  } catch (err) {
    report(err)
  }
}

async function preview() {
  try {
    const result = await store.preview()
    if (result.fatal) return

    toast.add({
      severity: result.summary.ready ? 'info' : 'warn',
      summary: `${result.summary.ready} of ${records(result)} rows ready`,
      detail: 'Nothing has been saved yet. Review the rows, then import.',
      life: 6000,
    })
  } catch (err) {
    report(err)
  }
}

/* ------------------------------ corrections ----------------------------- */

const editing = ref(null)
const draft = ref({})
const showEveryField = ref(false)

/** The value in force for a cell: the operator's edit, else the file's. */
function current(row, label) {
  return row.corrected?.[label] ?? row.cells?.[label] ?? ''
}

function openEditor(row) {
  editing.value = row
  draft.value = Object.fromEntries(
    store.templateColumns.map((column) => [column.label, current(row, column.label)]),
  )
  showEveryField.value = false
}

/**
 * The fields the editor offers.
 *
 * By default the ones this row's errors actually point at, plus whatever is
 * already corrected and the required columns — a desktop has 37 columns, and
 * showing all of them to fix one date is how an operator misses the date.
 */
const editorFields = computed(() => {
  if (!editing.value) return []
  if (showEveryField.value) return store.templateColumns

  const wanted = new Set([
    ...(editing.value.fix ?? []),
    ...Object.keys(editing.value.corrected ?? {}),
  ])

  return store.templateColumns.filter(
    (column) => wanted.has(column.label) || column.required,
  )
})

function saveEdits() {
  const row = editing.value

  for (const column of store.templateColumns) {
    store.correct(
      row.line,
      column.label,
      draft.value[column.label] ?? '',
      row.cells?.[column.label] ?? '',
    )
  }

  editing.value = null
}

function resetRow(row) {
  store.clearCorrections(row.line)
  if (editing.value?.line === row.line) editing.value = null
}

async function recheck() {
  try {
    const result = await store.preview()
    if (result.fatal) return

    toast.add({
      severity: result.summary.errors || result.summary.duplicates ? 'warn' : 'success',
      summary: `${result.summary.ready} of ${records(result)} rows ready`,
      detail: result.summary.errors || result.summary.duplicates
        ? 'Some rows still need attention.'
        : 'Everything checks out — step 5 will save them.',
      life: 6000,
    })
  } catch (err) {
    report(err)
  }
}

async function commit() {
  try {
    const result = await store.commit()
    toast.add({
      severity: result.summary.rejected ? 'warn' : 'success',
      summary: `Imported ${result.summary.applied} assets`,
      detail: result.summary.rejected
        ? `${result.summary.rejected} rows were refused — see the table.`
        : 'They are in the equipment register now.',
      life: 8000,
    })
    // The register has changed underneath the rest of the app.
    await inventory.refresh().catch(() => {})
  } catch (err) {
    report(err)
  }
}

/* -------------------------------- report -------------------------------- */

/**
 * Records, as opposed to lines.
 *
 * Padding rows are not records, and a workstation line can be three of them —
 * the machine, its printer and its scanner.
 */
function records(result) {
  return result.summary.total - result.summary.blank + (result.summary.derived ?? 0)
}

const visibleRows = computed(() => {
  const rows = store.report?.rows ?? []
  // Padding rows are counted but not listed: there can be 900 of them, and
  // none of them is something the operator has to do anything about.
  return rows.filter((row) => row.status !== 'BLANK')
})

const problemRows = computed(() =>
  visibleRows.value.filter((row) => row.errors.length || row.warnings.length),
)

const showAllRows = ref(false)

const rowsToShow = computed(() =>
  showAllRows.value ? visibleRows.value : problemRows.value,
)

const severity = {
  READY: 'info',
  IMPORTED: 'success',
  ERROR: 'danger',
  DUPLICATE: 'warn',
  BLANK: 'secondary',
}
</script>

<template>
  <section class="view">
    <header class="view__header">
      <div>
        <h1>Import</h1>
        <p class="muted">
          Bulk-register equipment from a CSV, with a column set per type of asset.
          Nothing is saved until you have seen what the file would do.
        </p>
      </div>
    </header>

    <ProgressSpinner v-if="store.loading && !store.profiles.length" style="width: 2.5rem" />

    <template v-else-if="store.profile">
      <!-- 1. the type -->
      <Card>
        <template #title>1. What are you importing?</template>
        <template #content>
          <SelectButton
            :model-value="store.profileName"
            :options="store.profiles"
            option-label="label"
            option-value="name"
            :allow-empty="false"
            aria-label="Equipment type"
            @update:model-value="store.selectProfile($event)"
          />
          <p class="muted spaced">{{ store.profile.description }}</p>

          <div class="actions">
            <Button
              label="Download sample CSV"
              icon="pi pi-download"
              size="small"
              @click="download(false)"
            />
            <Button
              label="Headers only"
              icon="pi pi-file"
              size="small"
              outlined
              severity="secondary"
              @click="download(true)"
            />
            <Button
              :label="columnsOpen ? 'Hide the columns' : 'What columns does it accept?'"
              :icon="columnsOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
              size="small"
              text
              @click="columnsOpen = !columnsOpen"
            />
          </div>

          <div v-if="columnsOpen" class="columns-doc">
            <p class="muted">
              Columns are matched <strong>by header name, not position</strong>, so you can
              reorder or leave out any of the optional ones. Spelling, spacing and
              capitalisation are ignored, and the source workbook's own headers are
              recognised as written.
            </p>
            <table class="doc-table">
              <thead>
                <tr>
                  <th>Header</th>
                  <th>Required</th>
                  <th>Also accepted as</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="column in store.templateColumns" :key="column.label">
                  <td>
                    <strong>{{ column.label }}</strong>
                    <span v-if="column.note" class="muted block">{{ column.note }}</span>
                  </td>
                  <td>
                    <Tag v-if="column.required" value="Required" severity="danger" />
                    <span v-else class="muted">Optional</span>
                  </td>
                  <td class="muted">{{ column.aliases.join(', ') || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </Card>

      <!-- 2. defaults -->
      <Card>
        <template #title>2. Fill the gaps</template>
        <template #content>
          <p class="muted">
            Applied only to rows that leave the column blank, so a file covering one
            province need not repeat its division on every line.
          </p>

          <div class="grid">
            <div class="field">
              <label for="default-division">Division</label>
              <Select
                id="default-division"
                v-model="store.defaults.division"
                :options="divisionOptions"
                option-label="label"
                option-value="value"
                placeholder="None"
                show-clear
                filter
                fluid
              />
            </div>
            <div class="field">
              <label for="default-section">Section</label>
              <Select
                id="default-section"
                v-model="store.defaults.section"
                :options="sectionOptions"
                option-label="label"
                option-value="value"
                :placeholder="store.defaults.division ? 'None' : 'Pick a division first'"
                :disabled="!store.defaults.division"
                show-clear
                filter
                fluid
              />
            </div>
            <div class="field">
              <label for="default-province">Province</label>
              <Select
                id="default-province"
                v-model="store.defaults.province"
                :options="PROVINCES"
                placeholder="None"
                show-clear
                editable
                fluid
              />
            </div>
            <div class="field">
              <label for="default-status">Status</label>
              <Select
                id="default-status"
                v-model="store.defaults.status"
                :options="EQUIPMENT_STATUSES"
                placeholder="AVAILABLE"
                show-clear
                fluid
              />
              <small class="muted">
                ISSUED suits a register of assets already handed to named staff — it
                keeps them out of the borrowing pool.
              </small>
            </div>
            <div class="field">
              <label for="default-condition">Condition</label>
              <Select
                id="default-condition"
                v-model="store.defaults.condition"
                :options="EQUIPMENT_CONDITIONS"
                placeholder="GOOD"
                show-clear
                fluid
              />
            </div>
          </div>

          <div class="checkbox-row">
            <Checkbox
              v-model="store.defaults.parFallback"
              input-id="par-fallback"
              binary
            />
            <label for="par-fallback">
              Use Actual User when PAR Issued To is blank
              <small class="muted block">
                The PAR names who is financially accountable, which is often not the
                person using the asset — so this is never applied on its own. Every row
                it touches is flagged in the preview.
              </small>
            </label>
          </div>
        </template>
      </Card>

      <!-- 3. the file -->
      <Card>
        <template #title>3. Choose the file</template>
        <template #content>
          <input
            ref="fileInput"
            type="file"
            accept=".csv,text/csv,text/plain"
            aria-label="CSV file to import"
            @change="pickFile"
          />

          <p v-if="store.file" class="muted spaced">
            <strong>{{ store.file.name }}</strong>
            — {{ (store.file.size / 1024).toFixed(1) }} KB
          </p>
          <p v-else class="muted spaced">
            Save your sheet as <strong>CSV UTF-8 (Comma delimited)</strong>. Up to
            {{ store.maxRows.toLocaleString() }} rows per file.
          </p>

          <div class="actions">
            <Button
              label="Preview"
              icon="pi pi-search"
              :disabled="!store.file"
              :loading="store.loading"
              @click="preview"
            />
            <Button
              v-if="store.file"
              label="Clear"
              icon="pi pi-times"
              text
              severity="secondary"
              @click="clearFile"
            />
          </div>

          <Message v-if="store.error" severity="error" :closable="false" class="spaced">
            {{ store.error }}
          </Message>
        </template>
      </Card>

      <!-- 4. review, and correct what needs it -->
      <Card v-if="store.report">
        <template #title>
          {{ store.report.committed ? 'What was imported' : '4. Review and correct' }}
        </template>
        <template #content>
          <Message v-if="store.report.fatal" severity="error" :closable="false">
            {{ store.report.fatal }}
          </Message>

          <template v-else>
            <Message
              v-if="store.report.hint"
              severity="warn"
              :closable="false"
              class="spaced"
            >
              {{ store.report.hint }}
            </Message>

            <Message
              v-if="store.report.truncated"
              severity="warn"
              :closable="false"
              class="spaced"
            >
              Only the first {{ store.maxRows.toLocaleString() }} rows were read. Split
              the file and import the rest separately.
            </Message>

            <dl class="tiles">
              <div>
                <dt>Records</dt>
                <dd>{{ records(store.report) }}</dd>
              </div>
              <div>
                <dt>{{ store.report.committed ? 'Imported' : 'Ready' }}</dt>
                <dd class="good">
                  {{ store.report.committed ? store.report.summary.applied : store.report.summary.ready }}
                </dd>
              </div>
              <div>
                <dt>Duplicates</dt>
                <dd :class="{ warn: store.report.summary.duplicates }">
                  {{ store.report.summary.duplicates }}
                </dd>
              </div>
              <div>
                <dt>Errors</dt>
                <dd :class="{ bad: store.report.summary.errors }">
                  {{ store.report.summary.errors }}
                </dd>
              </div>
              <div v-if="store.report.summary.derived">
                <dt>Printers &amp; scanners</dt>
                <dd>{{ store.report.summary.derived }}</dd>
              </div>
              <div v-if="store.report.summary.blank">
                <dt>Blank rows</dt>
                <dd class="muted">{{ store.report.summary.blank }}</dd>
              </div>
            </dl>

            <p v-if="store.report.summary.derived" class="muted">
              {{ store.report.summary.derived }} of these are printers and
              scanners named on a workstation's row. Each is registered in its
              own right, with its own property number, inheriting the division,
              the PAR holder and the acquisition date from the machine it came
              with.
            </p>

            <p v-if="store.report.summary.blank" class="muted">
              {{ store.report.summary.blank }} trailing rows held nothing identifying and
              were skipped — that is ordinary spreadsheet padding, not a problem.
            </p>

            <!-- header diagnostics -->
            <div class="diagnostics">
              <p>
                <i class="pi pi-check-circle good" />
                {{ store.report.headers.matched.length }} columns matched.
              </p>
              <p v-if="store.report.headers.unrecognised.length">
                <i class="pi pi-exclamation-triangle warn" />
                Not recognised, so <strong>not imported</strong>:
                <span class="mono">
                  {{ store.report.headers.unrecognised.map((h) => h.header).join(', ') }}
                </span>
              </p>
              <p v-if="store.report.headers.duplicate.length">
                <i class="pi pi-exclamation-triangle warn" />
                Repeated headers — the first of each was used:
                <span class="mono">
                  {{ store.report.headers.duplicate.map((h) => h.header).join(', ') }}
                </span>
              </p>
            </div>

            <Divider />

            <div class="actions">
              <Button
                v-if="visibleRows.length > problemRows.length"
                :label="showAllRows ? `Show only the ${problemRows.length} needing attention` : `Show all ${visibleRows.length} rows`"
                size="small"
                text
                @click="showAllRows = !showAllRows"
              />
              <Button
                v-if="store.correctionCount"
                :label="`Undo all ${store.correctionCount} corrections`"
                icon="pi pi-undo"
                size="small"
                text
                severity="secondary"
                @click="store.clearCorrections()"
              />
            </div>

            <Message
              v-if="store.report.rowsOmitted"
              severity="info"
              :closable="false"
              class="spaced"
            >
              Showing {{ visibleRows.length }} of
              {{ records(store.report).toLocaleString() }} records — everything
              needing attention, up to {{ store.report.rowLimit }}, plus a few
              clean rows as a sample. The counts above cover the whole file.
              <template v-if="store.report.summary.errors > store.report.rowLimit">
                With this many failing rows it is quicker to fix them in the
                spreadsheet than one at a time here.
              </template>
            </Message>

            <p v-if="!rowsToShow.length" class="muted spaced">
              <i class="pi pi-check-circle good" />
              Every row is clean.
            </p>

            <!-- rows: cards on a phone, a table on a desktop -->
            <div v-else-if="isMobile" class="cards">
              <article v-for="row in rowsToShow" :key="row.line" class="record-card">
                <header>
                  <strong>Line {{ row.line }} — {{ row.assetTag ?? '—' }}</strong>
                  <Tag :value="row.status" :severity="severity[row.status]" />
                </header>
                <p v-if="row.derivedFrom" class="muted">
                  Registered from the workstation on line {{ row.derivedFrom }}
                </p>
                <ul v-if="row.errors.length" class="issues bad">
                  <li v-for="(e, i) in row.errors" :key="i">{{ e }}</li>
                </ul>
                <ul v-if="row.warnings.length" class="issues warn">
                  <li v-for="(w, i) in row.warnings" :key="i">{{ w }}</li>
                </ul>
                <div v-if="!store.report.committed && !row.derivedFrom" class="actions">
                  <Button
                    label="Correct"
                    icon="pi pi-pencil"
                    size="small"
                    outlined
                    @click="openEditor(row)"
                  />
                  <Button
                    v-if="store.corrections[row.line]"
                    label="Undo"
                    icon="pi pi-undo"
                    size="small"
                    text
                    severity="secondary"
                    @click="resetRow(row)"
                  />
                </div>
              </article>
            </div>

            <DataTable v-else :value="rowsToShow" size="small" scrollable scroll-height="28rem">
              <Column field="line" header="Line" style="width: 5rem" />
              <Column header="Status" style="width: 8rem">
                <template #body="{ data }">
                  <Tag :value="data.status" :severity="severity[data.status]" />
                </template>
              </Column>
              <Column header="Asset tag" style="width: 12rem">
                <template #body="{ data }">
                  <span class="mono">{{ data.assetTag ?? '—' }}</span>
                  <span v-if="data.derivedFrom" class="muted block">
                    from the workstation on this line
                  </span>
                  <span v-else-if="data.assetTagGenerated" class="muted block">assigned</span>
                </template>
              </Column>
              <Column header="Property no." style="width: 13rem">
                <template #body="{ data }">
                  <span class="mono">{{ data.payload.propertyNumber ?? '—' }}</span>
                </template>
              </Column>
              <Column header="Office">
                <template #body="{ data }">
                  <span>{{ data.office.division ?? '—' }}</span>
                  <span v-if="data.office.section" class="muted block">
                    {{ data.office.section }}
                  </span>
                </template>
              </Column>
              <Column header="Needs attention">
                <template #body="{ data }">
                  <ul v-if="data.errors.length" class="issues bad">
                    <li v-for="(e, i) in data.errors" :key="i">{{ e }}</li>
                  </ul>
                  <ul v-if="data.warnings.length" class="issues warn">
                    <li v-for="(w, i) in data.warnings" :key="i">{{ w }}</li>
                  </ul>
                  <span v-if="!data.errors.length && !data.warnings.length" class="muted">—</span>
                </template>
              </Column>
              <Column v-if="!store.report.committed" header="" style="width: 11rem">
                <template #body="{ data }">
                  <div class="row-actions">
                    <Button
                      v-if="!data.derivedFrom"
                      label="Correct"
                      icon="pi pi-pencil"
                      size="small"
                      outlined
                      @click="openEditor(data)"
                    />
                    <Button
                      v-if="store.corrections[data.line]"
                      v-tooltip.left="'Put this row back to what the file says'"
                      icon="pi pi-undo"
                      size="small"
                      text
                      severity="secondary"
                      aria-label="Undo the corrections on this row"
                      @click="resetRow(data)"
                    />
                  </div>
                </template>
              </Column>
            </DataTable>

            <div v-if="store.stale" class="recheck">
              <Message severity="info" :closable="false">
                <template v-if="store.correctionCount">
                  {{ store.correctionCount }}
                  {{ store.correctionCount === 1 ? 'correction is' : 'corrections are' }}
                  waiting. They go through the same rules as the file did, so
                  re-check to see what they actually do.
                </template>
                <template v-else>
                  The settings above changed, so this report is out of date.
                </template>
              </Message>
              <Button
                label="Re-check"
                icon="pi pi-refresh"
                :loading="store.loading"
                @click="recheck"
              />
            </div>
          </template>
        </template>
      </Card>

      <!-- 5. the write -->
      <Card v-if="store.report && !store.report.fatal">
        <template #title>5. Import to the database</template>
        <template #content>
          <template v-if="store.report.committed">
            <Message
              :severity="store.report.summary.rejected ? 'warn' : 'success'"
              :closable="false"
            >
              {{ store.report.summary.applied }} assets are in the register.
              <template v-if="store.report.summary.rejected">
                {{ store.report.summary.rejected }} were refused — the table above says why.
              </template>
            </Message>
            <div class="actions">
              <RouterLink to="/equipment">
                <Button label="View the equipment register" icon="pi pi-arrow-right" outlined />
              </RouterLink>
              <Button
                label="Import another file"
                icon="pi pi-plus"
                text
                severity="secondary"
                @click="clearFile"
              />
            </div>
          </template>

          <template v-else>
            <p class="muted">
              This writes
              <strong>{{ store.report.summary.ready }}</strong>
              {{ store.report.summary.ready === 1 ? 'asset' : 'assets' }} to the
              register. Rows still showing an error are left out — fix them in
              step 4 and re-check, or import without them and handle them later.
            </p>

            <p v-if="store.stale" class="muted spaced">
              <i class="pi pi-exclamation-triangle warn" />
              Re-check in step 4 first, so what you import is what you reviewed.
            </p>

            <div class="actions">
              <Button
                :label="`Import ${store.report.summary.ready} assets`"
                icon="pi pi-upload"
                :disabled="!store.canCommit"
                :loading="store.committing"
                @click="commit"
              />
            </div>
          </template>
        </template>
      </Card>
    </template>

    <!-- the correction editor -->
    <Dialog
      :visible="!!editing"
      modal
      :header="editing ? `Correct line ${editing.line}` : ''"
      :style="{ width: '40rem', maxWidth: '95vw' }"
      @update:visible="editing = null"
    >
      <template v-if="editing">
        <Message v-if="editing.errors.length" severity="error" :closable="false">
          <ul class="issues">
            <li v-for="(e, i) in editing.errors" :key="i">{{ e }}</li>
          </ul>
        </Message>

        <div class="edit-fields">
          <div v-for="column in editorFields" :key="column.label" class="field">
            <label :for="`fix-${column.label}`">
              {{ column.label }}
              <Tag v-if="column.required" value="Required" severity="danger" />
              <Tag
                v-else-if="(editing.fix ?? []).includes(column.label)"
                value="Needs attention"
                severity="warn"
              />
            </label>
            <InputText
              :id="`fix-${column.label}`"
              v-model="draft[column.label]"
              :aria-describedby="column.note ? `note-${column.label}` : undefined"
              fluid
            />
            <small v-if="column.note" :id="`note-${column.label}`" class="muted">
              {{ column.note }}
            </small>
            <small v-if="editing.cells?.[column.label]" class="muted">
              The file says: <span class="mono">{{ editing.cells[column.label] }}</span>
            </small>
          </div>
        </div>

        <Button
          :label="showEveryField ? 'Show only what needs attention' : `Show all ${store.templateColumns.length} columns`"
          :icon="showEveryField ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
          size="small"
          text
          @click="showEveryField = !showEveryField"
        />
      </template>

      <template #footer>
        <Button label="Cancel" text severity="secondary" @click="editing = null" />
        <Button label="Keep the correction" icon="pi pi-check" @click="saveEdits" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.spaced {
  margin-top: 0.75rem;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 1rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.checkbox-row {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  margin-top: 1.25rem;
}

.block {
  display: block;
}

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr));
  gap: 0.75rem;
  margin: 0 0 1rem;
}

.tiles > div {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
}

.tiles dt {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.7;
}

.tiles dd {
  margin: 0.15rem 0 0;
  font-size: 1.5rem;
  font-weight: 600;
}

.diagnostics p {
  display: flex;
  align-items: baseline;
  gap: 0.45rem;
  margin: 0.3rem 0;
  font-size: 0.86rem;
}

.doc-table {
  width: 100%;
  margin-top: 0.75rem;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.doc-table th,
.doc-table td {
  padding: 0.4rem 0.5rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--p-content-border-color);
}

.columns-doc {
  margin-top: 1rem;
}

.issues {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.82rem;
}

.cards {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin-top: 0.75rem;
}

.record-card {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.85rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.6rem;
}

.record-card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.recheck {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1rem;
}

.recheck :deep(.p-message) {
  flex: 1 1 18rem;
  margin: 0;
}

.edit-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1rem;
  margin: 1rem 0;
}

.edit-fields label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 500;
}

.good {
  color: var(--p-green-500);
}

.warn {
  color: var(--p-orange-500);
}

.bad {
  color: var(--p-red-500);
}

input[type='file'] {
  display: block;
  width: 100%;
  max-width: 28rem;
  padding: 0.55rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: 0.5rem;
}
</style>
