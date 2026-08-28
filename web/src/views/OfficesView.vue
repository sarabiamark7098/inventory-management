<script setup>
import { computed, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import { useInventoryStore } from '@/stores/inventory'
import { useDebounced } from '@/composables/useDebounced'

const inventory = useInventoryStore()
const confirm = useConfirm()
const toast = useToast()

const search = ref('')
// Typing stays instant; the offices are re-filtered once it stops.
const searchTerm = useDebounced(search)
const collapsed = ref(new Set())

const dialogOpen = ref(false)
const saving = ref(false)
const error = ref(null)
const form = ref(blank())

function blank(divisionId = null) {
  return { id: null, code: '', name: '', divisionId }
}

/** How many assets sit against each office, division or section. */
const usage = computed(() => {
  const counts = new Map()
  const bump = (id) => id && counts.set(id, (counts.get(id) ?? 0) + 1)
  for (const item of inventory.equipment) {
    bump(item.divisionId)
    bump(item.sectionId)
  }
  return counts
})

/**
 * Divisions with their sections nested underneath.
 *
 * A search term matches either level, but a matching section keeps its division
 * visible — an office out of its chart is meaningless.
 */
const tree = computed(() => {
  const term = searchTerm.value.trim().toLowerCase()
  const matches = (o) =>
    !term || `${o.name} ${o.code}`.toLowerCase().includes(term)

  return inventory.divisions
    .map((division) => {
      const sections = (inventory.sectionsByDivision.get(division.id) ?? [])
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name))
      const hit = matches(division)
      return {
        division,
        sections: hit ? sections : sections.filter(matches),
        matched: hit,
      }
    })
    .filter((row) => row.matched || row.sections.length > 0)
})

const totals = computed(() => ({
  divisions: inventory.divisions.length,
  sections: inventory.offices.length - inventory.divisions.length,
}))

