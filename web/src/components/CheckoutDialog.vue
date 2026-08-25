<script setup>
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import CodeScanner from './CodeScanner.vue'
import StatusTag from './StatusTag.vue'
import { calculateExpectedReturn, formatDateTime } from '@/domain/loan'
import { canBeBorrowed, explainNotBorrowable } from '@/domain/constants'
import { useInventoryStore } from '@/stores/inventory'

const props = defineProps({
  visible: { type: Boolean, required: true },
  mode: { type: String, default: 'ISSUE' }, // 'ISSUE' | 'REQUEST'
  presetEquipment: { type: Object, default: null },
})
const emit = defineEmits(['update:visible'])

const inventory = useInventoryStore()
const toast = useToast()

const asset = ref(null)
const scanMiss = ref(null)
const borrowerName = ref('')
const borrowerEmail = ref('')
const issueDate = ref(new Date())
/** `null` means "follow the category default"; a Date is a manual override. */
const dueOverride = ref(null)
const notes = ref('')
const saving = ref(false)
const error = ref(null)

watch(
  () => props.visible,
  (open) => {
    if (!open) return
    asset.value = props.presetEquipment
    scanMiss.value = null
    borrowerName.value = ''
    borrowerEmail.value = ''
    issueDate.value = new Date()
    dueOverride.value = null
    notes.value = ''
    error.value = null
  },
)

const loanDays = computed(() => {
  if (!asset.value?.categoryId) return 7
  return inventory.categoryById.get(asset.value.categoryId)?.defaultLoanDays ?? 7
})

// Derived, not stored: tracks the category default until the operator overrides.
const dueDate = computed(() =>
  dueOverride.value ?? calculateExpectedReturn(issueDate.value, loanDays.value),
)

const unavailable = computed(
  () => props.mode === 'ISSUE' && asset.value !== null && asset.value.status !== 'AVAILABLE',
)

/** Out of the loan pool entirely, as opposed to merely busy on another loan. */
const withdrawn = computed(
  () => asset.value !== null && !canBeBorrowed(asset.value.status),
)

const unavailableReason = computed(() => {
  if (!asset.value) return ''
  return withdrawn.value
    ? `${asset.value.assetTag} ${explainNotBorrowable(asset.value.status)}.`
    : `This asset is ${asset.value.status.toLowerCase()} and cannot be issued until it returns to AVAILABLE.`
})

async function onScan(code) {
  const match = await inventory.findByCode(code)
  if (!match) {
    scanMiss.value = code
    return
  }
  scanMiss.value = null
  asset.value = match
}

async function submit() {
  if (!asset.value) return (error.value = 'Scan or select an asset first.')
  if (!borrowerName.value.trim()) return (error.value = 'Borrower name is required.')
  if (!borrowerEmail.value.trim()) return (error.value = 'Borrower email is required.')
  if (dueDate.value <= issueDate.value) {
    return (error.value = 'Expected return must be after the issue date.')
  }

  saving.value = true
  error.value = null
  try {
    const payload = {
      equipmentId: asset.value.id,
      borrowerName: borrowerName.value,
      borrowerEmail: borrowerEmail.value,
      issueDate: issueDate.value.toISOString(),
      expectedReturnDate: dueDate.value.toISOString(),
      notes: notes.value || null,
    }

    if (props.mode === 'REQUEST') await inventory.requestLoan(payload)
    else await inventory.checkout(payload)

    emit('update:visible', false)
    toast.add({
      severity: 'success',
      summary: props.mode === 'REQUEST' ? 'Request submitted' : 'Equipment issued',
      detail: `${asset.value.assetTag} → ${borrowerName.value}`,
      life: 3000,
    })
  } catch (err) {
    error.value = err?.message ?? 'Checkout failed.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="mode === 'REQUEST' ? 'Request a loan' : 'Issue equipment'"
    :style="{ width: '44rem' }"
    :breakpoints="{ '960px': '95vw' }"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="muted" style="margin-top: 0">
      {{
        mode === 'REQUEST'
          ? 'Files a pending request an inventory manager can approve later.'
          : 'Scan the asset label, then confirm borrower details to hand the device over.'
      }}
    </p>

    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 1rem">
      {{ error }}
    </Message>

    <div v-if="asset" class="asset-banner">
      <div>
        <strong class="mono">{{ asset.assetTag }}</strong>
        <span class="muted">{{ asset.brand ? `${asset.brand} ` : '' }}{{ asset.modelName }}</span>
      </div>
      <div class="asset-banner__tags">
        <StatusTag kind="equipment" :value="asset.status" />
        <StatusTag kind="condition" :value="asset.condition" />
        <Button label="Change" icon="pi pi-qrcode" size="small" severity="secondary" text @click="asset = null" />
      </div>
    </div>

    <template v-else>
      <CodeScanner v-if="visible" @detected="onScan" />
      <Message v-if="scanMiss" severity="warn" :closable="false" style="margin-top: 0.75rem">
        No equipment matches “{{ scanMiss }}”.
      </Message>
    </template>

    <Message
      v-if="unavailable"
      :severity="withdrawn ? 'error' : 'warn'"
      :closable="false"
      style="margin-top: 0.75rem"
    >
      {{ unavailableReason }}
    </Message>

    <div v-if="asset" class="form-grid" style="margin-top: 1rem">
      <div class="field">
        <label for="co-name">Borrower name <span class="required">*</span></label>
        <InputText id="co-name" v-model="borrowerName" placeholder="Ada Lovelace" fluid />
      </div>

      <div class="field">
        <label for="co-email">Borrower email <span class="required">*</span></label>
        <InputText id="co-email" v-model="borrowerEmail" type="email" placeholder="ada@example.org" fluid />
      </div>

      <div class="field">
        <label for="co-issue">Issue date</label>
        <DatePicker id="co-issue" v-model="issueDate" show-time hour-format="24" show-icon fluid />
      </div>

      <div class="field">
        <label for="co-due">Expected return <span class="required">*</span></label>
        <DatePicker
          id="co-due"
          :model-value="dueDate"
          show-time
          hour-format="24"
          show-icon
          fluid
          @update:model-value="dueOverride = $event"
        />
        <span class="hint">
          {{
            dueOverride
              ? 'Manually overridden'
              : `Auto-calculated from the ${loanDays}-day category default`
          }}
        </span>
      </div>

      <div class="field span-2">
        <label for="co-notes">Purpose / notes</label>
        <Textarea
          id="co-notes"
          v-model="notes"
          rows="2"
          placeholder="Field survey at the district office."
          fluid
        />
      </div>

      <p class="muted span-2" style="margin: 0">
        <i class="pi pi-calendar-clock" style="margin-right: 0.35rem" />
        Due {{ formatDateTime(dueDate) }}
      </p>
    </div>

    <template #footer>
      <Button
        label="Cancel"
        severity="secondary"
        text
        :disabled="saving"
        @click="emit('update:visible', false)"
      />
      <Button
        :label="saving ? 'Saving…' : mode === 'REQUEST' ? 'Submit request' : 'Confirm checkout'"
        icon="pi pi-check"
        :loading="saving"
        :disabled="!asset || unavailable || withdrawn"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.asset-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
  background: var(--p-surface-50);
}

.asset-banner strong {
  display: block;
  font-size: 0.9rem;
}

.asset-banner__tags {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
</style>
