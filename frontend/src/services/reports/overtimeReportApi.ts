import { apiFetch } from '../apiClient'
import type { PaginatedReportResponse, OvertimeReportRow } from '../../types/reports'

export async function fetchOvertimeReport(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<PaginatedReportResponse<OvertimeReportRow>> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<PaginatedReportResponse<OvertimeReportRow>>(
    `/reports/overtime${query ? `?${query}` : ''}`,
  )
}
