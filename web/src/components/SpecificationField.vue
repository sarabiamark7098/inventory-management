<script setup>
/**
 * One category-defined specification input.
 *
 * Split out of SpecificationFields so the equipment form can place a field
 * beside a related one — a host name next to the property number — without
 * nesting a second grid inside its own, and without a second copy of the
 * type handling to drift from this one.
 */
defineProps({
  field: { type: Object, required: true },
  modelValue: { type: [String, Number, Boolean, null], default: null },
})

defineEmits(['update:modelValue'])
</script>

<template>
  <div class="field" :class="{ 'field--check': field.type === 'boolean' }">
    <template v-if="field.type === 'boolean'">
      <Checkbox
        :input-id="`spec-${field.key}`"
        :model-value="Boolean(modelValue)"
        binary
        @update:model-value="$emit('update:modelValue', $event)"
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
        :model-value="modelValue ?? null"
        :options="field.options ?? []"
        show-clear
        placeholder="—"
        fluid
        @update:model-value="$emit('update:modelValue', $event)"
      />

      <InputNumber
        v-else-if="field.type === 'number'"
        :id="`spec-${field.key}`"
        :model-value="modelValue ?? null"
        :placeholder="field.placeholder"
        fluid
        @update:model-value="$emit('update:modelValue', $event)"
      />

      <DatePicker
        v-else-if="field.type === 'date'"
        :id="`spec-${field.key}`"
        :model-value="modelValue ? new Date(modelValue) : null"
        date-format="yy-mm-dd"
        show-icon
        fluid
        @update:model-value="
          $emit('update:modelValue', $event ? $event.toISOString().slice(0, 10) : null)
        "
      />

      <InputText
        v-else
        :id="`spec-${field.key}`"
        :model-value="modelValue ?? ''"
        :placeholder="field.placeholder"
        fluid
        @update:model-value="$emit('update:modelValue', $event)"
      />
    </template>
  </div>
</template>

<style scoped>
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
