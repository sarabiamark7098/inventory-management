<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'

import SyncStatusBadge from './SyncStatusBadge.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const mobileOpen = ref(false)
const userMenu = ref(null)
const profileOpen = ref(false)
const profileName = ref('')

const links = computed(() =>
  [
    { to: '/', label: 'Dashboard', icon: 'pi pi-home' },
    { to: '/equipment', label: 'Equipment', icon: 'pi pi-desktop' },
    { to: '/categories', label: 'Categories', icon: 'pi pi-tags' },
    { to: '/borrowing', label: 'Borrowing', icon: 'pi pi-arrow-right-arrow-left' },
    // Account management is admin-only; hide what the API would refuse anyway.
    ...(auth.isAdmin ? [{ to: '/accounts', label: 'Accounts', icon: 'pi pi-users' }] : []),
  ],
)

const userMenuItems = computed(() => [
  {
    label: auth.user?.name ?? 'Account',
    items: [
      {
        label: 'Edit profile',
        icon: 'pi pi-user-edit',
        command: () => {
          profileName.value = auth.user?.name ?? ''
          profileOpen.value = true
        },
      },
      {
        label: 'Sign out',
        icon: 'pi pi-sign-out',
        command: signOut,
      },
    ],
  },
])

function isActive(to) {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

function go(to) {
  mobileOpen.value = false
  router.push(to)
}

async function signOut() {
  await auth.logout()
  router.replace({ name: 'login' })
}

async function saveProfile() {
  if (!profileName.value.trim()) return
  if (await auth.updateProfile(profileName.value.trim())) {
    profileOpen.value = false
    toast.add({ severity: 'success', summary: 'Profile updated', life: 2000 })
  }
}
</script>

<template>
  <header class="app-header">
    <div class="app-header__inner">
      <Button
        class="app-header__toggle"
        text
        rounded
        :icon="mobileOpen ? 'pi pi-times' : 'pi pi-bars'"
        :aria-label="mobileOpen ? 'Close navigation' : 'Open navigation'"
        @click="mobileOpen = !mobileOpen"
      />

      <RouterLink to="/" class="app-header__brand">
        <span class="app-header__mark">ICT</span>
        <span class="app-header__title">Equipment &amp; Borrowing</span>
      </RouterLink>

      <nav class="app-header__nav">
        <Button
          v-for="link in links"
          :key="link.to"
          :label="link.label"
          :icon="link.icon"
          :text="!isActive(link.to)"
          :severity="isActive(link.to) ? 'primary' : 'secondary'"
          size="small"
          @click="go(link.to)"
        />
      </nav>

      <div class="app-header__status">
        <SyncStatusBadge />

        <Button
          class="app-header__user"
          text
          rounded
          aria-haspopup="true"
          aria-controls="user-menu"
          :aria-label="`Account menu for ${auth.user?.name ?? 'user'}`"
          @click="userMenu.toggle($event)"
        >
          <Avatar :label="auth.initials" shape="circle" size="normal" />
        </Button>
        <Menu id="user-menu" ref="userMenu" :model="userMenuItems" popup>
          <template #start>
            <div class="user-card">
              <strong>{{ auth.user?.name }}</strong>
              <span>{{ auth.user?.email }}</span>
              <Tag :value="auth.user?.roleLabel" severity="secondary" />
            </div>
          </template>
        </Menu>
      </div>
    </div>

    <nav v-if="mobileOpen" class="app-header__mobile">
      <Button
        v-for="link in links"
        :key="link.to"
        :label="link.label"
        :icon="link.icon"
        :text="!isActive(link.to)"
        :severity="isActive(link.to) ? 'primary' : 'secondary'"
        size="small"
        @click="go(link.to)"
      />
    </nav>

    <Dialog v-model:visible="profileOpen" modal header="Edit profile" :style="{ width: '24rem' }">
      <div class="field">
        <label for="profile-name">Display name</label>
        <InputText id="profile-name" v-model="profileName" fluid autofocus />
      </div>
      <div class="field" style="margin-top: 1rem">
        <label>Email</label>
        <InputText :model-value="auth.user?.email" disabled fluid />
        <span class="hint">
          Your email is your sign-in identity — ask an admin to change it.
        </span>
      </div>

      <template #footer>
        <Button label="Cancel" text severity="secondary" @click="profileOpen = false" />
        <Button label="Save" icon="pi pi-check" :loading="auth.loading" @click="saveProfile" />
      </template>
    </Dialog>
  </header>
</template>

<style scoped>
.app-header {
  position: sticky;
  top: 0;
  z-index: 30;
  border-bottom: 1px solid var(--p-content-border-color);
  background: var(--p-content-background);
}

.app-header__inner {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  max-width: var(--app-shell-max);
  margin: 0 auto;
  padding: 0.5rem 1rem;
}

.app-header__brand {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  text-decoration: none;
  color: inherit;
}

.app-header__mark {
  display: grid;
  place-items: center;
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 0.4rem;
  background: var(--p-primary-color);
  color: var(--p-primary-contrast-color);
  font-size: 0.68rem;
  font-weight: 700;
}

.app-header__title {
  font-size: 0.9rem;
  font-weight: 600;
}

.app-header__nav {
  display: none;
  gap: 0.25rem;
  margin-left: 0.75rem;
}

.app-header__status {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: auto;
}

.app-header__user {
  padding: 0.15rem;
}

.app-header__toggle {
  display: inline-flex;
}

.app-header__mobile {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.25rem;
  padding: 0.5rem 1rem 0.75rem;
  border-top: 1px solid var(--p-content-border-color);
}

.user-card {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  align-items: flex-start;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.user-card strong {
  font-size: 0.85rem;
}

.user-card span {
  font-size: 0.72rem;
  color: var(--p-text-muted-color);
}

@media (min-width: 980px) {
  .app-header__nav {
    display: flex;
  }

  .app-header__toggle,
  .app-header__mobile {
    display: none;
  }
}

@media (max-width: 560px) {
  .app-header__title {
    display: none;
  }
}
</style>
