<script setup>
import { computed, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import CustomFieldsBuilder from '@/components/CustomFieldsBuilder.vue'
import { deepClone } from '@/domain/clone'
import { useInventoryStore } from '@/stores/inventory'

const inventory = useInventoryStore()
const confirm = useConfirm()
const toast = useToast()

const dialogOpen = ref(false)
const saving = ref(false)
const error = ref(null)
const form = ref(blank())

function blank() {
  return {
    id: null,
    name: '',
    description: '',
    defaultLoanDays: 7,
    requiresMonitorSerial: false,
    fields: [],
  }
}

const usage = computed(() => {
  const counts = new Map()
  for (const item of inventory.equipment) {
    if (!item.categoryId) continue
    counts.set(item.categoryId, (counts.get(item.categoryId) ?? 0) + 1)
  }
  return counts
})

function openCreate() {
  error.value = null
  form.value = blank()
  dialogOpen.value = true
}

function openEdit(category) {
  error.value = null
  form.value = {
    id: category.id,
    name: category.name,
    description: category.description ?? '',
    defaultLoanDays: category.defaultLoanDays,
    requiresMonitorSerial: Boolean(category.requiresMonitorSerial),
    fields: deepClone(category.customFields?.fields, []) ?? [],
  }
  dialogOpen.value = true
}

/** Mirrors the server's expectations before anything reaches the outbox. */
function validateFields(fields) {
  const seen = new Set()
  for (const field of fields) {
    if (!field.label.trim()) return 'Every custom field needs a label.'
    if (!field.key) return `Field "${field.label}" has no key.`
    if (seen.has(field.key)) return `Duplicate field key "${field.key}". Labels must be unique.`
    seen.add(field.key)
    if (field.type === 'select' && (field.options ?? []).length === 0) {
      return `Field "${field.label}" is a dropdown but has no options.`
    }
  }
  return null
}

async function save() {
  if (!form.value.name.trim()) {
    error.value = 'Category name is required.'
    return
  }
  const fieldError = validateFields(form.value.fields)
  if (fieldError) {
    error.value = fieldError
    return
  }

  saving.value = true
  error.value = null
  try {
    const payload = {
      name: form.value.name,
      description: form.value.description,
      defaultLoanDays: form.value.defaultLoanDays,
      requiresMonitorSerial: form.value.requiresMonitorSerial,
      customFields: { fields: form.value.fields },
    }
    if (form.value.id) await inventory.updateCategory(form.value.id, payload)
    else await inventory.createCategory(payload)

    dialogOpen.value = false
    toast.add({
      severity: 'success',
      summary: form.value.id ? 'Category updated' : 'Category created',
      detail: 'Saved.',
      life: 2500,
    })
  } catch (err) {
    error.value = err?.message ?? 'Could not save category.'
  } finally {
    saving.value = false
  }
}

function confirmDelete(category) {
  confirm.require({
    header: 'Delete category',
    message: `Soft-delete "${category.name}"? Equipment already assigned keeps its reference, and the deletion syncs to the server.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Delete', severity: 'danger' },
    accept: async () => {
      await inventory.deleteCategory(category.id)
      toast.add({ severity: 'success', summary: 'Category deleted', life: 2000 })
    },
  })
}
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Categories</h1>
        <p>
          Group equipment, define the custom specification fields each group captures,
          and set default loan durations.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button label="New category" icon="pi pi-plus" @click="openCreate" />
      </div>
    </div>

    <div v-if="inventory.loading" class="empty-state">
      <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
      <p>Loading categories…</p>
    </div>

    <Card v-else-if="inventory.categories.length === 0">
      <template #content>
        <div class="empty-state">
          <i class="pi pi-tags" />
          <p><strong>No categories yet</strong></p>
          <p>Create categories such as Laptops, Networking, or Peripherals.</p>
          <Button label="New category" icon="pi pi-plus" @click="openCreate" />
        </div>
      </template>
    </Card>

    <div v-else class="card-grid">
      <Card v-for="category in inventory.categories" :key="category.id">
        <template #title>
          <div class="cat-title">
            <span>{{ category.name }}</span>
            <span class="cat-actions">
              <Button
                icon="pi pi-pencil"
                text
                rounded
                size="small"
                :aria-label="`Edit ${category.name}`"
                @click="openEdit(category)"
              />
              <Button
                icon="pi pi-trash"
                text
                rounded
                size="small"
                severity="danger"
                :aria-label="`Delete ${category.name}`"
                @click="confirmDelete(category)"
              />
            </span>
          </div>
        </template>

        <template #content>
          <p v-if="category.description" class="muted cat-desc">{{ category.description }}</p>

          <p class="muted">
            <i class="pi pi-calendar-clock" style="margin-right: 0.35rem" />
            Default loan: {{ category.defaultLoanDays }}
            {{ category.defaultLoanDays === 1 ? 'day' : 'days' }}
          </p>

          <p v-if="category.requiresMonitorSerial" class="muted">
            <i class="pi pi-desktop" style="margin-right: 0.35rem" />
            Requires system unit + monitor serials
          </p>

          <div class="cat-fields">
            <span v-if="(category.customFields?.fields ?? []).length === 0" class="muted">
              No custom fields
            </span>
            <Tag
              v-for="field in category.customFields?.fields ?? []"
              :key="field.key"
              :value="field.key"
              severity="secondary"
            />
          </div>

          <p class="muted cat-usage">
            {{ usage.get(category.id) ?? 0 }}
            {{ (usage.get(category.id) ?? 0) === 1 ? 'item' : 'items' }} assigned
          </p>
        </template>
      </Card>
    </div>

    <Dialog
      v-model:visible="dialogOpen"
      modal
      :header="form.id ? 'Edit category' : 'New category'"
      :style="{ width: '46rem' }"
      :breakpoints="{ '960px': '95vw' }"
    >
      <p class="muted" style="margin-top: 0">
        Custom fields become the specification inputs shown when registering
        equipment in this category.
      </p>

      <Message v-if="error" severity="error" :closable="false" class="dialog-error">
        {{ error }}
      </Message>

      <div class="form-grid">
        <div class="field" style="grid-column: span 2">
          <label for="cat-name">Name <span class="required">*</span></label>
          <InputText id="cat-name" v-model="form.name" placeholder="Laptops" fluid />
        </div>

        <div class="field">
          <label for="cat-days">Default loan (days)</label>
          <InputNumber
            id="cat-days"
            v-model="form.defaultLoanDays"
            :min="0"
            :max="365"
            fluid
          />
          <span class="hint">Used to auto-fill due dates</span>
        </div>

        <div class="field span-2 monitor-toggle">
          <Checkbox
            v-model="form.requiresMonitorSerial"
            input-id="cat-monitor-serial"
            binary
          />
          <label for="cat-monitor-serial">
            Records two serial numbers — system unit and monitor
            <span class="hint">
              Tick this for desktop sets. Registering equipment in this category
              will then require both serials.
            </span>
          </label>
        </div>

        <div class="field span-2">
          <label for="cat-desc">Description</label>
          <Textarea
            id="cat-desc"
            v-model="form.description"
            rows="2"
            placeholder="Portable computers issued to staff and students."
            fluid
          />
        </div>
      </div>

      <Divider />

      <CustomFieldsBuilder v-model="form.fields" />

      <template #footer>
        <Button label="Cancel" severity="secondary" text :disabled="saving" @click="dialogOpen = false" />
        <Button
          :label="saving ? 'Saving…' : 'Save category'"
          icon="pi pi-check"
          :loading="saving"
          @click="save"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.cat-title {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.95rem;
}

.cat-actions {
  display: flex;
  flex-shrink: 0;
}

.cat-desc {
  margin-top: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.cat-fields {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin: 0.75rem 0;
}

.cat-usage {
  margin-bottom: 0;
}

.dialog-error {
  margin-bottom: 1rem;
}

.monitor-toggle {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
}

.monitor-toggle label {
  margin: 0;
  font-size: 0.82rem;
  color: var(--p-text-color);
  font-weight: 500;
}

.monitor-toggle .hint {
  display: block;
  margin-top: 0.25rem;
  font-weight: 400;
}
</style>
