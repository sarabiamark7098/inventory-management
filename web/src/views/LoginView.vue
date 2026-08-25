<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { describeAllowedDomains, emailPlaceholder, isAllowedEmail } from '@/domain/email'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const code = ref('')
const codeInput = ref(null)
const resendIn = ref(0)
let resendTimer = null

const allowedDomains = describeAllowedDomains()
const placeholder = emailPlaceholder()
const emailValid = computed(() => isAllowedEmail(email.value))

// Only nag once the address looks finished, not on every keystroke.
const domainMismatch = computed(() => {
  const value = email.value.trim()
  return Boolean(allowedDomains) && value.includes('@') && !emailValid.value
})

function startResendCountdown(seconds = 45) {
  resendIn.value = seconds
  clearInterval(resendTimer)
  resendTimer = setInterval(() => {
    resendIn.value -= 1
    if (resendIn.value <= 0) clearInterval(resendTimer)
  }, 1000)
}

async function submitEmail() {
  if (!emailValid.value) return
  const ok = await auth.requestCode(email.value)
  if (!ok) return
  startResendCountdown()
  await nextTick()
  codeInput.value?.$el?.querySelector('input')?.focus()
}

async function submitCode() {
  if (code.value.trim().length !== 6) return
  const ok = await auth.verifyCode(code.value)
  if (!ok) {
    code.value = ''
    return
  }
  clearInterval(resendTimer)
  router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/')
}

function useDifferentEmail() {
  code.value = ''
  clearInterval(resendTimer)
  auth.restart()
}

// Six digits is the full code, so submit without making the user press Enter.
watch(code, (value) => {
  if (value && value.trim().length === 6) submitCode()
})
</script>

<template>
  <div class="login">
    <div class="login__card">
      <div class="login__brand">
        <span class="login__mark">ICT</span>
        <div>
          <h1>Equipment &amp; Borrowing</h1>
          <p>Management System</p>
        </div>
      </div>

      <Message v-if="auth.error" severity="error" :closable="false" class="login__msg">
        {{ auth.error }}
      </Message>

      <!-- Step 1: identify -->
      <form v-if="auth.status === 'IDLE'" @submit.prevent="submitEmail">
        <p class="login__lede">
          Enter your work email and we'll send you a one-time sign-in code. No
          password to remember.
        </p>

        <div class="field">
          <label for="login-email">Work email</label>
          <InputText
            id="login-email"
            v-model="email"
            type="email"
            :placeholder="placeholder"
            autocomplete="email"
            :invalid="domainMismatch"
            autofocus
            fluid
            :disabled="auth.loading"
            :aria-describedby="allowedDomains ? 'login-email-hint' : undefined"
          />
          <span
            v-if="allowedDomains"
            id="login-email-hint"
            class="hint"
            :class="{ 'hint--error': domainMismatch }"
          >
            Only {{ allowedDomains }} addresses can sign in.
          </span>
        </div>

        <Button
          type="submit"
          label="Send sign-in code"
          icon="pi pi-envelope"
          class="login__submit"
          :loading="auth.loading"
          :disabled="!emailValid"
          fluid
        />
      </form>

      <!-- Step 2: verify -->
      <form v-else @submit.prevent="submitCode">
        <p class="login__lede">
          We sent a 6-digit code to <strong>{{ auth.pendingEmail }}</strong
          >. It expires in 10 minutes.
        </p>

        <div class="field">
          <label for="login-code">Sign-in code</label>
          <InputOtp
            id="login-code"
            ref="codeInput"
            v-model="code"
            :length="6"
            integer-only
            :disabled="auth.loading"
          />
        </div>

        <Button
          type="submit"
          label="Verify and sign in"
          icon="pi pi-sign-in"
          class="login__submit"
          :loading="auth.loading"
          :disabled="code.trim().length !== 6"
          fluid
        />

        <div class="login__actions">
          <Button
            :label="resendIn > 0 ? `Resend in ${resendIn}s` : 'Resend code'"
            text
            size="small"
            :disabled="resendIn > 0 || auth.loading"
            @click="submitEmail"
          />
          <Button label="Use a different email" text size="small" @click="useDifferentEmail" />
        </div>
      </form>

      <p class="login__foot">
        Accounts are created by your system administrator. If you don't have one
        yet, ask them to add your
        <template v-if="allowedDomains">{{ allowedDomains }}</template> address.
      </p>
    </div>
  </div>
</template>

<style scoped>
.login {
  display: grid;
  place-items: center;
  min-height: 100vh;
  padding: 1.5rem;
  background:
    radial-gradient(1200px 600px at 50% -10%, var(--p-primary-100), transparent),
    var(--p-surface-100);
}

.login__card {
  width: 100%;
  max-width: 26rem;
  padding: 2rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.9rem;
  background: var(--p-content-background);
  box-shadow: 0 12px 40px rgb(0 0 0 / 8%);
}

.login__brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.login__mark {
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.6rem;
  background: var(--p-primary-color);
  color: var(--p-primary-contrast-color);
  font-weight: 700;
  font-size: 0.8rem;
}

.login__brand h1 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.login__brand p {
  margin: 0;
  font-size: 0.78rem;
  color: var(--p-text-muted-color);
}

.login__lede {
  margin: 0 0 1.25rem;
  font-size: 0.85rem;
  line-height: 1.5;
  color: var(--p-text-muted-color);
}

.login__msg {
  margin-bottom: 1rem;
}

.login__submit {
  margin-top: 1.25rem;
}

.login__actions {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.hint--error {
  color: var(--p-red-500);
}

.login__foot {
  margin: 1.5rem 0 0;
  padding-top: 1rem;
  border-top: 1px solid var(--p-content-border-color);
  font-size: 0.72rem;
  line-height: 1.5;
  color: var(--p-text-muted-color);
}
</style>
