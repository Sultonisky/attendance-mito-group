import { apiFetch } from './apiClient'
import type {
  AttendanceCorrectionIndexResponse,
  AttendanceCorrectionRequest,
  CreateAttendanceCorrectionPayload,
} from '../types/attendanceCorrection'

export async function fetchAttendanceCorrections(params: {
  page?: number
  per_page?: number
  status?: string
  from?: string
  to?: string
  employee_id?: string | number
} = {}): Promise<AttendanceCorrectionIndexResponse> {
  const query = new URLSearchParams()
  if (params.page != null && params.page > 1) query.set('page', String(params.page))
  if (params.per_page != null) query.set('per_page', String(params.per_page))
  if (params.status) query.set('status', params.status)
  if (params.from) query.set('from', params.from)
  if (params.to) query.set('to', params.to)
  if (params.employee_id != null && params.employee_id !== '') {
    query.set('employee_id', String(params.employee_id))
  }

  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiFetch<AttendanceCorrectionIndexResponse>(`/attendance/correction-requests${suffix}`)
}

export async function createAttendanceCorrection(
  payload: CreateAttendanceCorrectionPayload,
): Promise<AttendanceCorrectionRequest> {
  const response = await apiFetch<{ data: AttendanceCorrectionRequest }>(
    '/attendance/correction-requests',
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )

  return response.data
}

export async function cancelAttendanceCorrection(
  id: number,
): Promise<AttendanceCorrectionRequest> {
  const response = await apiFetch<{ data: AttendanceCorrectionRequest }>(
    `/attendance/correction-requests/${id}/cancel`,
    { method: 'POST' },
  )

  return response.data
}

export async function approveAttendanceCorrection(
  id: number,
): Promise<AttendanceCorrectionRequest> {
  const response = await apiFetch<{ data: AttendanceCorrectionRequest }>(
    `/attendance/correction-requests/${id}/approve`,
    { method: 'POST' },
  )

  return response.data
}

export async function rejectAttendanceCorrection(
  id: number,
  reason: string,
): Promise<AttendanceCorrectionRequest> {
  const response = await apiFetch<{ data: AttendanceCorrectionRequest }>(
    `/attendance/correction-requests/${id}/reject`,
    {
      method: 'POST',
      body: JSON.stringify({ reason }),
    },
  )

  return response.data
}
