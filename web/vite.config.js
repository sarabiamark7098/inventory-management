import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  /*
   * Relative asset URLs (./assets/...) rather than root-absolute (/assets/...).
   *
   * This is what lets the same build run at the domain root, at
   * /inventory_management, or anywhere else, with no build-time knowledge of the
   * path and no environment variable to keep in sync with nginx. The browser
   * resolves them against the directory index.html was served from.
   *
   * It pairs with hash routing (see src/router/index.js): the path part of the
   * URL then never changes, so relative resolution is always correct regardless
   * of how deep a route is.
   */
  base: './',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
  },
})
