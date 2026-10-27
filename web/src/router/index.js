import { createRouter, createWebHashHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: 'Sign in', public: true, layout: 'blank' },
  },
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { title: 'Dashboard' },
  },
  {
    path: '/equipment',
    name: 'equipment',
    component: () => import('@/views/EquipmentView.vue'),
    meta: { title: 'Equipment' },
  },
  {
    path: '/offices',
    name: 'offices',
    component: () => import('@/views/OfficesView.vue'),
    meta: { title: 'Offices' },
  },
  {
    path: '/categories',
    name: 'categories',
    component: () => import('@/views/CategoriesView.vue'),
    meta: { title: 'Categories' },
  },
  {
    path: '/borrowing',
    name: 'borrowing',
    component: () => import('@/views/BorrowingView.vue'),
    meta: { title: 'Borrowing' },
  },
  {
    path: '/import',
    name: 'import',
    component: () => import('@/views/ImportView.vue'),
    meta: { title: 'Import', roles: ['ADMIN', 'MANAGER'] },
  },
  {
    path: '/archive',
    name: 'archive',
    component: () => import('@/views/ArchiveView.vue'),
    meta: { title: 'Archive' },
  },
  {
    path: '/history',
    name: 'history',
    component: () => import('@/views/HistoryView.vue'),
    meta: { title: 'History' },
  },
  {
    path: '/accounts',
    name: 'accounts',
    component: () => import('@/views/AccountsView.vue'),
    meta: { title: 'Accounts', roles: ['ADMIN'] },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  /*
   * Hash routing, deliberately.
   *
   * The app is served from a subdirectory (/inventory_management) on a host it
   * does not control the root of. With history routing that requires the build
   * to know its own path AND nginx to serve index.html for every unknown route —
   * two settings that must agree, and the usual cause of "works until you press
   * refresh".
   *
   * Everything after the # never reaches the server, so a deep link and a
   * refresh both work with no server-side fallback at all. The cost is a # in
   * the URL, which is a fair trade for an internal tool.
   */
  history: createWebHashHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // A persisted token has to be re-validated once before any guard can trust it.
  if (auth.restoring) await auth.restore()

  if (to.meta.public) {
    return auth.isAuthenticated && to.name === 'login' ? { name: 'dashboard' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  if (to.meta.roles && !to.meta.roles.includes(auth.user?.role)) {
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title
    ? `${to.meta.title} · ICT Equipment & Borrowing`
    : 'ICT Equipment & Borrowing'
})

export default router
