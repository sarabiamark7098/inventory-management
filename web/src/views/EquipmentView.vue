<script setup>
import { computed, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import CodeScanner from '@/components/CodeScanner.vue'
import EquipmentFormDialog from '@/components/EquipmentFormDialog.vue'
import QrLabelSheet from '@/components/QrLabelSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { EQUIPMENT_CONDITIONS, EQUIPMENT_STATUSES, PROVINCES } from '@/domain/constants'
import { useIsMobile } from '@/composables/useBreakpoint'
import { formatDate } from '@/domain/loan'
import { useInventoryStore } from '@/stores/inventory'

const inventory = useInventoryStore()
const confirm = useConfirm()
const toast = useToast()

const isMobile = useIsMobile()

const search = ref('')
const statusFilter = ref(null)
const categoryFilter = ref(null)
const conditionFilter = ref(null)
const provinceFilter = ref(null)
const divisionFilter = ref(null)
const filtersOpen = ref(false)

const formOpen = ref(false)
const editing = ref(null)
const scanOpen = ref(false)
const scanMiss = ref(null)
// Dialog visibility is kept separate from its payload: PrimeVue emits a boolean
// on close, which would otherwise overwrite the record we are displaying.
const scanResult = ref(null)
const scanResultOpen = ref(false)
const labelsFor = ref([])
const labelsOpen = ref(false)

function showLabels(items) {
  labelsFor.value = items
  labelsOpen.value = true
}

const categoryOptions = computed(() => [
  { label: 'Uncategorised', value: '__none__' },
  ...inventory.categories.map((c) => ({ label: c.name, value: c.id })),
])

const divisionOptions = computed(() =>
  inventory.divisions.map((d) => ({ label: d.name, value: d.id })),
)

/** Filtering runs over the loaded register, so it costs no round-trip (US-2.4). */
const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  return inventory.equipment.filter((item) => {
    if (statusFilter.value && item.status !== statusFilter.value) return false
    if (conditionFilter.value && item.condition !== conditionFilter.value) return false
    if (provinceFilter.value && item.province !== provinceFilter.value) return false
    if (divisionFilter.value && item.divisionId !== divisionFilter.value) return false
    if (categoryFilter.value === '__none__' && item.categoryId) return false
    if (
      categoryFilter.value &&
      categoryFilter.value !== '__none__' &&
      item.categoryId !== categoryFilter.value
    ) {
      return false
    }
    if (!term) return true

    return [
      item.assetTag,
      item.propertyNumber,
      item.dateAcquired,
      item.serialNumber,
      item.monitorSerialNumber,
      item.actualUser,
      item.position,
      item.adUsername,
      item.anydeskNumber,
      item.remarks,
      item.modelName,
      item.brand,
      item.location,
      inventory.officeLabel(item.divisionId),
      inventory.officeLabel(item.sectionId),
      item.province,
      item.parIssuedTo,
      item.subParIssuedTo,
      inventory.categoryById.get(item.categoryId)?.name,
      ...Object.values(item.specifications ?? {}).map((v) => String(v ?? '')),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()
      .includes(term)
  })
})

const filtersActive = computed(
  () =>
    search.value ||
    statusFilter.value ||
    categoryFilter.value ||
    conditionFilter.value ||
    provinceFilter.value ||
    divisionFilter.value,
)

function clearFilters() {
  search.value = ''
  statusFilter.value = null
  categoryFilter.value = null
  conditionFilter.value = null
  provinceFilter.value = null
  divisionFilter.value = null
}

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(item) {
  editing.value = item
  formOpen.value = true
}

async function onScan(code) {
  const match = await inventory.findByCode(code)
  if (match) {
    scanResult.value = match
    scanResultOpen.value = true
    scanMiss.value = null
    scanOpen.value = false
  } else {
    scanMiss.value = code
  }
}

