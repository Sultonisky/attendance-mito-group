import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { usePortalAnchor } from '../composables/usePortalAnchor'
import { isMaintenanceFlagActive } from '../services/maintenanceFlag'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: { name: 'outsource' },
    },
    { path: '/reports', redirect: { name: 'reports' } },
    { path: '/reports/attendance', redirect: { name: 'reports.attendance' } },
    { path: '/reports/leave', redirect: { name: 'reports.leave' } },
    { path: '/reports/overtime', redirect: { name: 'reports.overtime' } },
    { path: '/reports/penalties', redirect: { name: 'reports.penalties' } },
    { path: '/reports/monthly-recaps', redirect: { name: 'reports.monthly-recaps' } },
    { path: '/outsource-attendance', redirect: { name: 'outsource-attendance' } },
    {
      path: '/dashboard',
      component: () => import('../layouts/AdminLayout.vue'),
      meta: { requiresAuth: true, adminOnly: true },
      children: [
        { path: '', name: 'dashboard', component: () => import('../pages/DashboardPage.vue'), meta: { title: 'Dashboard', permission: 'dashboard.view' } },
        { path: 'reports', name: 'reports', component: () => import('../pages/reports/ReportsPage.vue'), meta: { title: 'Admin menu' } },
        { path: 'reports/attendance', name: 'reports.attendance', component: () => import('../pages/reports/AttendanceReportPage.vue'), meta: { title: 'Attendance', permission: 'attendance.view' } },
        { path: 'reports/leave', name: 'reports.leave', component: () => import('../pages/reports/LeaveReportPage.vue'), meta: { title: 'Leave', permission: 'leave.view' } },
        { path: 'reports/overtime', name: 'reports.overtime', component: () => import('../pages/reports/OvertimeReportPage.vue'), meta: { title: 'Overtime', permission: 'overtime.view' } },
        { path: 'reports/penalties', name: 'reports.penalties', component: () => import('../pages/reports/PenaltyReportPage.vue'), meta: { title: 'Penalties', permission: 'penalty.view' } },
        { path: 'outsource-attendance', name: 'outsource-attendance', component: () => import('../pages/reports/outsource/OutsourceAttendanceReportPage.vue'), meta: { title: 'Outsource attendance', permission: 'outsource_attendance.view' } },
        { path: 'outsource-persons', name: 'outsource.persons', component: () => import('../pages/reports/outsource/OutsourcePersonListPage.vue'), meta: { title: 'Outsource persons', permission: 'outsource_attendance.view' } },
        { path: 'outsource-work-locations', name: 'outsource.work-locations', component: () => import('../pages/reports/outsource/OutsourceWorkLocationPage.vue'), meta: { title: 'Work locations', permission: 'outsource_attendance.view' } },
        { path: 'reports/monthly-recaps', name: 'reports.monthly-recaps', component: () => import('../pages/reports/MonthlyRecapsReportPage.vue'), meta: { title: 'Monthly recap', permissionAny: ['monthly_recap.view', 'monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize', 'monthly_recap.export'] } },
        { path: 'users', name: 'users', component: () => import('../pages/users/UserListPage.vue'), meta: { title: 'User management', permission: 'user.view' } },
        { path: 'permissions', name: 'permissions', component: () => import('../pages/users/PermissionsPage.vue'), meta: { title: 'Permissions', permission: 'permission.view' } },
        { path: 'audit-logs', name: 'audit-logs', component: () => import('../pages/reports/AuditLogsPage.vue'), meta: { title: 'Audit Logs', permission: 'audit.view' } },
        { path: 'employees', name: 'employees', component: () => import('../pages/employees/EmployeeListPage.vue'), meta: { title: 'Employees', permission: 'employees.view' } },
      ],
    },
    {
      path: '/employee',
      name: 'employee-app',
      component: () => import('../pages/attendance/EmployeeAppPage.vue'),
      meta: { requiresAuth: true, employeeOnly: true, title: 'Employee App' },
    },
    {
      path: '/attendance',
      name: 'attendance',
      component: () => import('../pages/attendance/AttendancePage.vue'),
      meta: { requiresAuth: true, employeeOnly: true, title: 'Attendance Check-in' },
    },
    {
      path: '/login',
      name: 'login',
      redirect: { name: 'login.employee' },
    },
    {
      path: '/login/admin',
      name: 'login.admin',
      component: () => import('../pages/auth/AdminLoginPage.vue'),
      meta: { loginAudience: 'admin', title: 'Admin Login' },
    },
    {
      path: '/login/employee',
      name: 'login.employee',
      component: () => import('../pages/auth/EmployeeLoginPage.vue'),
      meta: { loginAudience: 'employee', title: 'Employee Login' },
    },
    {
      path: '/outsource',
      name: 'outsource',
      component: () => import('../pages/attendance/OutsourcePage.vue'),
      meta: { requiresAuth: false, title: 'Presensi Outsource' },
    },
    {
      path: '/401',
      name: 'error.unauthorized',
      component: () => import('../pages/errors/UnauthorizedPage.vue'),
      meta: { requiresAuth: false, title: '401 - Sesi Berakhir' },
    },
    {
      path: '/403',
      name: 'error.forbidden',
      component: () => import('../pages/errors/ForbiddenPage.vue'),
      meta: { requiresAuth: false, title: '403 - Akses Ditolak' },
    },
    {
      path: '/500',
      name: 'error.server',
      component: () => import('../pages/errors/ServiceUnavailablePage.vue'),
      meta: { requiresAuth: false, title: 'Sedang Dalam Pemeliharaan' },
    },
    {
      path: '/503',
      name: 'error.unavailable',
      component: () => import('../pages/errors/ServiceUnavailablePage.vue'),
      meta: { requiresAuth: false, title: 'Sedang Dalam Pemeliharaan' },
    },
    {
      path: '/404',
      name: 'error.not-found',
      component: () => import('../pages/errors/NotFoundPage.vue'),
      meta: { requiresAuth: false, title: '404 - Halaman Tidak Ditemukan' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'error.catch-all',
      component: () => import('../pages/errors/NotFoundPage.vue'),
      meta: { requiresAuth: false, title: '404 - Halaman Tidak Ditemukan' },
    },
  ],
})

