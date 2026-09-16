import { apiFetch } from '../apiClient'
import type { MonthlyRecapRow } from '../../types/reports'

export async function fetchMonthlyRecaps(
  params: Record<string, string | number | boolean | null | undefined> = {},
): Promise<{ success: boolean; data: MonthlyRecapRow[]; meta?: Record<string, unknown> }> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<{ success: boolean; data: MonthlyRecapRow[]; meta?: Record<string, unknown> }>(
    `/monthly-recaps${query ? `?${query}` : ''}`,
  )
}
