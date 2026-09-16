import { apiFetch } from '../apiClient'
import type { PaginatedReportResponse, LeaveReportRow } from '../../types/reports'

export async function fetchLeaveReport(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<PaginatedReportResponse<LeaveReportRow>> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<PaginatedReportResponse<LeaveReportRow>>(
    `/reports/leave${query ? `?${query}` : ''}`,
  )
}
