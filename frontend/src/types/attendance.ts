export type AttendanceGeofence = {
  passed: boolean
  distance_meters: number | null
  method: string | null
}

export type AttendancePolicy = {
  allowed: boolean
  reason: string | null
  policies: unknown[]
}

export type AttendanceSession = {
  id: number
  attendance_record_id: number
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
  status: 'open' | 'closed'
  created_at: string | null
  updated_at: string | null
}

export type AttendanceRecord = {
  id: number
  employee_id: number
  attendance_date: string
  status: 'present' | 'absent' | 'late' | 'incomplete' | 'off_day' | 'holiday' | 'leave' | 'business_trip'
  sessions: AttendanceSession[]
  geofence: AttendanceGeofence | null
  policy: AttendancePolicy | null
  error: string | null
  created_at: string | null
  updated_at: string | null
}

export type AttendanceSuccessResponse = {
  success: true
  data: AttendanceRecord
}

export type AttendanceErrorResponse = {
  success: false
  data: {
    status: string
    error: string
    geofence: AttendanceGeofence | null
    policy: AttendancePolicy | null
  }
}

export type AttendanceResponse = AttendanceSuccessResponse | AttendanceErrorResponse

export type AttendanceIndexItem = {
  success: true
  data: AttendanceRecord
}

export type AttendanceIndexResponse = {
  data: AttendanceIndexItem[]
  links: unknown
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}
