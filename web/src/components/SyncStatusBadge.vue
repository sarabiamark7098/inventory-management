<script setup>
import { computed, ref } from 'vue'

import { useSyncStore } from '@/stores/sync'
import { formatDateTime } from '@/domain/loan'

const sync = useSyncStore()
const panelOpen = ref(false)

const tooltip = computed(() =>
  [
    sync.lastSyncedAt ? `Last synced ${formatDateTime(sync.lastSyncedAt)}` : 'Never synced',
    sync.lastError ? `Error: ${sync.lastError}` : null,
  ]
    .filter(Boolean)
    .join(' · '),
)

function onClick() {
  // Failures need a deliberate retry; otherwise just nudge the engine.
  if (sync.failed > 0) panelOpen.value = true
  else sync.synchronize()
}
</script>

<template>
  <div>
    <Button
      v-tooltip.bottom="tooltip"
      :label="sync.badge.label"
      :icon="sync.badge.icon"
      :severity="sync.badge.severity"
      size="small"
      rounded
      outlined
      :aria-label="`Sync status: ${sync.badge.label}`"
      @click="onClick"
    />

    <Dialog
      v-model:visible="panelOpen"
      modal
      header="Sync problems"
      :style="{ width: '32rem' }"
    >
      <p class="muted" style="margin-top: 0">
        The server refused {{ sync.failed }} queued
        {{ sync.failed === 1 ? 'change' : 'changes' }}. They stay on this device
        until you retry them.
      </p>

      <Message v-if="sync.lastError" severity="error" :closable="false">
        {{ sync.lastError }}
      </Message>

      <template #footer>
        <Button label="Close" text @click="panelOpen = false" />
        <Button
          label="Retry now"
          icon="pi pi-refresh"
          @click="
            () => {
              panelOpen = false
              sync.retryFailed()
            }
          "
        />
      </template>
    </Dialog>
  </div>
</template>
