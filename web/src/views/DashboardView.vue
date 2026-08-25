<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import CheckoutDialog from '@/components/CheckoutDialog.vue'
import StatusTag from '@/components/StatusTag.vue'
import { daysUntilDue, isOverdue } from '@/domain/loan'
import { useInventoryStore } from '@/stores/inventory'

const inventory = useInventoryStore()
const router = useRouter()
const checkoutOpen = ref(false)

const tiles = computed(() => [
  { label: 'Total equipment', value: inventory.stats.total, icon: 'pi pi-desktop' },
  { label: 'Available', value: inventory.stats.available, icon: 'pi pi-check-circle', tone: 'good' },
  { label: 'On loan', value: inventory.stats.onLoan, icon: 'pi pi-arrow-right-arrow-left' },
  {
    label: 'Overdue',
    value: inventory.stats.overdue,
    icon: 'pi pi-exclamation-triangle',
    tone: inventory.stats.overdue > 0 ? 'bad' : undefined,
  },
])

const secondaryTiles = computed(() =>
  [
    inventory.stats.requests > 0
      ? { label: 'Pending requests', value: inventory.stats.requests, icon: 'pi pi-inbox', tone: 'warn' }
      : null,
    inventory.stats.maintenance > 0
      ? { label: 'In maintenance', value: inventory.stats.maintenance, icon: 'pi pi-wrench', tone: 'warn' }
      : null,
    inventory.stats.conflicts > 0
      ? { label: 'Sync conflicts', value: inventory.stats.conflicts, icon: 'pi pi-flag', tone: 'bad' }
      : null,
  ].filter(Boolean),
)

const dueSoon = computed(() =>
  [...inventory.activeLoans]
    .sort((a, b) => new Date(a.expectedReturnDate) - new Date(b.expectedReturnDate))
    .slice(0, 6),
)

const recentEquipment = computed(() => inventory.equipment.slice(0, 6))

function remainingLabel(tx) {
  const remaining = daysUntilDue(tx.expectedReturnDate)
  return isOverdue(tx)
    ? `${Math.abs(remaining)} ${Math.abs(remaining) === 1 ? 'day' : 'days'} overdue`
    : `due in ${remaining} ${remaining === 1 ? 'day' : 'days'}`
}
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Dashboard</h1>
        <p>
          Live view of the local store. Everything here works without a network
          connection.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button
          label="Quick checkout"
          icon="pi pi-arrow-right-arrow-left"
          @click="checkoutOpen = true"
        />
        <Button
          label="Register asset"
          icon="pi pi-plus"
          severity="secondary"
          @click="router.push('/equipment')"
        />
      </div>
    </div>

    <div class="stat-grid">
      <Card v-for="tile in tiles" :key="tile.label">
        <template #content>
          <div class="tile">
            <span class="tile__icon"><i :class="tile.icon" /></span>
            <span>
              <span class="tile__value" :class="tile.tone ? `tile__value--${tile.tone}` : ''">
                {{ tile.value }}
              </span>
              <span class="tile__label">{{ tile.label }}</span>
            </span>
          </div>
        </template>
      </Card>
    </div>

    <div v-if="secondaryTiles.length" class="stat-grid" style="margin-top: 0.75rem">
      <Card v-for="tile in secondaryTiles" :key="tile.label">
        <template #content>
          <div class="tile">
            <span class="tile__icon"><i :class="tile.icon" /></span>
            <span>
              <span class="tile__value" :class="`tile__value--${tile.tone}`">{{ tile.value }}</span>
              <span class="tile__label">{{ tile.label }}</span>
            </span>
          </div>
        </template>
      </Card>
    </div>

    <div class="two-col">
      <Card>
        <template #title>
          <div class="section-head">
            <span>Due soonest</span>
            <Button label="View all" text size="small" @click="router.push('/borrowing')" />
          </div>
        </template>
        <template #content>
          <p v-if="dueSoon.length === 0" class="muted">Nothing is on loan right now.</p>
          <ul v-else class="list">
            <li v-for="tx in dueSoon" :key="tx.id">
              <span class="list__main">
                <strong>
                  {{ inventory.equipmentById.get(tx.equipmentId)?.assetTag ?? '—' }}
                </strong>
                <span class="muted">{{ tx.borrowerName }} · {{ remainingLabel(tx) }}</span>
              </span>
              <StatusTag kind="borrowing" :value="tx.status" :overdue="isOverdue(tx)" />
            </li>
          </ul>
        </template>
      </Card>

      <Card>
        <template #title>
          <div class="section-head">
            <span>Recently updated equipment</span>
            <Button label="View all" text size="small" @click="router.push('/equipment')" />
          </div>
        </template>
        <template #content>
          <p v-if="recentEquipment.length === 0" class="muted">No equipment registered yet.</p>
          <ul v-else class="list">
            <li v-for="item in recentEquipment" :key="item.id">
              <span class="list__main">
                <strong class="mono">{{ item.assetTag }}</strong>
                <span class="muted">
                  {{ item.modelName }}
                  <template v-if="item.categoryId">
                    · {{ inventory.categoryById.get(item.categoryId)?.name }}
                  </template>
                </span>
              </span>
              <StatusTag kind="equipment" :value="item.status" />
            </li>
          </ul>
        </template>
      </Card>
    </div>

    <CheckoutDialog v-model:visible="checkoutOpen" />
  </section>
</template>

<style scoped>
.tile {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.tile__icon {
  display: grid;
  place-items: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  background: var(--p-surface-100);
  color: var(--p-text-muted-color);
}

.tile__value {
  display: block;
  font-size: 1.5rem;
  font-weight: 600;
  line-height: 1.1;
}

.tile__value--good {
  color: var(--p-green-500);
}

.tile__value--warn {
  color: var(--p-amber-500);
}

.tile__value--bad {
  color: var(--p-red-500);
}

.tile__label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.85rem;
  font-weight: 600;
}

.list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.list li:last-child {
  border-bottom: 0;
}

.list__main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.list__main strong {
  font-size: 0.8rem;
}

.list__main .muted {
  font-size: 0.72rem;
}
</style>