/**
 * Per-page document titles.
 *
 * Every route declares its own `meta.title`; this hook applies it as the
 * HTML <title> so each page (and browser tab / bookmark) gets its own
 * title instead of the static one in index.html. The favicon stays
 * global (/images/mito.png, set in index.html).
 */
router.afterEach((to) => {
  document.title = typeof to.meta.title === 'string' && to.meta.title
    ? `${to.meta.title} | MITO Group`
    : 'MITO Group Attendance'
})

/**
 * Navigation guard — UX only, NOT a security boundary.
 *
 * Backend authorization (Sanctum + Gate/Policy) remains authoritative;
 * protected API endpoints reject unauthenticated/unauthorized requests
 * regardless of this guard.
 *
 * Cross-session isolation:
 *   Each tab stores its portal anchor ('admin'|'employee') in sessionStorage.
 *   sessionStorage is per-tab and NOT shared between tabs, so when Tab B
 *   (employee) overwrites the shared session cookie, Tab A (admin) can detect
 *   the mismatch on the next navigation/refresh and redirect to its own login
 *   page rather than silently landing in the wrong portal.
 */
router.beforeEach(async (to) => {
  // Flag written by `php artisan mito:maintenance down` — works for Vite local
  // and production SPA without waiting for an API call.
  if (to.name !== 'error.unavailable' && to.name !== 'error.server') {
    if (await isMaintenanceFlagActive()) {
      return { name: 'error.unavailable' }
    }
  }

  // Public routes (login, outsource, error pages) skip auth bootstrap.
  if (to.path === '/outsource' || to.meta.requiresAuth === false) {
    return true
  }

  const auth = useAuthStore()
  const { getPortal, isRoleConsistentWithPortal, clearPortal } = usePortalAnchor()

  if (!auth.isInitialized) {
    await auth.fetchCurrentUser().catch(() => undefined)
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // Portal anchor still set → session was expected (expired / logout elsewhere).
    // Fresh tabs without an anchor go straight to the matching login page.
    const portal = getPortal()
    if (portal !== null) {
      return { name: 'error.unauthorized' }
    }
    if (to.meta.adminOnly) {
      return { name: 'login.admin' }
    }
    return { name: 'login.employee' }
  }

  const isAdmin = auth.roles.some((role) => ['ADMIN', 'SUPER_ADMIN'].includes(role))

  // ── Cross-session takeover detection ──────────────────────────────────────
  // If this tab has a portal anchor but the server session now belongs to a
  // different role (another tab logged in and overwrote the cookie), we must
  // NOT silently redirect to the other portal. Surface a session-expired page.
  if (to.meta.requiresAuth && !isRoleConsistentWithPortal(isAdmin)) {
    clearPortal()
    auth.clearUser()
    return { name: 'error.unauthorized' }
  }

  if (to.meta.adminOnly && !isAdmin) {
    // No anchor yet (e.g. direct URL navigation) — go to employee portal.
    return { name: 'employee-app' }
  }

  const requiredPermission = to.meta.permission
  const requiredPermissions = to.meta.permissionAny
  if (isAdmin && typeof requiredPermission === 'string' && !auth.can(requiredPermission)) {
    return { name: 'dashboard' }
  }
  if (isAdmin && Array.isArray(requiredPermissions) && !requiredPermissions.some((permission) => auth.can(String(permission)))) {
    return { name: 'dashboard' }
  }

  if (to.meta.employeeOnly && isAdmin) {
    return { name: 'dashboard' }
  }

  // Redirect already-authenticated users away from the login page,
  // but ONLY if their role matches the intended audience of that portal.
  if (to.meta.loginAudience && auth.isAuthenticated) {
    const audienceMatchesRole =
      (to.meta.loginAudience === 'admin' && isAdmin) ||
      (to.meta.loginAudience === 'employee' && !isAdmin)

    if (audienceMatchesRole) {
      return { name: isAdmin ? 'dashboard' : 'employee-app' }
    }
  }

  return true
})

/**
 * Surface lazy-chunk load failures on a friendly 500 page instead of a blank
 * screen — Nuxt UI lazy pages pull heavy deps (Unovis, MapLibre) that may fail
 * on flaky networks. Log only; no PII.
 */
router.onError((error) => {
  console.error('[router] navigation failed:', error)

  const message = error instanceof Error ? error.message : String(error)
  const isChunkLoadFailure =
    message.includes('Failed to fetch dynamically imported module')
    || message.includes('Importing a module script failed')
    || /Loading chunk [\w-]+ failed/.test(message)

  if (
    isChunkLoadFailure
    && router.currentRoute.value.name !== 'error.server'
    && router.currentRoute.value.name !== 'error.unavailable'
  ) {
    void router.push({ name: 'error.unavailable' })
  }
})

export default router
