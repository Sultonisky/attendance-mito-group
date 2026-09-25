export type DashboardKpiData = {
  date: string
  present: number
  absent: number
  late: number
  on_leave: number
  incomplete: number
}

export type DashboardKpiResponse = {
  success: boolean
  data: DashboardKpiData
}

export type DashboardStaffRow = {
  id: number
  code: string
  name: string
  email: string | null
  location: string
  status: 'Present' | 'Late' | 'On leave' | 'Absent' | 'Incomplete'
}

export type DashboardStaffResponse = {
  success: boolean
  data: DashboardStaffRow[]
}

export type DashboardTrendPoint = {
  date: string
  present: number
  absent: number
  late: number
  on_leave: number
  rate: number
}

export type DashboardTrendResponse = {
  success: boolean
  data: DashboardTrendPoint[]
}

export type SystemHealthSnapshot = {
  app_ok: boolean
  ai_ok: boolean
  overall_ok: boolean
  refreshed_at: string
}

export type SystemServiceStatus = 'ok' | 'warn' | 'fail'

export type SystemServiceRow = {
  key: string
  label: string
  status: SystemServiceStatus
  detail: string
}

export type SystemRuntimeInfo = {
  environment: string
  debug: boolean
  laravel_version: string
  php_version: string
  timezone: string
  maintenance: boolean
}

export type SystemsHealthData = {
  overall_status: 'ok' | 'degraded' | 'fail'
  refreshed_at: string
  services: SystemServiceRow[]
  runtime: SystemRuntimeInfo
}

export type SystemHealthResponse = {
  success: boolean
  data: SystemsHealthData
}