function toggle(id) {
  const next = new Set(collapsed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  collapsed.value = next
}

function openDivision() {
  form.value = blank(null)
  error.value = null
  dialogOpen.value = true
}

function openSection(divisionId) {
  form.value = blank(divisionId)
  error.value = null
  dialogOpen.value = true
}

function openEdit(office) {
  form.value = {
    id: office.id,
    code: office.code,
    name: office.name,
    divisionId: office.divisionId ?? null,
  }
  error.value = null
  dialogOpen.value = true
}

const divisionOptions = computed(() => [
  { label: 'None — this is a division', value: null },
  ...inventory.divisions
    // An office cannot be filed under itself.
    .filter((d) => d.id !== form.value.id)
    .map((d) => ({ label: d.name, value: d.id })),
])

async function save() {
  if (!form.value.code.trim()) return (error.value = 'An office code is required.')
  if (!form.value.name.trim()) return (error.value = 'An office name is required.')

  saving.value = true
  error.value = null
  try {
    const payload = {
      code: form.value.code,
      name: form.value.name,
      divisionId: form.value.divisionId,
    }
    if (form.value.id) await inventory.updateOffice(form.value.id, payload)
    else await inventory.createOffice(payload)

    dialogOpen.value = false
    toast.add({
      severity: 'success',
      summary: form.value.id ? 'Office updated' : 'Office added',
      detail: 'Saved.',
      life: 2500,
    })
  } catch (err) {
    error.value = err?.message ?? 'Could not save the office.'
  } finally {
    saving.value = false
  }
}

function confirmDelete(office) {
  confirm.require({
    header: 'Remove office',
    message: `Remove "${office.name}"? The removal syncs on the next cycle.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Remove', severity: 'danger' },
    accept: async () => {
      try {
        await inventory.deleteOffice(office.id)
        toast.add({ severity: 'success', summary: 'Office removed', life: 2000 })
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: 'Cannot remove',
          detail: err?.message,
          life: 6000,
        })
      }
    },
  })
}
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Offices</h1>
        <p>
          The organisational chart. Divisions hold sections, and equipment is
          assigned to one of each — a division always, a section when it applies.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button label="Add division" icon="pi pi-plus" @click="openDivision" />
      </div>
    </div>

    <Card class="filter-card">
      <template #content>
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="search"
            placeholder="Search office name or code…"
            aria-label="Search offices"
            fluid
          />
        </IconField>
        <p class="muted totals">
          {{ totals.divisions }} divisions · {{ totals.sections }} sections
        </p>
      </template>
    </Card>

    <div v-if="inventory.loading" class="empty-state">
      <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
      <p>Loading the office register…</p>
    </div>

    <Card v-else-if="tree.length === 0">
      <template #content>
        <div class="empty-state">
          <i class="pi pi-sitemap" />
          <p>
            <strong>{{ search ? 'No offices match' : 'No offices yet' }}</strong>
          </p>
          <Button
            v-if="!search"
            label="Add division"
            icon="pi pi-plus"
            @click="openDivision"
          />
          <Button v-else label="Clear search" severity="secondary" @click="search = ''" />
        </div>
      </template>
    </Card>

    <div v-else class="office-tree">
      <Card v-for="row in tree" :key="row.division.id">
        <template #content>
          <div class="division">
            <button
              type="button"
              class="division__summary"
              :aria-expanded="!collapsed.has(row.division.id)"
              @click="toggle(row.division.id)"
            >
              <i
                :class="
                  collapsed.has(row.division.id) ? 'pi pi-chevron-right' : 'pi pi-chevron-down'
                "
              />
              <span class="division__name">
                <strong>{{ row.division.name }}</strong>
                <span class="muted mono">{{ row.division.code }}</span>
              </span>
            </button>

            <div class="division__meta">
              <Tag
                :value="`${row.sections.length} section${row.sections.length === 1 ? '' : 's'}`"
                severity="secondary"
              />
              <Tag
                v-if="usage.get(row.division.id)"
                :value="`${usage.get(row.division.id)} assets`"
                severity="info"
              />
              <Button
                icon="pi pi-plus"
                text
                rounded
                size="small"
                aria-label="Add section"
                v-tooltip.bottom="'Add section'"
                @click="openSection(row.division.id)"
              />
              <Button
                icon="pi pi-pencil"
                text
                rounded
                size="small"
                :aria-label="`Edit ${row.division.name}`"
                @click="openEdit(row.division)"
              />
              <Button
                icon="pi pi-trash"
                text
                rounded
                size="small"
                severity="danger"
                :aria-label="`Remove ${row.division.name}`"
                @click="confirmDelete(row.division)"
              />
            </div>
          </div>

          <ul v-if="!collapsed.has(row.division.id)" class="sections">
            <li v-if="row.sections.length === 0" class="muted section--empty">
              No sections. Equipment can still be assigned at division level.
            </li>
            <li v-for="section in row.sections" :key="section.id" class="section">
              <span class="section__name">
                {{ section.name }}
                <span class="muted mono">{{ section.code }}</span>
              </span>
              <span class="section__meta">
                <Tag
                  v-if="usage.get(section.id)"
                  :value="`${usage.get(section.id)} assets`"
                  severity="info"
                />
                <Button
                  icon="pi pi-pencil"
                  text
                  rounded
                  size="small"
                  :aria-label="`Edit ${section.name}`"
                  @click="openEdit(section)"
                />
                <Button
                  icon="pi pi-trash"
                  text
                  rounded
                  size="small"
                  severity="danger"
                  :aria-label="`Remove ${section.name}`"
                  @click="confirmDelete(section)"
                />
              </span>
            </li>
          </ul>
        </template>
      </Card>
    </div>

    <Dialog
      v-model:visible="dialogOpen"
      modal
      :header="form.id ? 'Edit office' : form.divisionId ? 'Add section' : 'Add division'"
      :style="{ width: '32rem' }"
      :breakpoints="{ '767px': '100vw' }"
    >
      <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 1rem">
        {{ error }}
      </Message>

      <div class="field">
        <label for="office-name">Name <span class="required">*</span></label>
        <InputText
          id="office-name"
          v-model="form.name"
          placeholder="FO Administrative Division"
          fluid
          autofocus
        />
      </div>

      <div class="field" style="margin-top: 1rem">
        <label for="office-code">Code <span class="required">*</span></label>
        <InputText
          id="office-code"
          v-model="form.code"
          placeholder="XI-FO-AD"
          class="mono"
          fluid
        />
        <span class="hint">Unique. Uppercased on save.</span>
      </div>

      <div class="field" style="margin-top: 1rem">
        <label for="office-parent">Belongs to</label>
        <Select
          id="office-parent"
          v-model="form.divisionId"
          :options="divisionOptions"
          option-label="label"
          option-value="value"
          fluid
        />
        <span class="hint">
          Leave as "None" for a division. Picking a division makes this a section
          within it.
        </span>
      </div>

      <template #footer>
        <Button
          label="Cancel"
          severity="secondary"
          text
          :disabled="saving"
          @click="dialogOpen = false"
        />
        <Button
          :label="saving ? 'Saving…' : 'Save office'"
          icon="pi pi-check"
          :loading="saving"
          @click="save"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.filter-card {
  margin-bottom: 1rem;
}

.totals {
  margin: 0.6rem 0 0;
}

.office-tree {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.division {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.division__summary {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex: 1;
  min-width: 0;
  padding: 0.25rem;
  background: none;
  border: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}

.division__name {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.division__name strong {
  font-size: 0.92rem;
}

.division__name .mono {
  font-size: 0.72rem;
}

.division__meta {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.sections {
  list-style: none;
  margin: 0.6rem 0 0;
  padding: 0 0 0 1.6rem;
  border-left: 2px solid var(--p-content-border-color);
}

.section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.section:last-child {
  border-bottom: 0;
}

.section__name {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 0.82rem;
}

.section__name .mono {
  font-size: 0.7rem;
}

.section__meta {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.section--empty {
  padding: 0.4rem 0;
  font-size: 0.78rem;
}

@media (max-width: 767px) {
  .division__meta,
  .section__meta {
    gap: 0.1rem;
  }

  .sections {
    padding-left: 0.9rem;
  }
}
</style>
