import { apiFetch, apiFetchFormData } from './apiClient'
import type { AttendanceResponse, AttendanceIndexResponse, AttendanceRecord } from '../types/attendance'

export type AttendanceIndexParams = {
  from?: string
  to?: string
  status?: string
  per_page?: number
  page?: number
}

export async function fetchAttendanceIndex(
  params: number | AttendanceIndexParams = 1,
): Promise<AttendanceIndexResponse> {
  const query = new URLSearchParams()

  if (typeof params === 'number') {
    if (params > 1) {
      query.set('per_page', String(params))
    }
  } else {
    if (params.from) query.set('from', params.from)
    if (params.to) query.set('to', params.to)
    if (params.status) query.set('status', params.status)
    if (params.per_page != null) query.set('per_page', String(params.per_page))
    if (params.page != null && params.page > 1) query.set('page', String(params.page))
  }

  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiFetch<AttendanceIndexResponse>(`/attendance${suffix}`)
}

export async function fetchAttendanceToday(): Promise<AttendanceRecord | null> {
  const response = await fetchAttendanceIndex({ per_page: 5 })
  const today = localDateString(new Date())

  const match = response.data.find(
    (item) => item.data.attendance_date === today,
  )

  return match?.data ?? null
}

export async function submitCheckIn(formData: FormData): Promise<AttendanceResponse> {
  return apiFetchFormData<AttendanceResponse>('/attendance/check-in', formData, {
    method: 'POST',
  })
}

export async function submitCheckOut(formData: FormData): Promise<AttendanceResponse> {
  return apiFetchFormData<AttendanceResponse>('/attendance/check-out', formData, {
    method: 'POST',
  })
}

function localDateString(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
