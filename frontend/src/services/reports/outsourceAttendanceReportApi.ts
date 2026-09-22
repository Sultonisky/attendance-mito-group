import { apiFetch } from '../apiClient'
import type {
  OutsourceAttendanceReportFilters,
  OutsourceAttendanceReportRow,
  PaginatedReportResponse,
} from '../../types/reports'

export type { OutsourceAttendanceReportFilters, OutsourceAttendanceReportRow }

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
