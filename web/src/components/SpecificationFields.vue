<script setup>
import SpecificationField from './SpecificationField.vue'

const props = defineProps({
  /** Field definitions from the selected category (US-1.2 -> US-2.1). */
  fields: { type: Array, required: true },
  modelValue: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue'])

function set(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<template>
  <p v-if="fields.length === 0" class="spec-empty">
    The selected category defines no further custom fields.
  </p>

  <div v-else class="form-grid">
    <SpecificationField
      v-for="field in fields"
      :key="field.key"
      :field="field"
      :model-value="modelValue[field.key] ?? null"
      @update:model-value="set(field.key, $event)"
    />
  </div>
</template>

<style scoped>
.spec-empty {
  margin: 0;
  padding: 1rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: 0.5rem;
  text-align: center;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}
</style>
