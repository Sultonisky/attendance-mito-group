import { apiFetch } from './apiClient'
import type { DashboardKpiResponse } from '../types/dashboard'

export async function fetchDashboardKpis(): Promise<DashboardKpiResponse> {
  return apiFetch<DashboardKpiResponse>('/dashboard/kpis')
}
