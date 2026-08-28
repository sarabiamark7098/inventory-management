<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'

import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/domain/loan'
import { describeAllowedDomains, emailPlaceholder, isAllowedEmail } from '@/domain/email'
import { useIsMobile } from '@/composables/useBreakpoint'
import { debounce, sequencer } from '@/domain/async'

const auth = useAuthStore()
const confirm = useConfirm()
const toast = useToast()

const isMobile = useIsMobile()

const users = ref([])
const roles = ref([])
const loading = ref(true)
const loadError = ref(null)
const search = ref('')

const dialogOpen = ref(false)
const saving = ref(false)
const formError = ref(null)
const form = ref(blank())

function blank() {
  return { id: null, name: '', email: '', role: 'MANAGER', isActive: true }
}

const allowedDomains = describeAllowedDomains()
const emailHint = allowedDomains
  ? `Sign-in codes are sent here. Only ${allowedDomains} addresses are accepted.`
  : 'Sign-in codes are sent here.'

function describe(err) {
  if (err instanceof ApiError) return err.firstValidationError() ?? err.message
  return err?.message ?? 'Something went wrong.'
}

/*
 * The account list is filtered by the server, so each change is a request and
 * two can be in flight at once. The newest search must win whichever response
 * arrives first, or the list settles on results for a term already typed past.
 */
const nextLoad = sequencer()

async function load() {
  const isCurrent = nextLoad()
  loading.value = true
  loadError.value = null
  try {
    const response = await api.users({ search: search.value })
    if (!isCurrent()) return
    users.value = response.data
    roles.value = response.roles
  } catch (err) {
    if (!isCurrent()) return
    loadError.value = describe(err)
  } finally {
    if (isCurrent()) loading.value = false
  }
}

/**
 * Searching used to need the Enter key, which is a fair way to avoid a request
 * per keystroke but leaves the list showing something other than what the box
 * says. Debounced, it keeps up on its own; Enter now just skips the wait.
 */
const queueSearch = debounce(load, 300)
watch(search, queueSearch)
onUnmounted(queueSearch.cancel)

onMounted(load)

function openCreate() {
  form.value = blank()
  formError.value = null
  dialogOpen.value = true
}

function openEdit(user) {
  form.value = {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    isActive: user.isActive,
  }
  formError.value = null
  dialogOpen.value = true
}

async function save() {
  if (!form.value.name.trim()) return (formError.value = 'Name is required.')
  if (!form.value.email.trim()) return (formError.value = 'Email is required.')
  if (!isAllowedEmail(form.value.email)) {
    return (formError.value = allowedDomains
      ? `Email must be a ${allowedDomains} address.`
      : 'Enter a valid email address.')
  }

  saving.value = true
  formError.value = null
  try {
    if (form.value.id) {
      await api.updateUser(form.value.id, {
        name: form.value.name,
        email: form.value.email,
        role: form.value.role,
        isActive: form.value.isActive,
      })
    } else {
      await api.createUser({
        name: form.value.name,
        email: form.value.email,
        role: form.value.role,
      })
    }
    dialogOpen.value = false
    await load()
    toast.add({
      severity: 'success',
      summary: form.value.id ? 'Account updated' : 'Account created',
      detail: form.value.id ? undefined : 'They can sign in with a code sent to that address.',
      life: 3000,
    })
  } catch (err) {
    formError.value = describe(err)
  } finally {
    saving.value = false
  }
}

async function toggleActive(user) {
  try {
    await api.updateUser(user.id, { isActive: !user.isActive })
    await load()
    toast.add({
      severity: 'success',
      summary: user.isActive ? 'Account deactivated' : 'Account reactivated',
      life: 2500,
    })
  } catch (err) {
    toast.add({ severity: 'error', summary: 'Could not update', detail: describe(err), life: 5000 })
  }
}

