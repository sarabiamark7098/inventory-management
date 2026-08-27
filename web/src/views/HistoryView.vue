<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import { useHistoryStore } from '@/stores/history'
import { formatDateTime } from '@/domain/loan'
import { useIsMobile } from '@/composables/useBreakpoint'

const history = useHistoryStore()
const toast = useToast()
const isMobile = useIsMobile()

const detail = ref(null)
const detailOpen = ref(false)
const detailLoading = ref(false)

onMounted(() => history.load().catch(report))

function report(err) {
  toast.add({
    severity: 'error',
    summary: 'Could not load the history',
    detail: err?.message ?? 'Unknown error',
    life: 6000,
  })
}

/**
 * Typing shouldn't fire a request per keystroke, and the log is paged
 * server-side so it cannot be filtered in the browser instead.
 */
let searchTimer
watch(
  () => history.filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => history.applyFilters().catch(report), 350)
  },
)

const rerun = () => history.applyFilters().catch(report)

function onPage(event) {
  history.load(event.page + 1).catch(report)
}

/** Colour carries the weight of the action, so a purge is never mistaken for an edit. */
const OPERATION_STYLE = {
  INSERT: { severity: 'success', icon: 'pi pi-plus', label: 'Created' },
  UPDATE: { severity: 'info', icon: 'pi pi-pencil', label: 'Edited' },
  DELETE: { severity: 'warn', icon: 'pi pi-inbox', label: 'Archived' },
  RESTORE: { severity: 'secondary', icon: 'pi pi-undo', label: 'Restored' },
  PURGE: { severity: 'danger', icon: 'pi pi-trash', label: 'Deleted forever' },
}

const styleFor = (op) =>
  OPERATION_STYLE[op] ?? { severity: 'secondary', icon: 'pi pi-circle', label: op }

const range = computed(() => {
  const { page, perPage, total } = history.meta
  if (!total) return null
  const first = (page - 1) * perPage + 1
  return `${first}–${Math.min(first + perPage - 1, total)} of ${total}`
})

async function open(row) {
  detailOpen.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await history.entry(row.id)
  } catch (err) {
    detailOpen.value = false
    report(err)
  } finally {
    detailLoading.value = false
  }
}

/** `par_issued_to` reads as "Par issued to"; the log stores column names. */
const humanise = (field) =>
  field
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/_/g, ' ')
    .replace(/^./, (c) => c.toUpperCase())

/** Renders any snapshot value as something a person can read in a table cell. */
function display(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) return value.length ? JSON.stringify(value) : '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const snapshotRows = computed(() =>
  Object.entries(detail.value?.snapshot ?? {})
    .filter(([field]) => !['id', 'updated_at', 'created_at'].includes(field))
    .map(([field, value]) => ({ field, value })),
)
</script>

