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

export type MonthlyRecapRow = {
  id: number
  employee_id: number
  period: string
  status: string
  summary: Record<string, unknown> | null
  details: unknown[]
  finalized_at: string | null
  exported_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type ReportSortOption = {
  label: string
  value: string
}
