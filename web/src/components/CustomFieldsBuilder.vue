<script setup>
import { ref } from 'vue'

import { CUSTOM_FIELD_TYPES } from '@/domain/constants'

const props = defineProps({
  modelValue: { type: Array, required: true },
})
const emit = defineEmits(['update:modelValue'])

const expanded = ref(null)

/** `Screen Size (inches)` -> `screen_size_inches` */
function toFieldKey(label) {
  return label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 60)
}

function patch(index, changes) {
  emit(
    'update:modelValue',
    props.modelValue.map((field, i) => (i === index ? { ...field, ...changes } : field)),
  )
}

function onLabelInput(index, label) {
  const field = props.modelValue[index]
  // Keep the key locked once the operator has edited it by hand.
  const key = field.key && field.key !== toFieldKey(field.label) ? field.key : toFieldKey(label)
  patch(index, { label, key })
}

function addField() {
  emit('update:modelValue', [
    ...props.modelValue,
    { key: '', label: '', type: 'text', required: false, options: [] },
  ])
  expanded.value = props.modelValue.length
}

function removeField(index) {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, i) => i !== index),
  )
  expanded.value = null
}

function move(index, delta) {
  const target = index + delta
  if (target < 0 || target >= props.modelValue.length) return
  const next = [...props.modelValue]
  ;[next[index], next[target]] = [next[target], next[index]]
  emit('update:modelValue', next)
  expanded.value = target
}

function setOptions(index, raw) {
  patch(index, {
    options: raw
      .split(',')
      .map((o) => o.trim())
      .filter(Boolean),
  })
}
</script>

<template>
  <div class="builder">
    <div class="builder__head">
      <span class="builder__title">Custom specification fields</span>
      <Button label="Add field" icon="pi pi-plus" size="small" severity="secondary" @click="addField" />
    </div>

    <p v-if="modelValue.length === 0" class="builder__empty">
      No custom fields yet. Add fields like <em>RAM</em> or <em>MAC Address</em> to
      capture category-specific specs.
    </p>

    <ul class="builder__list">
      <li v-for="(field, index) in modelValue" :key="index" class="builder__item">
        <div class="builder__row">
          <button
            type="button"
            class="builder__summary"
            @click="expanded = expanded === index ? null : index"
          >
            <i :class="expanded === index ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
            <span class="builder__label">{{ field.label || 'Untitled field' }}</span>
            <Tag :value="field.type" severity="secondary" />
            <Tag v-if="field.required" value="required" severity="danger" />
          </button>

          <div class="builder__actions">
            <Button
              icon="pi pi-arrow-up"
              text
              rounded
              size="small"
              aria-label="Move up"
              :disabled="index === 0"
              @click="move(index, -1)"
            />
            <Button
              icon="pi pi-arrow-down"
              text
              rounded
              size="small"
              aria-label="Move down"
              :disabled="index === modelValue.length - 1"
              @click="move(index, 1)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Remove field"
              @click="removeField(index)"
            />
          </div>
        </div>

        <div v-if="expanded === index" class="builder__editor">
          <div class="field">
            <label :for="`field-label-${index}`">Label</label>
            <InputText
              :id="`field-label-${index}`"
              :model-value="field.label"
              placeholder="e.g. Processor"
              fluid
              @update:model-value="onLabelInput(index, $event ?? '')"
            />
          </div>

          <div class="field">
            <label :for="`field-key-${index}`">Key (stored in specifications)</label>
            <InputText
              :id="`field-key-${index}`"
              :model-value="field.key"
              placeholder="processor"
              class="mono"
              fluid
              @update:model-value="patch(index, { key: toFieldKey($event ?? '') })"
            />
          </div>

          <div class="field">
            <label :for="`field-type-${index}`">Type</label>
            <Select
              :id="`field-type-${index}`"
              :model-value="field.type"
              :options="CUSTOM_FIELD_TYPES"
              fluid
              @update:model-value="patch(index, { type: $event })"
            />
          </div>

          <div class="field field--check">
            <Checkbox
              :input-id="`field-required-${index}`"
              :model-value="field.required"
              binary
              @update:model-value="patch(index, { required: $event })"
            />
            <label :for="`field-required-${index}`">Required when registering equipment</label>
          </div>

          <div v-if="field.type === 'select'" class="field span-2">
            <label :for="`field-options-${index}`">Dropdown options (comma separated)</label>
            <InputText
              :id="`field-options-${index}`"
              :model-value="(field.options ?? []).join(', ')"
              placeholder="8GB, 16GB, 32GB"
              fluid
              @update:model-value="setOptions(index, $event ?? '')"
            />
          </div>

          <div v-else class="field span-2">
            <label :for="`field-placeholder-${index}`">Placeholder (optional)</label>
            <InputText
              :id="`field-placeholder-${index}`"
              :model-value="field.placeholder ?? ''"
              fluid
              @update:model-value="patch(index, { placeholder: $event })"
            />
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.builder__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.builder__title {
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}

.builder__empty {
  margin: 0;
  padding: 1.25rem 1rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: 0.5rem;
  text-align: center;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.builder__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.builder__item {
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
  overflow: hidden;
}

.builder__row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.5rem;
}

.builder__summary {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
  min-width: 0;
  background: none;
  border: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  padding: 0.25rem;
}

.builder__label {
  font-size: 0.85rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.builder__actions {
  display: flex;
  flex-shrink: 0;
}

.builder__editor {
  display: grid;
  gap: 0.85rem;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  padding: 0.85rem;
  border-top: 1px solid var(--p-content-border-color);
  background: var(--p-surface-50);
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

.span-2 {
  grid-column: 1 / -1;
}
</style>
