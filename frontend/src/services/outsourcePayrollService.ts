import { apiFetch } from './apiClient'

export interface OutsourcePayslip {
  period: string
  outsource_id: string
  full_name: string | null
  vendor: string | null
  hke: number
  basic_salary: number
  bpjs_kesehatan_deduction: number
  loan_deduction: number
  take_home_pay: number
}

export interface OutsourceIncentive {
  period: string
  outsource_id: string
  full_name: string | null
  vendor: string | null
  umk_amount: number
  incentive_amount: number
}

interface OutsourcePayrollResponse<T> {
  success: boolean
  data: T[]
}

export async function fetchOutsourcePayslips(): Promise<OutsourcePayslip[]> {
  const response = await apiFetch<OutsourcePayrollResponse<OutsourcePayslip>>(
    '/outsource/payroll/payslips',
  )

  if (response.success !== true || !Array.isArray(response.data)) {
    throw new Error('Unexpected payslip response from Attendance API.')
  }

  return response.data
}

export async function fetchOutsourceIncentives(): Promise<OutsourceIncentive[]> {
  const response = await apiFetch<OutsourcePayrollResponse<OutsourceIncentive>>(
    '/outsource/payroll/incentives',
  )

  if (response.success !== true || !Array.isArray(response.data)) {
    throw new Error('Unexpected incentive response from Attendance API.')
  }

  return response.data
}
