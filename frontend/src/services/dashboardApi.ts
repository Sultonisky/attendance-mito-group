import { apiFetch } from './apiClient'
import type {
  DashboardKpiResponse,
  DashboardStaffResponse,
  DashboardTrendResponse,
  SystemHealthSnapshot,
} from '../types/dashboard'

export type DashboardSource = 'employee' | 'outsource' | 'all'

export async function fetchDashboardKpis(source: DashboardSource = 'employee'): Promise<DashboardKpiResponse> {
  const params = new URLSearchParams({ source })
  return apiFetch<DashboardKpiResponse>(`/dashboard/kpis?${params}`)
}

export async function fetchDashboardStaffToday(source: DashboardSource = 'employee'): Promise<DashboardStaffResponse> {
  const params = new URLSearchParams({ source })
  return apiFetch<DashboardStaffResponse>(`/dashboard/staff-today?${params}`)
}

export async function fetchDashboardAttendanceTrend(
  from: string,
  to: string,
  source: DashboardSource = 'employee',
): Promise<DashboardTrendResponse> {
  const params = new URLSearchParams({ from, to, source })
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