<template>
  <section class="view">
    <header class="view__header">
      <div>
        <h1>History</h1>
        <p class="muted">
          Every change anyone has made, newest first.
          <template v-if="history.meta.total">{{ history.meta.total }} recorded.</template>
        </p>
      </div>

      <div class="view__actions">
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="history.filters.search"
            placeholder="Search asset tag, name, id…"
            aria-label="Search the history"
          />
        </IconField>
        <Button
          icon="pi pi-refresh"
          severity="secondary"
          outlined
          aria-label="Reload the history"
          :loading="history.loading"
          @click="history.load(history.meta.page).catch(report)"
        />
      </div>
    </header>

    <div class="filters">
      <Select
        v-model="history.filters.entityType"
        :options="history.options.entityTypes"
        option-label="label"
        option-value="value"
        placeholder="Anything"
        show-clear
        aria-label="Filter by record type"
        @change="rerun"
      />
      <Select
        v-model="history.filters.operation"
        :options="history.options.operations"
        option-label="label"
        option-value="value"
        placeholder="Any action"
        show-clear
        aria-label="Filter by action"
        @change="rerun"
      />
      <Select
        v-model="history.filters.actor"
        :options="history.options.actors"
        option-label="label"
        option-value="value"
        placeholder="Anyone"
        show-clear
        filter
        aria-label="Filter by person"
        @change="rerun"
      >
        <template #option="{ option }">
          <span>{{ option.label }}</span>
          <Tag
            v-if="option.deleted"
            value="account deleted"
            severity="secondary"
            class="option-tag"
          />
        </template>
      </Select>
      <DatePicker
        v-model="history.filters.from"
        placeholder="From"
        date-format="dd M yy"
        show-icon
        icon-display="input"
        aria-label="From date"
        @date-select="rerun"
        @clear-click="rerun"
      />
      <DatePicker
        v-model="history.filters.to"
        placeholder="To"
        date-format="dd M yy"
        show-icon
        icon-display="input"
        aria-label="To date"
        @date-select="rerun"
        @clear-click="rerun"
      />
      <Button
        v-if="history.hasFilters"
        label="Clear"
        icon="pi pi-filter-slash"
        text
        size="small"
        @click="history.clearFilters().catch(report)"
      />
    </div>

    <Message v-if="history.error" severity="error" :closable="false">
      {{ history.error }}
    </Message>

    <div v-if="history.loading && !history.entries.length" class="empty-state">
      <ProgressSpinner style="width: 2rem; height: 2rem" />
    </div>

    <div v-else-if="!history.entries.length" class="empty-state">
      <i class="pi pi-history" style="font-size: 2rem" aria-hidden="true" />
      <p v-if="history.hasFilters">Nothing matches those filters.</p>
      <p v-else>Nothing has happened yet.</p>
    </div>

    <template v-else>
      <!-- Mobile: one card per entry, since a five-column table is unreadable. -->
      <div v-if="isMobile" class="card-list">
        <article
          v-for="row in history.entries"
          :key="row.id"
          class="entry-card"
          role="button"
          tabindex="0"
          @click="open(row)"
          @keydown.enter="open(row)"
        >
          <header>
            <Tag
              :value="styleFor(row.operation).label"
              :severity="styleFor(row.operation).severity"
              :icon="styleFor(row.operation).icon"
            />
            <span class="muted">{{ formatDateTime(row.occurredAt) }}</span>
          </header>
          <p class="entry-card__record">
            <span class="muted">{{ row.entityLabel }}</span>
            <strong>{{ row.recordLabel ?? '—' }}</strong>
          </p>
          <p class="entry-card__actor">
            <i class="pi pi-user" aria-hidden="true" />
            {{ row.actor.name }}
            <Tag v-if="row.actor.deleted" value="account deleted" severity="secondary" />
          </p>
        </article>
      </div>

      <DataTable
        v-else
        :value="history.entries"
        size="small"
        data-key="id"
        scrollable
        row-hover
        @row-click="open($event.data)"
      >
        <Column field="occurredAt" header="When" style="width: 13rem">
          <template #body="{ data }">{{ formatDateTime(data.occurredAt) }}</template>
        </Column>
        <Column field="operation" header="Action" style="width: 11rem">
          <template #body="{ data }">
            <Tag
              :value="styleFor(data.operation).label"
              :severity="styleFor(data.operation).severity"
              :icon="styleFor(data.operation).icon"
            />
          </template>
        </Column>
        <Column header="Record">
          <template #body="{ data }">
            <div class="asset-cell">
              <strong>{{ data.recordLabel ?? '—' }}</strong>
              <span class="muted">{{ data.entityLabel }}</span>
            </div>
          </template>
        </Column>
        <Column header="Who">
          <template #body="{ data }">
            <div class="asset-cell">
              <strong>{{ data.actor.name }}</strong>
              <span v-if="data.actor.deleted" class="muted">account deleted</span>
              <span v-else-if="data.actor.system" class="muted">not a sign-in</span>
              <span v-else class="muted">{{ data.actor.email }}</span>
            </div>
          </template>
        </Column>
        <Column header="" style="width: 6rem">
          <template #body="{ data }">
            <Button
              icon="pi pi-search"
              text
              rounded
              size="small"
              :aria-label="`What changed on ${data.recordLabel ?? 'this record'}`"
              @click.stop="open(data)"
            />
          </template>
        </Column>
      </DataTable>

      <div class="pager">
        <span class="muted">{{ range }}</span>
        <Paginator
          :rows="history.meta.perPage"
          :total-records="history.meta.total"
          :first="(history.meta.page - 1) * history.meta.perPage"
          @page="onPage"
        />
      </div>
    </template>

    <Dialog
      v-model:visible="detailOpen"
      modal
      header="What changed"
      :style="{ width: '42rem' }"
      :breakpoints="{ '640px': '95vw' }"
    >
      <div v-if="detailLoading" class="empty-state">
        <ProgressSpinner style="width: 2rem; height: 2rem" />
      </div>

      <div v-else-if="detail" class="detail">
        <dl class="detail__meta">
          <dt>Record</dt>
          <dd>
            <strong>{{ detail.recordLabel ?? '—' }}</strong>
            <span class="muted"> · {{ detail.entityLabel }}</span>
          </dd>
          <dt>Action</dt>
          <dd>
            <Tag
              :value="styleFor(detail.operation).label"
              :severity="styleFor(detail.operation).severity"
            />
          </dd>
          <dt>Who</dt>
          <dd>
            {{ detail.actor.name }}
            <Tag v-if="detail.actor.deleted" value="account deleted" severity="secondary" />
          </dd>
          <dt>When</dt>
          <dd>{{ formatDateTime(detail.occurredAt) }}</dd>
        </dl>

        <Divider />

        <!--
          Three cases, and conflating them would mislead. A comparable entry can
          show before and after; a destroyed record has no "after" to compare
          with, only what it held; an entry carried over from the old log has no
          "before" and must not imply every field was new.
        -->
        <template v-if="detail.comparable && detail.changes.length">
          <DataTable :value="detail.changes" size="small" data-key="field">
            <Column header="Field">
              <template #body="{ data }">{{ humanise(data.field) }}</template>
            </Column>
            <Column header="From">
              <template #body="{ data }">
                <span class="was">{{ display(data.from) }}</span>
              </template>
            </Column>
            <Column header="To">
              <template #body="{ data }">
                <strong>{{ display(data.to) }}</strong>
              </template>
            </Column>
          </DataTable>
        </template>

        <template v-else>
          <Message severity="secondary" :closable="false">
            <template v-if="detail.operation === 'PURGE'">
              This record was destroyed. What follows is the last state it held —
              the only remaining evidence it existed.
            </template>
            <template v-else-if="!detail.comparable">
              This entry predates field-level tracking, so what follows is the
              record as it stood, not what changed.
            </template>
            <template v-else> Nothing changed beyond timestamps. </template>
          </Message>

          <DataTable
            v-if="snapshotRows.length"
            :value="snapshotRows"
            size="small"
            data-key="field"
            class="snapshot"
          >
            <Column header="Field">
              <template #body="{ data }">{{ humanise(data.field) }}</template>
            </Column>
            <Column header="Value">
              <template #body="{ data }">{{ display(data.value) }}</template>
            </Column>
          </DataTable>
        </template>
      </div>

      <template #footer>
        <Button label="Close" text severity="secondary" @click="detailOpen = false" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.filters :deep(.p-select),
.filters :deep(.p-datepicker) {
  min-width: 10rem;
  flex: 1 1 10rem;
}

.option-tag {
  margin-left: 0.4rem;
}

.pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.card-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.entry-card {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.8rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.6rem;
  cursor: pointer;
}

.entry-card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.75rem;
}

.entry-card__record {
  display: flex;
  flex-direction: column;
  margin: 0;
  font-size: 0.85rem;
}

.entry-card__record .muted {
  font-size: 0.72rem;
}

.entry-card__actor {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.detail__meta {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.35rem 0.9rem;
  margin: 0;
  font-size: 0.85rem;
}

.detail__meta dt {
  color: var(--p-text-muted-color);
}

.detail__meta dd {
  margin: 0;
}

.was {
  color: var(--p-text-muted-color);
  text-decoration: line-through;
}

.snapshot {
  margin-top: 0.75rem;
}
</style>
