import { apiFetch } from '../apiClient'
import type {
  OutsourceAttendanceReportFilters,
  OutsourceAttendanceReportRow,
  PaginatedReportResponse,
} from '../../types/reports'

export type { OutsourceAttendanceReportFilters, OutsourceAttendanceReportRow }

export type OutsourceAttendanceAdminPayload = {
  outsource_id: number
  attendance_date: string
  pin_id: number
  check_in_at: string
  check_out_at?: string | null
}

export type OutsourceAttendanceAdminUpdatePayload = {
  pin_id: number
  check_in_at: string
  check_out_at?: string | null
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

export async function createOutsourceAttendance(
  payload: OutsourceAttendanceAdminPayload,
): Promise<{ success: boolean; data: { attendance_id: number } }> {
  return apiFetch('/outsource-attendance', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateOutsourceAttendance(
  attendanceId: number,
  payload: OutsourceAttendanceAdminUpdatePayload,
): Promise<{ success: boolean; data: { attendance_id: number } }> {
  return apiFetch(`/outsource-attendance/${attendanceId}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function voidOutsourceAttendance(
  attendanceId: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-attendance/${attendanceId}/void`, { method: 'DELETE' })
}
