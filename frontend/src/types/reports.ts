export type ReportMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type PaginatedReportResponse<T> = {
  success: boolean
  data: T[]
  meta: ReportMeta
}

export type AttendanceReportRow = {
  id: number
  employee_id: number
  employee_name: string
  attendance_date: string
  status: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
  created_at: string
}

export type LeaveReportRow = {
  id: number
  employee_id: number
  employee_name: string
  leave_type_id: number
  leave_type_code: string
  leave_type_name: string
  start_date: string
  end_date: string
  status: string
  reason: string
  created_at: string
}

export type OvertimeReportRow = {
  id: number
  employee_id: number
  employee_name: string
  attendance_id: number
  overtime_request_id: number | null
  date: string
  potential_minutes: number
  requested_minutes: number
  approved_minutes: number
  actual_minutes: number
  status: string
  created_at: string
}

export type PenaltyReportRow = {
  id: number
  employee_id: number
  employee_name: string
  penalty_rule_id: number
  penalty_rule_name: string
  attendance_id: number
  source: string
  violation_type: string
  violation_custom: string | null
  original_points: number
  adjusted_points: number
  final_points: number
  reason: string
  status: string
  occurred_at: string
  created_at: string
}

export type MonthlyRecapSummary = {
  scheduled_days: number
  present_days: number
  late_days: number
  incomplete_days: number
  absent_days: number
}

export type MonthlyRecapRow = {
  id: number
  source: 'employee' | 'outsource'
  employee_id: number | null
  outsource_id: number | null
  employee_code?: string | null
  outsource_code?: string | null
  subject_name?: string | null
  period: string
  status: string
  summary: MonthlyRecapSummary | null
  details: unknown[]
  finalized_at: string | null
  exported_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type OutsourceAttendanceLocationPin = {
  id: number | null
  name: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
}

export type OutsourceAttendanceLocationGps = {
  latitude: number | null
  longitude: number | null
  accuracy_meters: number | null
}

export type OutsourceAttendanceEventLocation = {
  pin: OutsourceAttendanceLocationPin | null
  gps: OutsourceAttendanceLocationGps | null
}

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
  pin: OutsourceAttendanceLocationPin | null
  check_in_location: OutsourceAttendanceEventLocation | null
  check_out_location: OutsourceAttendanceEventLocation | null
  attendance_date: string
  status: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
  session_count: number | null
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
  page?: number
  sort: string
  direction: 'asc' | 'desc'
}

export type AuditLogRow = {
  id: number
  action: string
  actor: {
    id: number | null
    name: string
    email: string | null
    kind?: 'user' | 'outsource'
  } | null
  auditable_type: string | null
  auditable_id: number | null
  old_values: Record<string, unknown> | unknown[] | null
  new_values: Record<string, unknown> | unknown[] | null
  ip_address: string | null
  user_agent: string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

export type AuditLogMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type AuditLogListResponse = {
  success: boolean
  data: AuditLogRow[]
  meta: AuditLogMeta
}
