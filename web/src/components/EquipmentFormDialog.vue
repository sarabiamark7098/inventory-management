<script setup>
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import SpecificationField from './SpecificationField.vue'
import SpecificationFields from './SpecificationFields.vue'
import { deepClone } from '@/domain/clone'
import { fromDateOnly } from '@/domain/loan'
import {
  EQUIPMENT_CONDITIONS,
  EQUIPMENT_STATUSES,
  EQUIPMENT_TRANSITIONS,
  PROVINCES,
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
    propertyNumber: source?.propertyNumber ?? '',
    dateAcquired: fromDateOnly(source?.dateAcquired),
    serialNumber: source?.serialNumber ?? '',
    monitorSerialNumber: source?.monitorSerialNumber ?? '',
    actualUser: source?.actualUser ?? '',
    position: source?.position ?? '',
    adUsername: source?.adUsername ?? '',
    anydeskNumber: source?.anydeskNumber ?? '',
    categoryId: source?.categoryId ?? null,
    modelName: source?.modelName ?? '',
    brand: source?.brand ?? '',
    status: source?.status ?? 'AVAILABLE',
    condition: source?.condition ?? 'GOOD',
    location: source?.location ?? '',
    divisionId: source?.divisionId ?? null,
    sectionId: source?.sectionId ?? null,
    province: source?.province ?? '',
    parIssuedTo: source?.parIssuedTo ?? '',
    subParIssuedTo: source?.subParIssuedTo ?? '',
    specifications: deepClone(source?.specifications, {}) ?? {},
    remarks: source?.remarks ?? '',
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

const divisionOptions = computed(() =>
  inventory.divisions.map((d) => ({ label: d.name, value: d.id })),
)

/**
 * Only the chosen division's sections are offered, so an asset cannot be filed
 * under a section belonging to a different division.
 */
const sectionOptions = computed(() =>
  (inventory.sectionsByDivision.get(form.value.divisionId) ?? [])
    .map((sec) => ({ label: sec.name, value: sec.id })),
)

function onDivisionChange(value) {
  // The previous section belongs to the old division; it cannot carry over.
  form.value = { ...form.value, divisionId: value, sectionId: null }
}

const selectedCategory = computed(() => inventory.categoryById.get(form.value.categoryId))

const allCustomFields = computed(() => selectedCategory.value?.customFields?.fields ?? [])

/**
 * Specifications the category asks to be shown beside a particular field
 * rather than in the Specifications block at the bottom.
 *
 * A host name belongs next to the property number because both are how you
 * confirm the machine in front of you is the record on the screen; a monitor's
 * property number belongs next to the monitor's serial because they identify
 * the same display. They are still specifications — the slot only says where
 * the form draws them.
 */
const slotted = (name) => computed(() => allCustomFields.value.filter((f) => f.slot === name))

const identityFields = slotted('identity')
const monitorFields = slotted('monitor')
const deploymentFields = slotted('deployment')

/** Everything the category did not place itself. */
const customFields = computed(() => allCustomFields.value.filter((f) => !f.slot))

/**
 * AnyDesk is how support reaches a machine remotely, so only the categories
 * that say they use it offer the field. An asset that already carries a number
 * keeps showing it whatever its category now says, so switching the flag off
 * hides the field without stranding a value nobody can edit.
 */
const showsAnydesk = computed(
  () => Boolean(selectedCategory.value?.tracksAnydesk) || Boolean(form.value.anydeskNumber),
)

/** Writes one specification, whether it is drawn inline or in the block below. */
function setSpec(key, value) {
  form.value = {
    ...form.value,
    specifications: { ...form.value.specifications, [key]: value },
  }
}

/** Desktop sets are one asset with two serialised parts. */
const needsMonitorSerial = computed(() => Boolean(selectedCategory.value?.requiresMonitorSerial))

const serialLabel = computed(() =>
  needsMonitorSerial.value ? 'Serial number (system unit)' : 'Serial number',
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
  if (!form.value.propertyNumber.trim()) return (error.value = 'Property number is required.')
  if (!form.value.dateAcquired) return (error.value = 'Date acquired is required.')

  if (needsMonitorSerial.value) {
    if (!form.value.serialNumber.trim()) {
      return (error.value = 'A system unit serial number is required for this category.')
    }
    if (!form.value.monitorSerialNumber.trim()) {
      return (error.value = 'A monitor serial number is required for this category.')
    }
  }
  if (
    form.value.serialNumber.trim() &&
    form.value.serialNumber.trim() === form.value.monitorSerialNumber.trim()
  ) {
    return (error.value = 'The system unit and monitor cannot share a serial number.')
  }

  // Accountability is mandatory for government property. Division comes from
  // the office register and is required; section is optional, because plenty of
  // offices assign equipment at division level.
  if (!form.value.divisionId) return (error.value = 'Division is required.')
  if (!String(form.value.province ?? '').trim()) {
    return (error.value = 'Province is required.')
  }
  if (!form.value.parIssuedTo.trim()) {
    return (error.value = 'Enter the name the PAR is issued to.')
  }

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
      detail: 'Saved.',
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
    :breakpoints="{ '960px': '95vw', '767px': '100vw' }"
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
        <label for="eq-property">Property number <span class="required">*</span></label>
        <InputText
          id="eq-property"
          v-model="form.propertyNumber"
          placeholder="2026-11-LT-0001"
          class="mono"
          fluid
        />
        <span class="hint">Official government property number</span>
      </div>

      <!-- Host names sit here, next to the number on the asset's sticker. -->
      <SpecificationField
        v-for="field in identityFields"
        :key="field.key"
        :field="field"
        :model-value="form.specifications[field.key] ?? null"
        @update:model-value="setSpec(field.key, $event)"
      />

      <div class="field">
        <label for="eq-acquired">Date acquired <span class="required">*</span></label>
        <DatePicker
          id="eq-acquired"
          v-model="form.dateAcquired"
          date-format="yy-mm-dd"
          :max-date="new Date()"
          show-icon
          fluid
        />
        <span class="hint">Used for useful-life reporting</span>
      </div>

      <div class="field">
        <label for="eq-serial">
          {{ serialLabel }}
          <span v-if="needsMonitorSerial" class="required">*</span>
        </label>
        <InputText id="eq-serial" v-model="form.serialNumber" class="mono" fluid />
      </div>

      <div v-if="needsMonitorSerial" class="field">
        <label for="eq-monitor-serial">
          Serial number (monitor) <span class="required">*</span>
        </label>
        <InputText
          id="eq-monitor-serial"
          v-model="form.monitorSerialNumber"
          class="mono"
          fluid
        />
        <span class="hint">This category records both parts of the set</span>
      </div>

      <!-- The monitor's property number, beside the serial of the same display. -->
      <SpecificationField
        v-for="field in monitorFields"
        :key="field.key"
        :field="field"
        :model-value="form.specifications[field.key] ?? null"
        @update:model-value="setSpec(field.key, $event)"
      />

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

    <span class="spec-heading">Deployment</span>
    <p class="section-note">
      Who is actually using the asset. The PAR above names who is accountable for
      it, which is often a different person. All optional — stock in the
      storeroom has no user.
    </p>
    <div class="form-grid">
      <!-- The user's ID number, ahead of their name, because it identifies them. -->
      <SpecificationField
        v-for="field in deploymentFields"
        :key="field.key"
        :field="field"
        :model-value="form.specifications[field.key] ?? null"
        @update:model-value="setSpec(field.key, $event)"
      />

      <div class="field">
        <label for="eq-actual-user">Actual user</label>
        <InputText id="eq-actual-user" v-model="form.actualUser" placeholder="Juan D. Dela Cruz" fluid />
      </div>

      <div class="field">
        <label for="eq-position">Position</label>
        <InputText
          id="eq-position"
          v-model="form.position"
          placeholder="Information Technology Officer I"
          fluid
        />
      </div>

      <div class="field">
        <label for="eq-ad">Active Directory user</label>
        <InputText id="eq-ad" v-model="form.adUsername" placeholder="jddelacruz" class="mono" fluid />
      </div>

      <div v-if="showsAnydesk" class="field">
        <label for="eq-anydesk">AnyDesk number</label>
        <InputText
          id="eq-anydesk"
          v-model="form.anydeskNumber"
          placeholder="1 234 567 890"
          class="mono"
          inputmode="numeric"
          fluid
        />
        <span class="hint">Optional — for remote support sessions</span>
      </div>
    </div>

    <Divider />

    <span class="spec-heading">Property accountability</span>
    <div class="form-grid">
      <div class="field">
        <label for="eq-division">Division <span class="required">*</span></label>
        <Select
          id="eq-division"
          :model-value="form.divisionId"
          :options="divisionOptions"
          option-label="label"
          option-value="value"
          filter
          placeholder="Select a division"
          fluid
          @update:model-value="onDivisionChange"
        />
        <span class="hint">From the office register</span>
      </div>

      <div class="field">
        <label for="eq-section">Section</label>
        <Select
          id="eq-section"
          v-model="form.sectionId"
          :options="sectionOptions"
          option-label="label"
          option-value="value"
          :disabled="!form.divisionId"
          filter
          show-clear
          :placeholder="form.divisionId ? 'Optional' : 'Choose a division first'"
          fluid
        />
        <span class="hint">
          Optional — leave blank when the asset sits at division level
        </span>
      </div>

      <div class="field">
        <label for="eq-province">Province <span class="required">*</span></label>
        <Select
          id="eq-province"
          v-model="form.province"
          :options="PROVINCES"
          editable
          placeholder="Select or type"
          fluid
        />
      </div>

      <div class="field">
        <label for="eq-par-name">PAR issued to <span class="required">*</span></label>
        <InputText
          id="eq-par-name"
          v-model="form.parIssuedTo"
          placeholder="Juan D. Dela Cruz"
          fluid
        />
        <span class="hint">Accountable officer named on the PAR</span>
      </div>

      <div class="field">
        <label for="eq-subpar-name">Sub-PAR issued to</label>
        <InputText
          id="eq-subpar-name"
          v-model="form.subParIssuedTo"
          placeholder="Optional"
          fluid
        />
        <span class="hint">Required if a Sub-PAR number is given</span>
      </div>
    </div>

    <Divider />

    <span class="spec-heading">Specifications</span>
    <SpecificationFields v-model="form.specifications" :fields="customFields" />

    <Divider />

    <div class="field">
      <label for="eq-remarks">Remarks</label>
      <Textarea
        id="eq-remarks"
        v-model="form.remarks"
        rows="3"
        placeholder="Anything the fields above have no place for — defect history, transfer notes, why this unit is set aside."
        fluid
      />
      <span class="hint">Optional. Shown on the asset and included in the CSV export.</span>
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
        :label="saving ? 'Saving…' : equipment ? 'Save changes' : 'Register'"
        icon="pi pi-check"
        :loading="saving"
        @click="save"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.section-note {
  margin: 0 0 0.85rem;
  font-size: 0.76rem;
  line-height: 1.5;
  color: var(--p-text-muted-color);
}

.spec-heading {
  display: block;
  margin-bottom: 0.6rem;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
</style>
