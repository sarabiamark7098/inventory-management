<script setup>
import { computed, onMounted, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import { useAuthStore } from '@/stores/auth'
import { useInventoryStore } from '@/stores/inventory'
import { formatDate, formatDateTime } from '@/domain/loan'
import { useIsMobile } from '@/composables/useBreakpoint'

const inventory = useInventoryStore()
const auth = useAuthStore()
const confirm = useConfirm()
const toast = useToast()
const isMobile = useIsMobile()

const search = ref('')
const purging = ref(null)
const restoring = ref(null)

onMounted(() => inventory.loadArchive().catch(reportFailure))

function reportFailure(err) {
  toast.add({
    severity: 'error',
    summary: 'Could not load the archive',
    detail: err?.message ?? 'Unknown error',
    life: 6000,
  })
}

const matches = (haystack) => {
  const term = search.value.trim().toLowerCase()
  return !term || haystack.filter(Boolean).join(' ').toLowerCase().includes(term)
}

const equipment = computed(() =>
  inventory.archive.equipment.filter((row) =>
    matches([row.assetTag, row.propertyNumber, row.modelName, row.brand, row.serialNumber]),
  ),
)
const categories = computed(() =>
  inventory.archive.categories.filter((row) => matches([row.name, row.description])),
)
const offices = computed(() =>
  inventory.archive.offices.filter((row) => matches([row.name, row.code])),
)
const borrowings = computed(() =>
  inventory.archive.borrowings.filter((row) => matches([row.borrowerName, row.borrowerEmail])),
)

const tabs = computed(() => [
  { key: 'equipment', label: 'Equipment', icon: 'pi pi-desktop', rows: equipment.value },
  { key: 'categories', label: 'Categories', icon: 'pi pi-tags', rows: categories.value },
  { key: 'offices', label: 'Offices', icon: 'pi pi-sitemap', rows: offices.value },
  { key: 'borrowings', label: 'Loans', icon: 'pi pi-arrow-right-arrow-left', rows: borrowings.value },
])

async function restore(row) {
  restoring.value = row.id
  try {
    await inventory.restoreEquipment(row.id)
    toast.add({
      severity: 'success',
      summary: `${row.assetTag} restored`,
      detail: 'It is back in the equipment register.',
      life: 5000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: 'Could not restore',
      detail: err?.message ?? 'Unknown error',
      life: 7000,
    })
  } finally {
    restoring.value = null
  }
}

/**
 * The confirmation names what will be lost, not just what will be deleted.
 *
 * Purging is the one action here with no undo, and the loan history it detaches
 * is the part an operator will not have thought about.
 */
function confirmPurge(row) {
  const history = row.loanCount
    ? ` ${row.loanCount} loan record${row.loanCount === 1 ? '' : 's'} will lose their link to it.`
    : ''

  confirm.require({
    header: `Permanently delete ${row.assetTag}?`,
    message:
      `This erases the record from the database. It cannot be undone, and the ` +
      `asset will no longer appear in any report.${history}`,
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: 'Delete forever',
    rejectLabel: 'Cancel',
    acceptProps: { severity: 'danger' },
    accept: () => purge(row),
  })
}

async function purge(row) {
  purging.value = row.id
  try {
    const result = await inventory.purgeEquipment(row.id)
    toast.add({
      severity: 'success',
      summary: `${result.assetTag} deleted permanently`,
      detail: result.detachedLoans
        ? `${result.detachedLoans} loan record${result.detachedLoans === 1 ? '' : 's'} detached.`
        : undefined,
      life: 5000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: 'Could not delete',
      detail: err?.message ?? 'Unknown error',
      life: 7000,
    })
  } finally {
    purging.value = null
  }
}
</script>

<template>
  <section class="view">
    <header class="view__header">
      <div>
        <h1>Archive</h1>
        <p class="muted">
          Records that were removed but kept, so the history they belong to still
          reads correctly.
          <template v-if="inventory.archivedCount">
            {{ inventory.archivedCount }} in total.
          </template>
        </p>
      </div>

      <div class="view__actions">
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText v-model="search" placeholder="Search the archive…" aria-label="Search" />
        </IconField>
        <Button
          icon="pi pi-refresh"
          severity="secondary"
          outlined
          aria-label="Reload the archive"
          :loading="inventory.archiveLoading"
          @click="inventory.loadArchive().catch(reportFailure)"
        />
      </div>
    </header>

    <Message v-if="!auth.isAdmin" severity="info" :closable="false">
      You can restore an archived asset. Deleting one permanently is reserved
      for an administrator.
    </Message>

    <div v-if="inventory.archiveLoading && !inventory.archivedCount" class="empty-state">
      <ProgressSpinner style="width: 2rem; height: 2rem" />
    </div>

    <div v-else-if="!inventory.archivedCount" class="empty-state">
      <i class="pi pi-inbox" style="font-size: 2rem" aria-hidden="true" />
      <p>Nothing has been archived.</p>
    </div>

    <Tabs v-else value="equipment">
      <TabList>
        <Tab v-for="tab in tabs" :key="tab.key" :value="tab.key">
          <i :class="tab.icon" aria-hidden="true" />
          <span class="tab-label">{{ tab.label }}</span>
          <Badge v-if="tab.rows.length" :value="tab.rows.length" severity="secondary" />
        </Tab>
      </TabList>

      <TabPanels>
        <!-- Equipment: the only tab that can dispose of what it lists. -->
        <TabPanel value="equipment">
          <p v-if="!equipment.length" class="muted">No archived equipment.</p>

          <div v-else-if="isMobile" class="card-list">
            <article v-for="row in equipment" :key="row.id" class="record-card">
              <header>
                <strong class="mono">{{ row.assetTag }}</strong>
                <span class="muted">{{ row.modelName }}</span>
              </header>
              <dl>
                <dt>Property no.</dt>
                <dd class="mono">{{ row.propertyNumber ?? '—' }}</dd>
                <dt>Division</dt>
                <dd>{{ inventory.officeLabel(row.divisionId) ?? '—' }}</dd>
                <dt>PAR issued to</dt>
                <dd>{{ row.parIssuedTo ?? '—' }}</dd>
                <dt>Archived</dt>
                <dd>{{ formatDateTime(row.deletedAt) }}</dd>
                <dt>Loan records</dt>
                <dd>{{ row.loanCount }}</dd>
              </dl>
              <Button
                label="Restore"
                icon="pi pi-undo"
                size="small"
                fluid
                :loading="restoring === row.id"
                @click="restore(row)"
              />
              <Button
                v-if="auth.isAdmin"
                label="Delete forever"
                icon="pi pi-trash"
                severity="danger"
                size="small"
                outlined
                fluid
                :disabled="row.activeLoanCount > 0"
                :loading="purging === row.id"
                @click="confirmPurge(row)"
              />
              <p v-if="row.activeLoanCount > 0" class="muted warn">
                Still out on loan - restore it rather than deleting it.
              </p>
            </article>
          </div>

          <DataTable v-else :value="equipment" size="small" data-key="id" scrollable>
            <Column field="assetTag" header="Asset" sortable>
              <template #body="{ data }">
                <div class="asset-cell">
                  <strong class="mono">{{ data.assetTag }}</strong>
                  <span class="muted">{{ data.modelName }}</span>
                </div>
              </template>
            </Column>
            <Column field="propertyNumber" header="Property no." sortable>
              <template #body="{ data }">
                <span class="mono">{{ data.propertyNumber ?? '—' }}</span>
              </template>
            </Column>
            <Column header="Office">
              <template #body="{ data }">
                <div class="asset-cell">
                  <strong>{{ inventory.officeLabel(data.divisionId) ?? '—' }}</strong>
                  <span class="muted">{{ data.parIssuedTo ?? '—' }}</span>
                </div>
              </template>
            </Column>
            <Column field="deletedAt" header="Archived" sortable>
              <template #body="{ data }">{{ formatDateTime(data.deletedAt) }}</template>
            </Column>
            <Column field="loanCount" header="Loans" sortable>
              <template #body="{ data }">
                <Tag
                  v-if="data.activeLoanCount"
                  value="On loan"
                  severity="warn"
                  v-tooltip.top="'Cannot be deleted while a borrower still holds it'"
                />
                <span v-else>{{ data.loanCount }}</span>
              </template>
            </Column>
            <Column header="" style="width: 17rem">
              <template #body="{ data }">
                <div class="row-actions">
                  <Button
                    label="Restore"
                    icon="pi pi-undo"
                    size="small"
                    :loading="restoring === data.id"
                    @click="restore(data)"
                  />
                  <Button
                    v-if="auth.isAdmin"
                    v-tooltip.left="
                      data.activeLoanCount
                        ? 'Still out on loan - restore it instead'
                        : 'Erase this record permanently'
                    "
                    label="Delete forever"
                    icon="pi pi-trash"
                    severity="danger"
                    size="small"
                    outlined
                    :disabled="data.activeLoanCount > 0"
                    :loading="purging === data.id"
                    @click="confirmPurge(data)"
                  />
                </div>
              </template>
            </Column>
          </DataTable>
        </TabPanel>

        <TabPanel value="categories">
          <p v-if="!categories.length" class="muted">No archived categories.</p>
          <DataTable v-else :value="categories" size="small" data-key="id">
            <Column field="name" header="Name" sortable />
            <Column field="description" header="Description">
              <template #body="{ data }">{{ data.description ?? '—' }}</template>
            </Column>
            <Column field="deletedAt" header="Archived" sortable>
              <template #body="{ data }">{{ formatDateTime(data.deletedAt) }}</template>
            </Column>
          </DataTable>
        </TabPanel>

        <TabPanel value="offices">
          <p v-if="!offices.length" class="muted">No archived offices.</p>
          <DataTable v-else :value="offices" size="small" data-key="id">
            <Column field="code" header="Code" sortable>
              <template #body="{ data }">
                <span class="mono">{{ data.code }}</span>
              </template>
            </Column>
            <Column field="name" header="Name" sortable />
            <Column header="Level">
              <template #body="{ data }">
                <Tag
                  :value="data.isDivision ? 'Division' : 'Section'"
                  :severity="data.isDivision ? 'info' : 'secondary'"
                />
              </template>
            </Column>
            <Column field="deletedAt" header="Archived" sortable>
              <template #body="{ data }">{{ formatDateTime(data.deletedAt) }}</template>
            </Column>
          </DataTable>
        </TabPanel>

        <TabPanel value="borrowings">
          <p v-if="!borrowings.length" class="muted">No archived loans.</p>
          <DataTable v-else :value="borrowings" size="small" data-key="id">
            <Column field="borrowerName" header="Borrower" sortable>
              <template #body="{ data }">
                <div class="asset-cell">
                  <strong>{{ data.borrowerName }}</strong>
                  <span class="muted">{{ data.borrowerEmail }}</span>
                </div>
              </template>
            </Column>
            <Column field="issueDate" header="Issued" sortable>
              <template #body="{ data }">{{ formatDate(data.issueDate) }}</template>
            </Column>
            <Column field="status" header="Status" sortable />
            <Column field="deletedAt" header="Archived" sortable>
              <template #body="{ data }">{{ formatDateTime(data.deletedAt) }}</template>
            </Column>
          </DataTable>
        </TabPanel>
      </TabPanels>
    </Tabs>
  </section>
</template>

<style scoped>
.row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.tab-label {
  margin: 0 0.4rem;
}

.record-card {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.85rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.6rem;
}

.record-card header {
  display: flex;
  flex-direction: column;
}

.record-card dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.2rem 0.75rem;
  margin: 0;
  font-size: 0.8rem;
}

.record-card dt {
  color: var(--p-text-muted-color);
}

.record-card dd {
  margin: 0;
}

.card-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.warn {
  margin: 0;
  font-size: 0.75rem;
}
</style>