function confirmDelete(item) {
  confirm.require({
    header: 'Archive Equipment',
    message: `Are you sure you want to archive ${item.assetTag} (${item.modelName})?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Archive', severity: 'danger' },
    accept: async () => {
      await inventory.deleteEquipment(item.id)
      toast.add({ severity: 'success', summary: 'Equipment archived', life: 2000 })
    },
  })
}

/** CSV export, built in the browser from what is on screen (US-2.4). */
function exportCsv() {
  const columns = [
    ['Asset Tag', (r) => r.assetTag],
    ['Property Number', (r) => r.propertyNumber],
    ['Date Acquired', (r) => r.dateAcquired],
    ['Serial Number', (r) => r.serialNumber],
    ['Monitor Serial Number', (r) => r.monitorSerialNumber],
    ['Model', (r) => r.modelName],
    ['Brand', (r) => r.brand],
    ['Category', (r) => inventory.categoryById.get(r.categoryId)?.name ?? ''],
    ['Status', (r) => r.status],
    ['Condition', (r) => r.condition],
    ['Location', (r) => r.location],
    ['Division', (r) => inventory.officeLabel(r.divisionId)],
    ['Section', (r) => inventory.officeLabel(r.sectionId)],
    ['Province', (r) => r.province],
    ['PAR Issued To', (r) => r.parIssuedTo],
    ['Sub-PAR Issued To', (r) => r.subParIssuedTo],
    ['Actual User', (r) => r.actualUser],
    ['Position', (r) => r.position],
    ['AD Username', (r) => r.adUsername],
    ['AnyDesk Number', (r) => r.anydeskNumber],
    ['Specifications', (r) => JSON.stringify(r.specifications ?? {})],
    ['Remarks', (r) => r.remarks],
    ['Updated At', (r) => r.updatedAt],
  ]

  const escape = (value) => {
    if (value === null || value === undefined) return ''
    const text = String(value)
    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
  }

  const csv = [
    columns.map(([header]) => escape(header)).join(','),
    ...filtered.value.map((row) => columns.map(([, get]) => escape(get(row))).join(',')),
  ].join('\r\n')

  // BOM keeps Excel from mangling UTF-8 asset names.
  const blob = new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `ict-equipment-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Equipment</h1>
        <p>
          Register, audit, and track ICT hardware. Search, filter, and export
          the register.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button label="Scan" icon="pi pi-qrcode" severity="secondary" @click="scanOpen = true" />
        <Button
          label="Export CSV"
          icon="pi pi-download"
          severity="secondary"
          :disabled="filtered.length === 0"
          @click="exportCsv"
        />
        <Button
          label="Labels"
          icon="pi pi-print"
          severity="secondary"
          :disabled="filtered.length === 0"
          @click="showLabels(filtered)"
        />
        <Button label="Register" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <Card class="filter-card">
      <template #content>
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="search"
            placeholder="Search tag, serial, user, office, AnyDesk…"
            aria-label="Search equipment"
            fluid
          />
        </IconField>

        <!-- Four dropdowns would bury the list on a phone, so they collapse. -->
        <Button
          v-if="isMobile"
          class="filter-toggle"
          :label="filtersOpen ? 'Hide filters' : 'Filters'"
          :icon="filtersOpen ? 'pi pi-chevron-up' : 'pi pi-sliders-h'"
          severity="secondary"
          text
          size="small"
          @click="filtersOpen = !filtersOpen"
        />

        <div v-show="!isMobile || filtersOpen" class="filter-grid">
          <Select
            v-model="categoryFilter"
            :options="categoryOptions"
            option-label="label"
            option-value="value"
            placeholder="All categories"
            show-clear
            aria-label="Filter by category"
            fluid
          />
          <Select
            v-model="statusFilter"
            :options="EQUIPMENT_STATUSES"
            placeholder="All statuses"
            show-clear
            aria-label="Filter by status"
            fluid
          />
          <Select
            v-model="conditionFilter"
            :options="EQUIPMENT_CONDITIONS"
            placeholder="All conditions"
            show-clear
            aria-label="Filter by condition"
            fluid
          />
          <Select
            v-model="provinceFilter"
            :options="PROVINCES"
            placeholder="All provinces"
            show-clear
            aria-label="Filter by province"
            fluid
          />
          <Select
            v-model="divisionFilter"
            :options="divisionOptions"
            option-label="label"
            option-value="value"
            placeholder="All divisions"
            show-clear
            filter
            aria-label="Filter by division"
            fluid
          />
        </div>

        <div v-if="filtersActive" class="filter-summary">
          <span class="muted">{{ filtered.length }} of {{ inventory.equipment.length }} items</span>
          <Button label="Clear filters" icon="pi pi-times" text size="small" @click="clearFilters" />
        </div>
      </template>
    </Card>

    <!-- Phones get cards; a seven-column table cannot be read at 375px. -->
    <div v-if="isMobile" class="record-list">
      <div v-if="inventory.loading" class="empty-state">
        <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
      </div>

      <div v-else-if="filtered.length === 0" class="empty-state">
        <i class="pi pi-desktop" />
        <p>
          <strong>
            {{ inventory.equipment.length === 0 ? 'No equipment registered' : 'No matches' }}
          </strong>
        </p>
        <Button
          v-if="inventory.equipment.length === 0"
          label="Register equipment"
          icon="pi pi-plus"
          @click="openCreate"
        />
        <Button v-else label="Clear filters" severity="secondary" @click="clearFilters" />
      </div>

      <article v-for="item in filtered" :key="item.id" class="record-card">
        <div class="record-card__head">
          <div class="record-card__title">
            <strong class="mono">{{ item.assetTag }}</strong>
            <span>{{ item.brand ? `${item.brand} ` : '' }}{{ item.modelName }}</span>
          </div>
          <div class="record-card__tags">
            <StatusTag kind="equipment" :value="item.status" />
          </div>
        </div>

        <dl class="record-card__meta">
          <dt>Property no.</dt>
          <dd class="mono">{{ item.propertyNumber ?? '—' }}</dd>
          <dt>Acquired</dt>
          <dd>{{ item.dateAcquired ? formatDate(item.dateAcquired) : '—' }}</dd>
          <template v-if="item.actualUser">
            <dt>Actual user</dt>
            <dd>{{ item.actualUser }}</dd>
          </template>
          <template v-if="item.anydeskNumber">
            <dt>AnyDesk</dt>
            <dd class="mono">{{ item.anydeskNumber }}</dd>
          </template>
          <template v-if="item.remarks">
            <dt>Remarks</dt>
            <dd class="record-card__remarks">{{ item.remarks }}</dd>
          </template>
          <dt>PAR issued to</dt>
          <dd>{{ item.parIssuedTo ?? '—' }}</dd>
          <template v-if="item.subParIssuedTo">
            <dt>Sub-PAR to</dt>
            <dd>{{ item.subParIssuedTo }}</dd>
          </template>
          <dt>Province</dt>
          <dd>{{ item.province ?? '—' }}</dd>
          <dt>Division</dt>
          <dd>{{ inventory.officeLabel(item.divisionId) ?? '—' }}</dd>
          <dt>Section</dt>
          <dd>{{ inventory.officeLabel(item.sectionId) ?? '—' }}</dd>
          <dt>Condition</dt>
          <dd><StatusTag kind="condition" :value="item.condition" /></dd>
        </dl>

        <div class="record-card__actions">
          <Button
            label="Label"
            icon="pi pi-qrcode"
            size="small"
            severity="secondary"
            outlined
            @click="showLabels([item])"
          />
          <Button
            label="Edit"
            icon="pi pi-pencil"
            size="small"
            severity="secondary"
            outlined
            @click="openEdit(item)"
          />
          <Button
            label="Delete"
            icon="pi pi-trash"
            size="small"
            severity="danger"
            outlined
            @click="confirmDelete(item)"
          />
        </div>
      </article>
    </div>

    <template v-else>
    <DataTable
      :value="filtered"
      :loading="inventory.loading"
      data-key="id"
      paginator
      :rows="15"
      :rows-per-page-options="[15, 30, 50]"
      striped-rows
      removable-sort
      class="equipment-table"
    >
      <template #empty>
        <div class="empty-state">
          <i class="pi pi-desktop" />
          <p>
            <strong>
              {{ inventory.equipment.length === 0 ? 'No equipment registered' : 'No matches' }}
            </strong>
          </p>
          <Button
            v-if="inventory.equipment.length === 0"
            label="Register equipment"
            icon="pi pi-plus"
            @click="openCreate"
          />
          <Button v-else label="Clear filters" severity="secondary" @click="clearFilters" />
        </div>
      </template>

      <Column field="assetTag" header="Asset" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <strong class="mono">{{ data.assetTag }}</strong>
            <span class="muted mono">{{ data.propertyNumber ?? '—' }}</span>
            <span class="muted">{{ data.brand ? `${data.brand} ` : '' }}{{ data.modelName }}</span>
          </div>
        </template>
      </Column>

      <Column header="Category">
        <template #body="{ data }">
          <span class="muted">
            {{ inventory.categoryById.get(data.categoryId)?.name ?? '—' }}
          </span>
        </template>
      </Column>

      <Column field="status" header="Status" sortable>
        <template #body="{ data }">
          <StatusTag kind="equipment" :value="data.status" />
        </template>
      </Column>

      <Column field="condition" header="Condition" sortable>
        <template #body="{ data }">
          <StatusTag kind="condition" :value="data.condition" />
        </template>
      </Column>

      <Column field="parIssuedTo" header="Accountable" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <strong>{{ data.parIssuedTo ?? '—' }}</strong>
            <span v-if="data.subParIssuedTo" class="muted">
              Sub-PAR: {{ data.subParIssuedTo }}
            </span>
          </div>
        </template>
      </Column>

      <Column field="divisionId" header="Office" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <strong>{{ inventory.officeLabel(data.divisionId) ?? '—' }}</strong>
            <span class="muted">
              {{ inventory.officeLabel(data.sectionId) ?? data.province ?? '—' }}
            </span>
          </div>
        </template>
      </Column>

      <Column field="dateAcquired" header="Acquired" sortable>
        <template #body="{ data }">
          <span class="muted">{{ data.dateAcquired ? formatDate(data.dateAcquired) : '—' }}</span>
        </template>
      </Column>

      <Column field="actualUser" header="Assigned to" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <strong>{{ data.actualUser ?? '—' }}</strong>
            <span class="muted">{{ data.position ?? data.location ?? '—' }}</span>
          </div>
        </template>
      </Column>

      <Column header="" style="width: 8rem">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              icon="pi pi-qrcode"
              text
              rounded
              size="small"
              aria-label="QR label"
              @click="showLabels([data])"
            />
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              aria-label="Edit"
              @click="openEdit(data)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Delete"
              @click="confirmDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
    </template>


    <EquipmentFormDialog v-model:visible="formOpen" :equipment="editing" />

    <Dialog
      v-model:visible="scanOpen"
      modal
      header="Scan asset"
      :style="{ width: '32rem' }"
      :breakpoints="{ '767px': '100vw' }"
      @hide="scanMiss = null"
    >
      <CodeScanner v-if="scanOpen" @detected="onScan" />
      <Message v-if="scanMiss" severity="warn" :closable="false" style="margin-top: 0.75rem">
        No equipment matches “{{ scanMiss }}”.
      </Message>
    </Dialog>

    <Dialog
      v-model:visible="scanResultOpen"
      modal
      :header="scanResult?.assetTag ?? ''"
      :style="{ width: '32rem' }"
      :breakpoints="{ '767px': '100vw' }"
    >
      <template v-if="scanResult">
        <p class="muted" style="margin-top: 0">{{ scanResult.modelName }}</p>
        <div class="detail-tags">
          <StatusTag kind="equipment" :value="scanResult.status" />
          <StatusTag kind="condition" :value="scanResult.condition" />
        </div>

        <dl class="detail-list">
          <div>
            <dt>Property no.</dt>
            <dd class="mono">{{ scanResult.propertyNumber ?? '—' }}</dd>
          </div>
          <div>
            <dt>Acquired</dt>
            <dd>{{ scanResult.dateAcquired ? formatDate(scanResult.dateAcquired) : '—' }}</dd>
          </div>
          <div><dt>Serial</dt><dd class="mono">{{ scanResult.serialNumber ?? '—' }}</dd></div>
          <div v-if="scanResult.monitorSerialNumber">
            <dt>Monitor serial</dt>
            <dd class="mono">{{ scanResult.monitorSerialNumber }}</dd>
          </div>
          <div v-if="scanResult.actualUser">
            <dt>Actual user</dt><dd>{{ scanResult.actualUser }}</dd>
          </div>
          <div v-if="scanResult.position">
            <dt>Position</dt><dd>{{ scanResult.position }}</dd>
          </div>
          <div v-if="scanResult.adUsername">
            <dt>AD user</dt><dd class="mono">{{ scanResult.adUsername }}</dd>
          </div>
          <div v-if="scanResult.anydeskNumber">
            <dt>AnyDesk</dt><dd class="mono">{{ scanResult.anydeskNumber }}</dd>
          </div>
          <div v-if="scanResult.remarks" class="detail-remarks">
            <dt>Remarks</dt><dd>{{ scanResult.remarks }}</dd>
          </div>
          <div>
            <dt>Category</dt>
            <dd>{{ inventory.categoryById.get(scanResult.categoryId)?.name ?? '—' }}</dd>
          </div>
          <div><dt>Location</dt><dd>{{ scanResult.location ?? '—' }}</dd></div>
          <div><dt>Brand</dt><dd>{{ scanResult.brand ?? '—' }}</dd></div>
          <div><dt>PAR issued to</dt><dd>{{ scanResult.parIssuedTo ?? '—' }}</dd></div>
          <div v-if="scanResult.subParIssuedTo">
            <dt>Sub-PAR to</dt><dd>{{ scanResult.subParIssuedTo }}</dd>
          </div>
          <div><dt>Province</dt><dd>{{ scanResult.province ?? '—' }}</dd></div>
          <div>
            <dt>Division</dt>
            <dd>{{ inventory.officeLabel(scanResult.divisionId) ?? '—' }}</dd>
          </div>
          <div>
            <dt>Section</dt>
            <dd>{{ inventory.officeLabel(scanResult.sectionId) ?? '—' }}</dd>
          </div>
          <div
            v-for="(value, key) in scanResult.specifications ?? {}"
            :key="key"
          >
            <dt class="mono">{{ key }}</dt>
            <dd>{{ typeof value === 'boolean' ? (value ? 'Yes' : 'No') : value }}</dd>
          </div>
        </dl>
      </template>

      <template #footer>
        <Button label="Close" text severity="secondary" @click="scanResultOpen = false" />
        <Button
          label="Edit asset"
          icon="pi pi-pencil"
          @click="
            () => {
              openEdit(scanResult)
              scanResultOpen = false
            }
          "
        />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="labelsOpen"
      modal
      header="QR labels"
      :style="{ width: '52rem' }"
      :breakpoints="{ '960px': '95vw', '767px': '100vw' }"
    >
      <QrLabelSheet v-if="labelsOpen" :items="labelsFor" />
    </Dialog>
  </section>
</template>

<style scoped>
.filter-card {
  margin-bottom: 1rem;
}

.filter-grid {
  display: grid;
  gap: 0.6rem;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  margin-top: 0.6rem;
}

.filter-toggle {
  margin-top: 0.5rem;
}

@media (max-width: 767px) {
  .filter-grid {
    grid-template-columns: 1fr;
  }
}

.filter-summary {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.6rem;
}

.row-actions {
  display: flex;
  justify-content: flex-end;
}

.record-card__remarks {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.detail-tags {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.detail-list {
  display: grid;
  gap: 0.35rem;
  margin: 0;
}

.detail-list > div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.8rem;
}

.detail-list dt {
  color: var(--p-text-muted-color);
}

.detail-list dd {
  margin: 0;
  font-weight: 500;
  text-align: right;
}

/* Prose needs room to wrap; the right-aligned value column is for short facts. */
.detail-remarks {
  flex-direction: column;
  align-items: flex-start;
}

.detail-remarks dd {
  text-align: left;
  font-weight: 400;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
