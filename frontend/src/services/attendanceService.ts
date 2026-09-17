import { apiFetch, apiFetchFormData } from './apiClient'
import type { AttendanceResponse, AttendanceIndexResponse, AttendanceRecord } from '../types/attendance'

export async function fetchAttendanceIndex(
  perPage = 1,
): Promise<AttendanceIndexResponse> {
  const query = perPage > 1 ? `?per_page=${perPage}` : ''
  return apiFetch<AttendanceIndexResponse>(`/attendance${query}`)
}

export async function fetchAttendanceToday(): Promise<AttendanceRecord | null> {
  const response = await fetchAttendanceIndex(5)
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
