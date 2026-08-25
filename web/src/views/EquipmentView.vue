<script setup>
import { computed, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import CodeScanner from '@/components/CodeScanner.vue'
import EquipmentFormDialog from '@/components/EquipmentFormDialog.vue'
import QrLabelSheet from '@/components/QrLabelSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { EQUIPMENT_CONDITIONS, EQUIPMENT_STATUSES } from '@/domain/constants'
import { useInventoryStore } from '@/stores/inventory'

const inventory = useInventoryStore()
const confirm = useConfirm()
const toast = useToast()

const search = ref('')
const statusFilter = ref(null)
const categoryFilter = ref(null)
const conditionFilter = ref(null)

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

/** All filtering runs against the local store, so it works offline (US-2.4). */
const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  return inventory.equipment.filter((item) => {
    if (statusFilter.value && item.status !== statusFilter.value) return false
    if (conditionFilter.value && item.condition !== conditionFilter.value) return false
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
      item.serialNumber,
      item.modelName,
      item.brand,
      item.location,
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
  () => search.value || statusFilter.value || categoryFilter.value || conditionFilter.value,
)

function clearFilters() {
  search.value = ''
  statusFilter.value = null
  categoryFilter.value = null
  conditionFilter.value = null
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
    header: 'Delete equipment',
    message: `Soft-delete ${item.assetTag} (${item.modelName})? The removal syncs on the next cycle.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Delete', severity: 'danger' },
    accept: async () => {
      await inventory.deleteEquipment(item.id)
      toast.add({ severity: 'success', summary: 'Equipment deleted', life: 2000 })
    },
  })
}

/** Offline CSV export — no server round-trip (US-2.4). */
function exportCsv() {
  const columns = [
    ['Asset Tag', (r) => r.assetTag],
    ['Serial Number', (r) => r.serialNumber],
    ['Model', (r) => r.modelName],
    ['Brand', (r) => r.brand],
    ['Category', (r) => inventory.categoryById.get(r.categoryId)?.name ?? ''],
    ['Status', (r) => r.status],
    ['Condition', (r) => r.condition],
    ['Location', (r) => r.location],
    ['Specifications', (r) => JSON.stringify(r.specifications ?? {})],
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
          Register, audit, and track ICT hardware. Search and export work fully
          offline against the local store.
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
        <div class="filter-grid">
          <IconField>
            <InputIcon class="pi pi-search" />
            <InputText
              v-model="search"
              placeholder="Search tag, serial, model, specs…"
              aria-label="Search equipment"
              fluid
            />
          </IconField>

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
        </div>

        <div v-if="filtersActive" class="filter-summary">
          <span class="muted">{{ filtered.length }} of {{ inventory.equipment.length }} items</span>
          <Button label="Clear filters" icon="pi pi-times" text size="small" @click="clearFilters" />
        </div>
      </template>
    </Card>

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

      <Column field="location" header="Location">
        <template #body="{ data }">
          <span class="muted">{{ data.location ?? '—' }}</span>
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

    <EquipmentFormDialog v-model:visible="formOpen" :equipment="editing" />

    <Dialog
      v-model:visible="scanOpen"
      modal
      header="Scan asset"
      :style="{ width: '32rem' }"
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
    >
      <template v-if="scanResult">
        <p class="muted" style="margin-top: 0">{{ scanResult.modelName }}</p>
        <div class="detail-tags">
          <StatusTag kind="equipment" :value="scanResult.status" />
          <StatusTag kind="condition" :value="scanResult.condition" />
        </div>

        <dl class="detail-list">
          <div><dt>Serial</dt><dd class="mono">{{ scanResult.serialNumber ?? '—' }}</dd></div>
          <div>
            <dt>Category</dt>
            <dd>{{ inventory.categoryById.get(scanResult.categoryId)?.name ?? '—' }}</dd>
          </div>
          <div><dt>Location</dt><dd>{{ scanResult.location ?? '—' }}</dd></div>
          <div><dt>Brand</dt><dd>{{ scanResult.brand ?? '—' }}</dd></div>
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
      :breakpoints="{ '960px': '95vw' }"
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
</style>
