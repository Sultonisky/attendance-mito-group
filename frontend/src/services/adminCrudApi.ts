import { apiFetch } from './apiClient'

export type ApiEnvelope<T> = { success?: boolean; data: T }

export async function createLeaveRequest(payload: {
  leave_type_id: number
  start_date: string
  end_date: string
  reason?: string
}): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>('/leave/requests', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function approveLeaveRequest(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/leave/requests/${id}/approve`, { method: 'POST' })
}

export async function rejectLeaveRequest(id: number, reason: string): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/leave/requests/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export async function cancelLeaveRequest(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/leave/requests/${id}/cancel`, { method: 'POST' })
}

export async function createOvertimeRequest(payload: {
  attendance_id: number
  requested_minutes: number
  reason?: string
}): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>('/overtime/requests', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function approveOvertimeRequest(id: number, approved_minutes?: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/overtime/requests/${id}/approve`, {
    method: 'POST',
    body: JSON.stringify(approved_minutes === undefined ? {} : { approved_minutes }),
  })
}

export async function rejectOvertimeRequest(id: number, reason: string): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/overtime/requests/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export async function cancelOvertimeRequest(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/overtime/requests/${id}/cancel`, { method: 'POST' })
}

export async function createPenalty(payload: Record<string, unknown>): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>('/penalties', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function adjustPenalty(id: number, points: number, reason: string): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/penalties/${id}/adjust`, {
    method: 'POST',
    body: JSON.stringify({ points, reason }),
  })
}

export async function voidPenalty(id: number, reason: string): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/penalties/${id}/void`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export async function generateMonthlyRecap(payload: {
  source?: 'employee' | 'outsource'
  employee_id?: number
  outsource_id?: number
  year: number
  month: number
}): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>('/monthly-recaps/generate', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export type MonthlyRecapBulkResult = {
  period: string
  source: string
  generated: number
  skipped: number
  failed: number
  failures: Array<{ id: number; message: string }>
}

export async function generateMonthlyRecapBulk(payload: {
  source: 'employee' | 'outsource'
  year: number
  month: number
  force?: boolean
  employee_id?: number
  outsource_id?: number
}): Promise<ApiEnvelope<MonthlyRecapBulkResult>> {
  return apiFetch<ApiEnvelope<MonthlyRecapBulkResult>>('/monthly-recaps/generate-bulk', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function reviewMonthlyRecap(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/monthly-recaps/${id}/review`, { method: 'POST' })
}

export async function finalizeMonthlyRecap(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/monthly-recaps/${id}/finalize`, { method: 'POST' })
}

export async function exportMonthlyRecap(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/monthly-recaps/${id}/export`, { method: 'POST' })
}

export async function reopenMonthlyRecap(id: number): Promise<ApiEnvelope<unknown>> {
  return apiFetch<ApiEnvelope<unknown>>(`/monthly-recaps/${id}/reopen`, { method: 'POST' })
}
