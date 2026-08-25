<script setup>
import { computed, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

import { EQUIPMENT_CONDITIONS } from '@/domain/constants'
import { daysUntilDue, isOverdue } from '@/domain/loan'
import { useInventoryStore } from '@/stores/inventory'

const props = defineProps({
  visible: { type: Boolean, required: true },
  transaction: { type: Object, default: null },
})
const emit = defineEmits(['update:visible'])

const inventory = useInventoryStore()
const toast = useToast()

const ACCESSORIES = [
  'Charger / power adapter',
  'Carrying case',
  'Mouse',
  'Cables',
  'Manual / documentation',
]

const condition = ref('GOOD')
const missing = ref([])
const damage = ref('')
const markLost = ref(false)
const saving = ref(false)
const error = ref(null)

const asset = computed(() =>
  props.transaction ? inventory.equipmentById.get(props.transaction.equipmentId) : null,
)

watch(
  () => props.visible,
  (open) => {
    if (!open) return
    condition.value = asset.value?.condition ?? 'GOOD'
    missing.value = []
    damage.value = ''
    markLost.value = false
    error.value = null
  },
)

const late = computed(() => (props.transaction ? isOverdue(props.transaction) : false))
const lateDays = computed(() =>
  props.transaction ? Math.abs(daysUntilDue(props.transaction.expectedReturnDate)) : 0,
)

async function submit() {
  if (!props.transaction) return
  saving.value = true
  error.value = null
  try {
    const inspectionNotes = [
      `Condition on return: ${condition.value}`,
      missing.value.length ? `Missing accessories: ${missing.value.join(', ')}` : null,
      damage.value.trim() ? `Damage: ${damage.value.trim()}` : null,
      markLost.value ? 'Asset reported LOST.' : null,
    ]
      .filter(Boolean)
      .join(' | ')

    await inventory.processReturn({
      transactionId: props.transaction.id,
      condition: condition.value,
      inspectionNotes,
      markLost: markLost.value,
    })

    emit('update:visible', false)
    toast.add({
      severity: 'success',
      summary: 'Return recorded',
      detail: `${asset.value?.assetTag ?? 'Asset'} · ${condition.value}`,
      life: 3000,
    })
  } catch (err) {
    error.value = err?.message ?? 'Could not record return.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    header="Process return"
    :style="{ width: '36rem' }"
    :breakpoints="{ '960px': '95vw' }"
    @update:visible="emit('update:visible', $event)"
  >
    <p v-if="transaction" class="muted" style="margin-top: 0">
      {{ transaction.borrowerName }} · {{ asset?.assetTag ?? 'asset' }}
    </p>

    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 1rem">
      {{ error }}
    </Message>

    <Message v-if="late" severity="error" :closable="false" style="margin-bottom: 1rem">
      Returned {{ lateDays }} {{ lateDays === 1 ? 'day' : 'days' }} past the due date.
    </Message>

    <div class="field">
      <label for="ret-condition">Condition on return</label>
      <Select id="ret-condition" v-model="condition" :options="EQUIPMENT_CONDITIONS" fluid />
      <span class="hint">
        POOR or DAMAGED sends the asset to MAINTENANCE instead of back to the shelf.
      </span>
    </div>

    <fieldset class="accessories">
      <legend>Missing accessories</legend>
      <div v-for="item in ACCESSORIES" :key="item" class="accessory">
        <Checkbox v-model="missing" :input-id="`acc-${item}`" :value="item" />
        <label :for="`acc-${item}`">{{ item }}</label>
      </div>
    </fieldset>

    <div class="field">
      <label for="ret-damage">Damage / inspection notes</label>
      <Textarea
        id="ret-damage"
        v-model="damage"
        rows="3"
        placeholder="Cracked bezel on the top-left corner; keyboard backlight intermittent."
        fluid
      />
    </div>

    <div class="lost-toggle">
      <Checkbox v-model="markLost" input-id="ret-lost" binary />
      <label for="ret-lost">Asset was not returned — mark it <strong>LOST</strong></label>
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
        :label="saving ? 'Saving…' : 'Record return'"
        icon="pi pi-check-circle"
        :loading="saving"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.accessories {
  margin: 1rem 0;
  padding: 0;
  border: 0;
}

.accessories legend {
  padding: 0;
  margin-bottom: 0.5rem;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}

.accessory {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.2rem 0;
}

.accessory label {
  font-size: 0.8rem;
}

.lost-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
}

.lost-toggle label {
  font-size: 0.8rem;
}
</style>
