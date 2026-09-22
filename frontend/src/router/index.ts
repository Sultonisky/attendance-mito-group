import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { usePortalAnchor } from '../composables/usePortalAnchor'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: { name: 'dashboard' },
      meta: { requiresAuth: true },
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
  if (to.path === '/outsource' || to.meta.requiresAuth === false) {
    return true
  }

  const auth = useAuthStore()
  const { getPortal, isRoleConsistentWithPortal, clearPortal } = usePortalAnchor()

  if (!auth.isInitialized) {
    await auth.fetchCurrentUser().catch(() => undefined)
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // Not authenticated — send to the login page that matches this tab's
    // portal anchor, falling back to route meta if no anchor exists yet.
    const portal = getPortal()
    if (portal === 'admin' || to.meta.adminOnly) {
      return { name: 'login.admin' }
    }
    return { name: 'login.employee' }
  }

  const isAdmin = auth.roles.some((role) => ['ADMIN', 'SUPER_ADMIN'].includes(role))

  // ── Cross-session takeover detection ──────────────────────────────────────
  // If this tab has a portal anchor but the server session now belongs to a
  // different role (another tab logged in and overwrote the cookie), we must
  // NOT silently redirect to the other portal. Instead, clear the stale
  // client state and send the user to THIS tab's correct login page.
  if (to.meta.requiresAuth && !isRoleConsistentWithPortal(isAdmin)) {
    const stalledPortal = getPortal()
    clearPortal()
    auth.clearUser()
    return stalledPortal === 'admin'
      ? { name: 'login.admin' }
      : { name: 'login.employee' }
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
 * Surface lazy-chunk load failures as router errors instead of an unhandled
 * rejection — Nuxt UI lazy pages pull heavy deps (Unovis, MapLibre) that may
 * fail on flaky networks. The caller logs via console only; no PII.
 */
router.onError((error) => {
  console.error('[router] navigation failed:', error)
})

export default router
