import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

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
        { path: 'outsource-attendance', name: 'outsource-attendance', component: () => import('../pages/reports/OutsourceAttendanceReportPage.vue'), meta: { title: 'Outsource attendance', permission: 'outsource_attendance.view' } },
        { path: 'reports/monthly-recaps', name: 'reports.monthly-recaps', component: () => import('../pages/reports/MonthlyRecapsReportPage.vue'), meta: { title: 'Monthly recap', permissionAny: ['monthly_recap.view', 'monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize', 'monthly_recap.export'] } },
      ],
    },
    {
      path: '/employee',
      name: 'employee-app',
      component: () => import('../pages/EmployeeAppPage.vue'),
      meta: { requiresAuth: true, employeeOnly: true },
    },
    {
      path: '/attendance',
      name: 'attendance',
      component: () => import('../pages/AttendancePage.vue'),
      meta: { requiresAuth: true, employeeOnly: true },
    },
    {
      path: '/login',
      name: 'login',
      redirect: { name: 'login.employee' },
    },
    {
      path: '/login/admin',
      name: 'login.admin',
      component: () => import('../pages/LoginPage.vue'),
      props: { audience: 'admin' },
      meta: { loginAudience: 'admin' },
    },
    {
      path: '/login/employee',
      name: 'login.employee',
      component: () => import('../pages/LoginPage.vue'),
      props: { audience: 'employee' },
      meta: { loginAudience: 'employee' },
    },
    {
      path: '/outsource',
      name: 'outsource',
      component: () => import('../pages/OutsourcePage.vue'),
      meta: { requiresAuth: false },
    },
  ],
})

/**
 * Navigation guard — UX only, NOT a security boundary.
 *
 * Backend authorization (Sanctum + Gate/Policy) remains authoritative;
 * protected API endpoints reject unauthenticated/unauthorized requests
 * regardless of this guard.
 */
router.beforeEach(async (to) => {
  if (to.path === '/outsource' || to.meta.requiresAuth === false) {
    return true
  }

  const auth = useAuthStore()

  if (!auth.isInitialized) {
    await auth.fetchCurrentUser().catch(() => undefined)
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return {
      name: to.meta.adminOnly ? 'login.admin' : 'login.employee',
    }
  }

  const isAdmin = auth.roles.some((role) => ['ADMIN', 'SUPER_ADMIN'].includes(role))

  if (to.meta.adminOnly && !isAdmin) {
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

  if (to.meta.loginAudience && auth.isAuthenticated) {
    return { name: isAdmin ? 'dashboard' : 'employee-app' }
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
