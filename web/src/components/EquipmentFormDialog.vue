<script setup>
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import SpecificationFields from './SpecificationFields.vue'
import { deepClone } from '@/domain/clone'
import {
  EQUIPMENT_CONDITIONS,
  EQUIPMENT_STATUSES,
  EQUIPMENT_TRANSITIONS,
  canTransitionEquipment,
} from '@/domain/constants'
import { useInventoryStore } from '@/stores/inventory'

const props = defineProps({
  visible: { type: Boolean, required: true },
  /** `null` opens the dialog in create mode. */
  equipment: { type: Object, default: null },
})
const emit = defineEmits(['update:visible'])

const inventory = useInventoryStore()
const toast = useToast()

const saving = ref(false)
const error = ref(null)
const form = ref(blankForm())

function blankForm(source = null) {
  return {
    assetTag: source?.assetTag ?? '',
    serialNumber: source?.serialNumber ?? '',
    categoryId: source?.categoryId ?? null,
    modelName: source?.modelName ?? '',
    brand: source?.brand ?? '',
    status: source?.status ?? 'AVAILABLE',
    condition: source?.condition ?? 'GOOD',
    location: source?.location ?? '',
    specifications: deepClone(source?.specifications, {}) ?? {},
  }
}

// Reset whenever the dialog opens, so a previous edit never bleeds through.
watch(
  () => props.visible,
  (open) => {
    if (!open) return
    form.value = blankForm(props.equipment)
    error.value = null
  },
)

const categoryOptions = computed(() => [
  { label: 'Uncategorised', value: null },
  ...inventory.categories.map((c) => ({ label: c.name, value: c.id })),
])

const customFields = computed(
  () => inventory.categoryById.get(form.value.categoryId)?.customFields?.fields ?? [],
)

/** Only offer transitions the state machine allows (US-2.3). */
const statusOptions = computed(() => {
  if (!props.equipment) return EQUIPMENT_STATUSES
  return EQUIPMENT_STATUSES.filter((s) => canTransitionEquipment(props.equipment.status, s))
})

function onCategoryChange(value) {
  // Specs belong to the old category's schema; start clean.
  form.value = { ...form.value, categoryId: value, specifications: {} }
}

function validateSpecifications() {
  for (const field of customFields.value) {
    if (!field.required) continue
    const value = form.value.specifications[field.key]
    const empty =
      value === undefined ||
      value === null ||
      value === '' ||
      (field.type === 'boolean' && value === false)
    if (empty) return `"${field.label}" is required for this category.`
  }
  return null
}

async function save() {
  if (!form.value.assetTag.trim()) return (error.value = 'Asset tag is required.')
  if (!form.value.modelName.trim()) return (error.value = 'Model name is required.')

  const specError = validateSpecifications()
  if (specError) return (error.value = specError)

  if (props.equipment && !canTransitionEquipment(props.equipment.status, form.value.status)) {
    error.value =
      `Cannot move from ${props.equipment.status} to ${form.value.status}. ` +
      `Allowed: ${EQUIPMENT_TRANSITIONS[props.equipment.status].join(', ')}.`
    return
  }

  saving.value = true
  error.value = null
  try {
    if (props.equipment) await inventory.updateEquipment(props.equipment.id, { ...form.value })
    else await inventory.createEquipment({ ...form.value })

    emit('update:visible', false)
    toast.add({
      severity: 'success',
      summary: props.equipment ? 'Equipment updated' : 'Equipment registered',
      detail: 'Saved locally and queued for sync.',
      life: 2500,
    })
  } catch (err) {
    error.value = err?.message ?? 'Could not save equipment.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="equipment ? `Edit ${equipment.assetTag}` : 'Register equipment'"
    :style="{ width: '48rem' }"
    :breakpoints="{ '960px': '95vw' }"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="muted" style="margin-top: 0">
      Specification inputs come from the selected category's custom field definitions.
    </p>

    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 1rem">
      {{ error }}
    </Message>

    <div class="form-grid">
      <div class="field">
        <label for="eq-tag">Asset tag <span class="required">*</span></label>
        <InputText id="eq-tag" v-model="form.assetTag" placeholder="ICT-LT-0001" class="mono" fluid />
        <span class="hint">Printed on the QR label</span>
      </div>

      <div class="field">
        <label for="eq-serial">Serial number</label>
        <InputText id="eq-serial" v-model="form.serialNumber" class="mono" fluid />
      </div>

      <div class="field">
        <label for="eq-model">Model name <span class="required">*</span></label>
        <InputText id="eq-model" v-model="form.modelName" placeholder="ThinkPad T14 Gen 4" fluid />
      </div>

      <div class="field">
        <label for="eq-brand">Brand</label>
        <InputText id="eq-brand" v-model="form.brand" placeholder="Lenovo" fluid />
      </div>

      <div class="field">
        <label for="eq-category">Category</label>
        <Select
          id="eq-category"
          :model-value="form.categoryId"
          :options="categoryOptions"
          option-label="label"
          option-value="value"
          fluid
          @update:model-value="onCategoryChange"
        />
      </div>

      <div class="field">
        <label for="eq-location">Location</label>
        <InputText id="eq-location" v-model="form.location" placeholder="ICT Storeroom / Rack B" fluid />
      </div>

      <div class="field">
        <label for="eq-status">Status</label>
        <Select id="eq-status" v-model="form.status" :options="statusOptions" fluid />
      </div>

      <div class="field">
        <label for="eq-condition">Condition</label>
        <Select id="eq-condition" v-model="form.condition" :options="EQUIPMENT_CONDITIONS" fluid />
      </div>
    </div>

    <Divider />

    <span class="spec-heading">Specifications</span>
    <SpecificationFields v-model="form.specifications" :fields="customFields" />

    <template #footer>
      <Button
        label="Cancel"
        severity="secondary"
        text
        :disabled="saving"
        @click="emit('update:visible', false)"
      />
      <Button
        :label="saving ? 'Saving…' : equipment ? 'Save changes' : 'Register'"
        icon="pi pi-check"
        :loading="saving"
        @click="save"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.spec-heading {
  display: block;
  margin-bottom: 0.6rem;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
</style>
