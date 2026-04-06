/**
 * LoreBuilder Router
 *
 * Auth guard: any route with meta.requiresAuth redirects to /login if the
 * user is not authenticated. The auth store is populated on app init.
 */

import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const routes = [
  // ── Public ──────────────────────────────────────────────────────────────────
  {
    path:      '/login',
    name:      'Login',
    component: () => import('@/views/LoginView.vue'),
    meta:      { requiresGuest: true },
  },
  {
    path:      '/register',
    name:      'Register',
    component: () => import('@/views/RegisterView.vue'),
    meta:      { requiresGuest: true },
  },
  {
    path:      '/invitations/:token',
    name:      'AcceptInvitation',
    component: () => import('@/views/AcceptInvitationView.vue'),
  },

  // ── Authenticated ────────────────────────────────────────────────────────────
  {
    path:      '/',
    redirect:  '/worlds',
  },
  {
    path:      '/worlds',
    name:      'WorldList',
    component: () => import('@/views/WorldListView.vue'),
    meta:      { requiresAuth: true },
  },
  {
    path:      '/worlds/new',
    name:      'WorldCreate',
    component: () => import('@/views/WorldCreateView.vue'),
    meta:      { requiresAuth: true },
  },
  {
    path:      '/worlds/:wid',
    meta:      { requiresAuth: true },
    children: [
      {
        path:      '',
        name:      'Dashboard',
        component: () => import('@/views/DashboardView.vue'),
      },
      {
        path:      'entities',
        name:      'EntityList',
        component: () => import('@/views/EntityListView.vue'),
      },
      {
        path:      'entities/new',
        name:      'EntityCreate',
        component: () => import('@/views/EntityCreateView.vue'),
      },
      {
        path:      'entities/:eid',
        name:      'EntityDetail',
        component: () => import('@/views/EntityDetailView.vue'),
      },
      {
        path:      'entities/:eid/edit',
        name:      'EntityEdit',
        component: () => import('@/views/EntityEditView.vue'),
      },
      {
        path:      'graph',
        name:      'Graph',
        component: () => import('@/views/GraphView.vue'),
      },
      {
        path:      'timeline',
        name:      'Timeline',
        component: () => import('@/views/TimelineView.vue'),
      },
      {
        path:      'arcs',
        name:      'StoryArcs',
        component: () => import('@/views/StoryArcKanban.vue'),
      },
      {
        path:      'audit-log',
        name:      'AuditLog',
        component: () => import('@/views/AuditLogView.vue'),
      },
      {
        path:      'settings/ai',
        name:      'WorldAiSettings',
        component: () => import('@/views/WorldAiSettingsView.vue'),
      },
      {
        path:      'ai/history',
        name:      'AiHistory',
        component: () => import('@/views/AiHistoryView.vue'),
      },
      {
        path:      'members',
        name:      'WorldMembers',
        component: () => import('@/views/WorldMembersView.vue'),
      },
      {
        path:      'export',
        name:      'Export',
        component: () => import('@/views/ExportView.vue'),
      },
    ],
  },

  // ── 404 fallback ─────────────────────────────────────────────────────────────
  {
    path:      '/:pathMatch(.*)*',
    name:      'NotFound',
    component: () => import('@/views/NotFoundView.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// ── Navigation Guard ──────────────────────────────────────────────────────────

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Populate session on first navigation if not yet loaded
  if (auth.user === null && !auth.loading) {
    await auth.fetchMe()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'Login', query: { redirect: to.fullPath } }
  }

  if (to.meta.requiresGuest && auth.isAuthenticated) {
    return { name: 'WorldList' }
  }
})

export default router
