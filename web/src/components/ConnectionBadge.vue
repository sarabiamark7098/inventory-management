<script setup>
import { computed } from 'vue'

import { useConnectionStore } from '@/stores/connection'
import { useInventoryStore } from '@/stores/inventory'
import { formatDateTime } from '@/domain/loan'

const connection = useConnectionStore()
const inventory = useInventoryStore()

const tooltip = computed(() =>
  connection.online
    ? inventory.lastLoadedAt
      ? `Loaded ${formatDateTime(inventory.lastLoadedAt)} — click to refresh`
      : 'Connected — click to refresh'
    : 'No connection to the server',
)

/** Online, this is a manual refresh; offline, it is a retry. */
function onClick() {
  if (connection.online) inventory.refresh().catch(() => {})
  else connection.check()
}
</script>

<template>
  <Button
    v-tooltip.bottom="tooltip"
    :label="connection.badge.label"
    :icon="connection.badge.icon"
    :severity="connection.badge.severity"
    :loading="inventory.loading && connection.online"
    size="small"
    rounded
    outlined
    :aria-label="`Connection: ${connection.badge.label}`"
    @click="onClick"
  />
</template>
