import { apiFetch } from './apiClient'
import type { AuditLogListResponse, AuditLogRow } from '../types/reports'

export async function fetchAuditLogs(
  params: Record<string, string | number | boolean | null | undefined>,
): Promise<AuditLogListResponse> {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  })

  const query = search.toString()
  return apiFetch<AuditLogListResponse>(`/audit-logs${query ? `?${query}` : ''}`)
}
