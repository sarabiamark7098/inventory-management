<script setup>
import { computed } from 'vue'

import {
  BORROWING_STATUS_LABEL,
  BORROWING_STATUS_SEVERITY,
  CONDITION_SEVERITY,
  EQUIPMENT_STATUS_SEVERITY,
} from '@/domain/constants'

const props = defineProps({
  kind: { type: String, required: true }, // 'equipment' | 'condition' | 'borrowing'
  value: { type: String, required: true },
  /** Renders an active-but-past-due loan as OVERDUE without mutating the record. */
  overdue: { type: Boolean, default: false },
})

const effective = computed(() =>
  props.kind === 'borrowing' && props.overdue ? 'OVERDUE' : props.value,
)

const severity = computed(() => {
  const map = {
    equipment: EQUIPMENT_STATUS_SEVERITY,
    condition: CONDITION_SEVERITY,
    borrowing: BORROWING_STATUS_SEVERITY,
  }[props.kind]
  return map?.[effective.value] ?? 'secondary'
})

const label = computed(() =>
  props.kind === 'borrowing'
    ? (BORROWING_STATUS_LABEL[effective.value] ?? effective.value)
    : effective.value,
)
</script>

<template>
  <Tag :value="label" :severity="severity" />
</template>
