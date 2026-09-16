import { apiFetch } from '../apiClient'
import type { PaginatedReportResponse, PenaltyReportRow } from '../../types/reports'

export async function fetchPenaltyReport(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<PaginatedReportResponse<PenaltyReportRow>> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<PaginatedReportResponse<PenaltyReportRow>>(
    `/reports/penalties${query ? `?${query}` : ''}`,
  )
}
