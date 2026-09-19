import { apiFetch } from '../apiClient'
import type { PaginatedReportResponse } from '../../types/reports'

export type OutsourceAttendanceReportRow = {
  attendance_id: number
  outsource: {
    id: number
    name: string
    code: string
  } | null
  city: {
    id: number | null
    name: string
  } | null
  store: {
    id: number
    name: string
  } | null
  attendance_date: string
  status: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
}

export type OutsourceAttendanceReportFilters = {
  from: string
  to: string
  city_id: string
  store_id: string
  outsource_id: string
  status: string
  search: string
  per_page: number
  sort: string
  direction: 'asc' | 'desc'
}

export async function fetchOutsourceAttendanceReport(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<PaginatedReportResponse<OutsourceAttendanceReportRow>> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<PaginatedReportResponse<OutsourceAttendanceReportRow>>(
    `/reports/outsource-attendance${query ? `?${query}` : ''}`,
  )
}

export async function voidOutsourceAttendance(
  attendanceId: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-attendance/${attendanceId}/void`, { method: 'DELETE' })
}