function confirmDelete(user) {
  confirm.require({
    header: 'Delete account',
    message: `Permanently delete ${user.name} (${user.email})? Their loan history stays intact.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Delete', severity: 'danger' },
    accept: async () => {
      try {
        await api.deleteUser(user.id)
        await load()
        toast.add({ severity: 'success', summary: 'Account deleted', life: 2500 })
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: 'Could not delete',
          detail: describe(err),
          life: 5000,
        })
      }
    },
  })
}

const roleSeverity = { ADMIN: 'danger', MANAGER: 'info' }
</script>

<template>
  <section>
    <div class="page-header">
      <div>
        <h1>Accounts</h1>
        <p>
          Provision accounts and set what each person can do. Sign-in is
          passwordless — everyone receives a one-time code by email.
        </p>
      </div>
      <div class="toolbar-actions">
        <Button label="Add account" icon="pi pi-user-plus" @click="openCreate" />
      </div>
    </div>

    <Message v-if="loadError" severity="error" :closable="false" class="alert">
      {{ loadError }}
    </Message>

    <Card class="filter-card">
      <template #content>
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="search"
            placeholder="Search name or email…"
            aria-label="Search accounts"
            fluid
            @keydown.enter="queueSearch.flush"
          />
        </IconField>
      </template>
    </Card>

    <div v-if="isMobile" class="record-list">
      <div v-if="loading" class="empty-state">
        <ProgressSpinner style="width: 2.5rem; height: 2.5rem" />
      </div>

      <div v-else-if="users.length === 0" class="empty-state">
        <i class="pi pi-users" />
        <p><strong>No accounts match</strong></p>
      </div>

      <article v-for="user in users" :key="user.id" class="record-card">
        <div class="record-card__head">
          <div class="record-card__title">
            <strong>
              {{ user.name }}
              <Tag v-if="user.id === auth.user?.id" value="you" severity="contrast" />
            </strong>
            <span>{{ user.email }}</span>
          </div>
          <Tag
            :value="user.isActive ? 'Active' : 'Off'"
            :severity="user.isActive ? 'success' : 'secondary'"
          />
        </div>

        <dl class="record-card__meta">
          <dt>Role</dt>
          <dd>
            <Tag :value="user.roleLabel" :severity="roleSeverity[user.role] ?? 'secondary'" />
          </dd>
          <dt>Last sign-in</dt>
          <dd>{{ user.lastLoginAt ? formatDateTime(user.lastLoginAt) : 'Never' }}</dd>
        </dl>

        <div class="record-card__actions">
          <Button
            label="Edit"
            icon="pi pi-pencil"
            size="small"
            severity="secondary"
            outlined
            @click="openEdit(user)"
          />
          <Button
            :label="user.isActive ? 'Deactivate' : 'Reactivate'"
            :icon="user.isActive ? 'pi pi-ban' : 'pi pi-check-circle'"
            size="small"
            :severity="user.isActive ? 'warn' : 'success'"
            outlined
            :disabled="user.id === auth.user?.id"
            @click="toggleActive(user)"
          />
          <Button
            label="Delete"
            icon="pi pi-trash"
            size="small"
            severity="danger"
            outlined
            :disabled="user.id === auth.user?.id"
            @click="confirmDelete(user)"
          />
        </div>
      </article>
    </div>

    <template v-else>
    <DataTable :value="users" :loading="loading" data-key="id" striped-rows paginator :rows="15">
      <template #empty>
        <div class="empty-state">
          <i class="pi pi-users" />
          <p><strong>No accounts match</strong></p>
        </div>
      </template>

      <Column field="name" header="Name" sortable>
        <template #body="{ data }">
          <div class="asset-cell">
            <strong>
              {{ data.name }}
              <Tag v-if="data.id === auth.user?.id" value="you" severity="contrast" />
            </strong>
            <span class="muted">{{ data.email }}</span>
          </div>
        </template>
      </Column>

      <Column field="role" header="Role" sortable>
        <template #body="{ data }">
          <Tag :value="data.roleLabel" :severity="roleSeverity[data.role] ?? 'secondary'" />
        </template>
      </Column>

      <Column field="isActive" header="Status" sortable>
        <template #body="{ data }">
          <Tag
            :value="data.isActive ? 'Active' : 'Deactivated'"
            :severity="data.isActive ? 'success' : 'secondary'"
          />
        </template>
      </Column>

      <Column field="lastLoginAt" header="Last sign-in" sortable>
        <template #body="{ data }">
          <span class="muted">
            {{ data.lastLoginAt ? formatDateTime(data.lastLoginAt) : 'Never' }}
          </span>
        </template>
      </Column>

      <Column header="" style="width: 10rem">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              aria-label="Edit account"
              @click="openEdit(data)"
            />
            <Button
              :icon="data.isActive ? 'pi pi-ban' : 'pi pi-check-circle'"
              text
              rounded
              size="small"
              :severity="data.isActive ? 'warn' : 'success'"
              :aria-label="data.isActive ? 'Deactivate account' : 'Reactivate account'"
              :disabled="data.id === auth.user?.id"
              @click="toggleActive(data)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Delete account"
              :disabled="data.id === auth.user?.id"
              @click="confirmDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
    </template>


    <Dialog
      v-model:visible="dialogOpen"
      modal
      :header="form.id ? 'Edit account' : 'Add account'"
      :style="{ width: '30rem' }"
      :breakpoints="{ '767px': '100vw' }"
    >
      <Message v-if="formError" severity="error" :closable="false" style="margin-bottom: 1rem">
        {{ formError }}
      </Message>

      <div class="field">
        <label for="acc-name">Full name <span class="required">*</span></label>
        <InputText id="acc-name" v-model="form.name" placeholder="Ada Lovelace" fluid autofocus />
      </div>

      <div class="field" style="margin-top: 1rem">
        <label for="acc-email">Work email <span class="required">*</span></label>
        <InputText
          id="acc-email"
          v-model="form.email"
          type="email"
          :placeholder="emailPlaceholder()"
          :invalid="Boolean(form.email.trim()) && !isAllowedEmail(form.email)"
          fluid
        />
        <span class="hint">{{ emailHint }}</span>
      </div>

      <div class="field" style="margin-top: 1rem">
        <label for="acc-role">Role</label>
        <Select
          id="acc-role"
          v-model="form.role"
          :options="roles"
          option-label="label"
          option-value="value"
          fluid
        />
        <span class="hint">
          Admins manage accounts as well. Managers register equipment, issue
          loans and record returns.
        </span>
      </div>

      <div v-if="form.id" class="active-toggle">
        <Checkbox v-model="form.isActive" input-id="acc-active" binary />
        <label for="acc-active">
          Active — deactivating signs them out of every device immediately
        </label>
      </div>

      <template #footer>
        <Button label="Cancel" text severity="secondary" :disabled="saving" @click="dialogOpen = false" />
        <Button
          :label="saving ? 'Saving…' : form.id ? 'Save changes' : 'Create account'"
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

.alert {
  margin-bottom: 1rem;
}

.row-actions {
  display: flex;
  justify-content: flex-end;
}

.active-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1.25rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.5rem;
}

.active-toggle label {
  margin: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}
</style>
