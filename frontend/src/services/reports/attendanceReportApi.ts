import { apiFetch } from '../apiClient'
import type { PaginatedReportResponse, AttendanceReportRow } from '../../types/reports'

type EmployeeAttendanceAdminBasePayload = {
  attendance_date: string
  check_in_at: string
  check_out_at?: string | null
}

export type EmployeeAttendanceAdminPayload = EmployeeAttendanceAdminBasePayload & (
  | { employee_id: number; hris_employee_id?: never; nik?: never; work_location_id?: never }
  | { employee_id?: never; hris_employee_id: string; nik: string; work_location_id: number }
)

export type EmployeeAttendanceAdminUpdatePayload = {
  check_in_at: string
  check_out_at?: string | null
}

export async function fetchAttendanceReport(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<PaginatedReportResponse<AttendanceReportRow>> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<PaginatedReportResponse<AttendanceReportRow>>(
    `/reports/attendance${query ? `?${query}` : ''}`,
  )
}

export async function createEmployeeAttendance(
  payload: EmployeeAttendanceAdminPayload,
): Promise<{ success: boolean; data: { attendance_id: number } }> {
  return apiFetch('/attendance', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateEmployeeAttendance(
  attendanceId: number,
  payload: EmployeeAttendanceAdminUpdatePayload,
): Promise<{ success: boolean; data: { attendance_id: number } }> {
  return apiFetch(`/attendance/${attendanceId}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function voidEmployeeAttendance(
  attendanceId: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/attendance/${attendanceId}/void`, { method: 'DELETE' })
}
