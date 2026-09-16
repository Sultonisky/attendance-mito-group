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
