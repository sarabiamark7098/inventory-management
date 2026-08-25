<script setup>
import { computed, onMounted, ref } from 'vue'
import { useToast } from 'primevue/usetoast'

import CheckoutDialog from '@/components/CheckoutDialog.vue'
import ReturnDialog from '@/components/ReturnDialog.vue'
import StatusTag from '@/components/StatusTag.vue'
import { daysUntilDue, formatDate, isOverdue } from '@/domain/loan'
import { useInventoryStore } from '@/stores/inventory'

const inventory = useInventoryStore()
const toast = useToast()

const tab = ref('active')
const search = ref('')
const checkoutOpen = ref(false)
const returning = ref(null)
const returnOpen = ref(false)

const tabs = [
  { value: 'active', label: 'On loan' },
  { value: 'requests', label: 'Requests' },
  { value: 'conflicts', label: 'Conflicts' },
  { value: 'history', label: 'History' },
]

// Promote past-due loans on entry; the sync engine repeats this each cycle.
onMounted(() => inventory.sweepOverdue())

const buckets = computed(() => {
  const result = { active: [], requests: [], conflicts: [], history: [] }
  for (const tx of inventory.borrowings) {
    if (['ISSUED', 'APPROVED', 'OVERDUE'].includes(tx.status)) result.active.push(tx)
    else if (tx.status === 'REQUESTED') result.requests.push(tx)
    else if (tx.status === 'CONFLICT_PENDING_REVIEW') result.conflicts.push(tx)
    else result.history.push(tx)
  }
  return result
})

const rows = computed(() => {
  const source = buckets.value[tab.value]
  const term = search.value.trim().toLowerCase()
  if (!term) return source

  return source.filter((tx) => {
    const asset = inventory.equipmentById.get(tx.equipmentId)
    return [tx.borrowerName, tx.borrowerEmail, tx.notes, asset?.assetTag, asset?.modelName]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()
      .includes(term)
  })
})

const tabOptions = computed(() =>
  tabs.map((t) => ({ ...t, label: `${t.label} (${buckets.value[t.value].length})` })),
)

function openReturn(tx) {
  returning.value = tx
  returnOpen.value = true
}

async function approve(tx) {
  await inventory.approveRequest(tx.id)
  toast.add({ severity: 'success', summary: 'Request approved', life: 2000 })
}

async function reject(tx) {
  await inventory.setBorrowingStatus(tx.id, 'REJECTED')
  toast.add({ severity: 'info', summary: 'Request rejected', life: 2000 })
}

function assetOf(tx) {
  return inventory.equipmentById.get(tx.equipmentId)
}

function dueLabel(tx) {
  const active = ['ISSUED', 'APPROVED', 'OVERDUE'].includes(tx.status)
  if (!active) {
    return tx.actualReturnDate ? `returned ${formatDate(tx.actualReturnDate)}` : ''
  }
  const remaining = daysUntilDue(tx.expectedReturnDate)
  return isOverdue(tx) ? `${Math.abs(remaining)}d overdue` : `${remaining}d remaining`
}
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Borrowing &amp; returns</h1>
        <p>
          Issue equipment with a quick scan, track due dates, and record return
          inspections.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button label="Quick checkout" icon="pi pi-arrow-right-arrow-left" @click="checkoutOpen = true" />
      </div>
    </div>

    <Message
      v-if="inventory.overdueLoans.length > 0"
      severity="error"
      :closable="false"
      class="alert"
    >
      <strong>{{ inventory.overdueLoans.length }}</strong>
      {{ inventory.overdueLoans.length === 1 ? 'loan is' : 'loans are' }} past the
      expected return date.
    </Message>

    <Message
      v-if="buckets.conflicts.length > 0 && tab !== 'conflicts'"
      severity="warn"
      :closable="false"
      class="alert"
    >
      <strong>{{ buckets.conflicts.length }}</strong>
      {{ buckets.conflicts.length === 1 ? 'transaction' : 'transactions' }} flagged by
      the server as a dual checkout.
      <Button label="Review" text size="small" @click="tab = 'conflicts'" />
    </Message>

    <div class="controls">
      <SelectButton
        v-model="tab"
        :options="tabOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        aria-label="Filter transactions"
      />
      <IconField class="controls__search">
        <InputIcon class="pi pi-search" />
        <InputText v-model="search" placeholder="Search borrower or asset…" fluid />
      </IconField>
    </div>

    <DataTable
      :value="rows"
      :loading="inventory.loading"
      data-key="id"
      paginator
      :rows="15"
      striped-rows
    >
      <template #empty>
        <div class="empty-state">
          <i class="pi pi-arrow-right-arrow-left" />
          <p><strong>Nothing here</strong></p>
          <p v-if="tab === 'active'">Issue a device with Quick checkout to see it here.</p>
        </div>
      </template>

      <Column header="Asset">
        <template #body="{ data }">
          <div class="asset-cell">
            <strong class="mono">{{ assetOf(data)?.assetTag ?? '—' }}</strong>
            <span class="muted">{{ assetOf(data)?.modelName ?? 'Asset removed' }}</span>
          </div>
        </template>
      </Column>

      <Column header="Borrower">
        <template #body="{ data }">
          <div class="asset-cell">
            <strong>{{ data.borrowerName }}</strong>
            <span class="muted">{{ data.borrowerEmail }}</span>
          </div>
        </template>
      </Column>

      <Column field="issueDate" header="Issued" sortable>
        <template #body="{ data }">
          <span class="muted">{{ formatDate(data.issueDate) }}</span>
        </template>
      </Column>

      <Column field="expectedReturnDate" header="Due" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <span :class="isOverdue(data) ? 'due-late' : 'muted'">
              {{ formatDate(data.expectedReturnDate) }}
            </span>
            <span class="muted">{{ dueLabel(data) }}</span>
          </div>
        </template>
      </Column>

      <Column field="status" header="Status" sortable>
        <template #body="{ data }">
          <StatusTag kind="borrowing" :value="data.status" :overdue="isOverdue(data)" />
          <p v-if="data.conflictReason" class="conflict-reason">{{ data.conflictReason }}</p>
        </template>
      </Column>

      <Column header="" style="width: 12rem">
        <template #body="{ data }">
          <div class="row-actions">
            <template v-if="data.status === 'REQUESTED'">
              <Button label="Approve" icon="pi pi-check" size="small" @click="approve(data)" />
              <Button
                label="Reject"
                icon="pi pi-times"
                size="small"
                severity="secondary"
                outlined
                @click="reject(data)"
              />
            </template>
            <Button
              v-if="['ISSUED', 'APPROVED', 'OVERDUE', 'CONFLICT_PENDING_REVIEW'].includes(data.status)"
              label="Return"
              icon="pi pi-check-circle"
              size="small"
              @click="openReturn(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <CheckoutDialog v-model:visible="checkoutOpen" />
    <ReturnDialog v-model:visible="returnOpen" :transaction="returning" />
  </section>
</template>

<style scoped>
.alert {
  margin-bottom: 1rem;
}

.controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.controls__search {
  min-width: 16rem;
}

.row-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.35rem;
}

.due-late {
  font-weight: 600;
  color: var(--p-red-500);
  font-size: 0.8rem;
}

.conflict-reason {
  margin: 0.3rem 0 0;
  max-width: 16rem;
  font-size: 0.7rem;
  color: var(--p-text-muted-color);
}
</style>
