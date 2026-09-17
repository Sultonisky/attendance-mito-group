import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginPage from '../pages/LoginPage.vue'
import DashboardPage from '../pages/DashboardPage.vue'
import EmployeeAppPage from '../pages/EmployeeAppPage.vue'
import AttendancePage from '../pages/AttendancePage.vue'
import ReportsPage from '../pages/reports/ReportsPage.vue'
import AttendanceReportPage from '../pages/reports/AttendanceReportPage.vue'
import LeaveReportPage from '../pages/reports/LeaveReportPage.vue'
import OvertimeReportPage from '../pages/reports/OvertimeReportPage.vue'
import PenaltyReportPage from '../pages/reports/PenaltyReportPage.vue'
import MonthlyRecapsReportPage from '../pages/reports/MonthlyRecapsReportPage.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: { name: 'dashboard' },
      meta: { requiresAuth: true },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: DashboardPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/employee',
      name: 'employee-app',
      component: EmployeeAppPage,
      meta: { requiresAuth: true },
    },
    {
      path: '/attendance',
      name: 'attendance',
      component: AttendancePage,
      meta: { requiresAuth: true },
    },
    {
      path: '/reports',
      name: 'reports',
      component: ReportsPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/reports/attendance',
      name: 'reports.attendance',
      component: AttendanceReportPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/reports/leave',
      name: 'reports.leave',
      component: LeaveReportPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/reports/overtime',
      name: 'reports.overtime',
      component: OvertimeReportPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/reports/penalties',
      name: 'reports.penalties',
      component: PenaltyReportPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/reports/monthly-recaps',
      name: 'reports.monthly-recaps',
      component: MonthlyRecapsReportPage,
      meta: { requiresAuth: true, adminOnly: true },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginPage,
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
  const auth = useAuthStore()

  if (!auth.isInitialized) {
    await auth.fetchCurrentUser().catch(() => undefined)
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  const isAdmin = auth.roles.some((role) => ['ADMIN', 'SUPER_ADMIN'].includes(role))

  if (to.meta.adminOnly && !isAdmin) {
    return { name: 'employee-app' }
  }

  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: isAdmin ? 'dashboard' : 'employee-app' }
  }

  return true
})

export default router
