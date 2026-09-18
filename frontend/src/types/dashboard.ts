export type DashboardKpiData = {
  date: string
  present: number
  absent: number
  late: number
  on_leave: number
}

export type DashboardKpiResponse = {
  success: boolean
  data: DashboardKpiData
}

export type DashboardStaffRow = {
  id: number
  name: string
  email: string | null
  location: string
  status: 'Present' | 'Late' | 'On leave' | 'Absent'
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
