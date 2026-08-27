import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import Tooltip from 'primevue/tooltip'

import 'primeicons/primeicons.css'
import './assets/main.css'

import App from './App.vue'
import router from './router'
import primeVueComponents from './plugins/primevue-components'

/**
 * Removes the offline build's local database.
 *
 * Until this version, every device that opened the app kept a full copy of the
 * inventory — assets, property numbers, accountable officers, loan history — in
 * IndexedDB, and it outlives both the sign-out and the upgrade. Dropping the
 * offline mode does not remove what earlier versions already wrote, so this
 * does, once, on first load of the new build.
 */
function dropLegacyOfflineDatabase() {
  try {
    indexedDB?.deleteDatabase('ict_inventory')
  } catch {
    /* private mode, or a browser that blocks storage access — nothing to clear */
  }
}

dropLegacyOfflineDatabase()

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: '.app-dark',
      cssLayer: {
        name: 'primevue',
        // Keeps our own layout rules winning over component defaults.
        order: 'app-base, primevue, app-utilities',
      },
    },
  },
  ripple: true,
})
app.use(ToastService)
app.use(ConfirmationService)
app.use(primeVueComponents)
app.directive('tooltip', Tooltip)

app.mount('#app')
