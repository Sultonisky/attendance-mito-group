export type AttendanceCorrectionType = 'clock_in' | 'clock_out' | 'both'

export type AttendanceCorrectionStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

export type AttendanceCorrectionRequest = {
  id: number
  employee_id: number
  employee_name?: string | null
  employee_code?: string | null
  attendance_record_id: number | null
  attendance_session_id: number | null
  request_type: AttendanceCorrectionType
  attendance_date: string
  requested_check_in_at: string | null
  requested_check_out_at: string | null
  reason: string
  status: AttendanceCorrectionStatus
  reviewed_by: number | null
  reviewed_at: string | null
  rejection_reason: string | null
  created_at: string | null
  updated_at: string | null
}

export type AttendanceCorrectionIndexResponse = {
  data: AttendanceCorrectionRequest[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export type CreateAttendanceCorrectionPayload = {
  request_type: AttendanceCorrectionType
  attendance_date: string
  requested_check_in_at?: string | null
  requested_check_out_at?: string | null
  reason: string
}
