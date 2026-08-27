<script setup>
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useConnectionStore } from '@/stores/connection'
import { useInventoryStore } from '@/stores/inventory'

const route = useRoute()
const auth = useAuthStore()
const inventory = useInventoryStore()
const connection = useConnectionStore()

const showShell = computed(() => route.meta.layout !== 'blank' && auth.isAuthenticated)

connection.start()

/**
 * Inventory data is loaded for a signed-in user and dropped on sign-out.
 *
 * Nothing is cached between sessions — the store is memory only — so a shared
 * workstation cannot leak the previous user's catalogue and loan history.
 */
watch(
  () => auth.isAuthenticated,
  (signedIn) => {
    if (signedIn) inventory.start()
    else inventory.stop()
  },
  { immediate: true },
)

/** Reconnecting reloads: whatever changed while we were away is now visible. */
watch(
  () => connection.online,
  (online) => {
    if (online && auth.isAuthenticated) inventory.refresh().catch(() => {})
  },
)
</script>

<template>
  <div v-if="auth.restoring" class="boot">
    <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
    <p>Restoring your session…</p>
  </div>

  <!--
    The connection gate. This system has no offline mode: every screen reads
    and writes straight through the API, so a lost connection means the app
    cannot show current data or accept a change. Blocking is the honest
    response — a form that looks like it saved and did not is worse than one
    that refuses to open.
  -->
  <div v-else-if="!connection.online" class="offline-gate">
    <i class="pi pi-wifi offline-icon" aria-hidden="true" />
    <h1>No connection</h1>
    <p>
      This system needs an internet connection to the inventory server. Nothing
      is stored on this device, so it cannot be used until the connection is
      back.
    </p>
    <Button
      label="Try again"
      icon="pi pi-refresh"
      :loading="connection.checking"
      @click="connection.check()"
    />
    <p class="offline-hint">Retrying automatically every few seconds.</p>
  </div>

  <div v-else-if="showShell" class="app-shell">
    <AppHeader />
    <main class="app-main">
      <RouterView />
    </main>
  </div>

  <RouterView v-else />

  <Toast position="bottom-right" />
  <ConfirmDialog />
</template>

<style scoped>
.boot {
  display: grid;
  place-items: center;
  gap: 0.75rem;
  min-height: 100vh;
  color: var(--p-text-muted-color);
  font-size: 0.85rem;
}

.offline-gate {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.85rem;
  min-height: 100vh;
  padding: 1.5rem;
  text-align: center;
}

.offline-icon {
  font-size: 2.5rem;
  color: var(--p-red-500);
}

.offline-gate h1 {
  margin: 0;
  font-size: 1.35rem;
}

.offline-gate p {
  max-width: 30rem;
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.9rem;
  line-height: 1.55;
}

.offline-hint {
  font-size: 0.78rem;
  opacity: 0.75;
}
</style>
