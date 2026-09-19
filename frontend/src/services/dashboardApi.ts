import { apiFetch } from './apiClient'
import type {
  DashboardKpiResponse,
  DashboardStaffResponse,
  DashboardTrendResponse,
  SystemHealthSnapshot,
} from '../types/dashboard'

export async function fetchDashboardKpis(): Promise<DashboardKpiResponse> {
  return apiFetch<DashboardKpiResponse>('/dashboard/kpis')
}

export async function fetchDashboardStaffToday(): Promise<DashboardStaffResponse> {
  return apiFetch<DashboardStaffResponse>('/dashboard/staff-today')
}

export async function fetchDashboardAttendanceTrend(
  from: string,
  to: string,
): Promise<DashboardTrendResponse> {
  const params = new URLSearchParams({ from, to })
  return apiFetch<DashboardTrendResponse>(`/dashboard/attendance-trend?${params}`)
}

export async function fetchSystemHealth(): Promise<SystemHealthSnapshot> {
  const refreshedAt = new Date()

  const [appResult, aiResult] = await Promise.allSettled([
    apiFetch<{ status?: string }>('/health'),
    apiFetch<{ laravel?: string; ai_status?: string }>('/ai-health'),
  ])

  const appOk = appResult.status === 'fulfilled'
  const aiOk = aiResult.status === 'fulfilled'

  return {
    app_ok: appOk,
    ai_ok: aiOk,
    overall_ok: appOk,
    refreshed_at: refreshedAt.toISOString(),
  }
}
