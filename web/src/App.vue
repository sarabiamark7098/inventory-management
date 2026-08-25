<script setup>
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { useInventoryStore } from '@/stores/inventory'
import { useSyncStore } from '@/stores/sync'

const route = useRoute()
const auth = useAuthStore()
const inventory = useInventoryStore()
const sync = useSyncStore()

const showShell = computed(() => route.meta.layout !== 'blank' && auth.isAuthenticated)

/**
 * The local store and sync engine only run for a signed-in user: syncing before
 * authentication would just collect 401s, and the cached catalogue belongs to
 * whoever is currently signed in.
 */
watch(
  () => auth.isAuthenticated,
  (signedIn) => {
    if (signedIn) {
      inventory.start()
      sync.start()
    } else {
      sync.stop()
      inventory.stop()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="auth.restoring" class="boot">
    <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
    <p>Restoring your session…</p>
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
</style>
