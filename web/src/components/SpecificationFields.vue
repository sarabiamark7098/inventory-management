<script setup>
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
    The selected category defines no custom fields.
  </p>

  <div v-else class="form-grid">
    <div
      v-for="field in fields"
      :key="field.key"
      class="field"
      :class="{ 'field--check': field.type === 'boolean' }"
    >
      <template v-if="field.type === 'boolean'">
        <Checkbox
          :input-id="`spec-${field.key}`"
          :model-value="Boolean(modelValue[field.key])"
          binary
          @update:model-value="set(field.key, $event)"
        />
        <label :for="`spec-${field.key}`">
          {{ field.label }}
          <span v-if="field.required" class="required">*</span>
        </label>
      </template>

      <template v-else>
        <label :for="`spec-${field.key}`">
          {{ field.label }}
          <span v-if="field.required" class="required">*</span>
        </label>

        <Select
          v-if="field.type === 'select'"
          :id="`spec-${field.key}`"
          :model-value="modelValue[field.key] ?? null"
          :options="field.options ?? []"
          show-clear
          placeholder="—"
          fluid
          @update:model-value="set(field.key, $event)"
        />

        <InputNumber
          v-else-if="field.type === 'number'"
          :id="`spec-${field.key}`"
          :model-value="modelValue[field.key] ?? null"
          :placeholder="field.placeholder"
          fluid
          @update:model-value="set(field.key, $event)"
        />

        <DatePicker
          v-else-if="field.type === 'date'"
          :id="`spec-${field.key}`"
          :model-value="modelValue[field.key] ? new Date(modelValue[field.key]) : null"
          date-format="yy-mm-dd"
          show-icon
          fluid
          @update:model-value="set(field.key, $event ? $event.toISOString().slice(0, 10) : null)"
        />

        <InputText
          v-else
          :id="`spec-${field.key}`"
          :model-value="modelValue[field.key] ?? ''"
          :placeholder="field.placeholder"
          fluid
          @update:model-value="set(field.key, $event)"
        />
      </template>
    </div>
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

.field--check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  align-self: end;
  padding-bottom: 0.4rem;
}

.field--check label {
  margin: 0;
}
</style>
