import { apiFetch } from './apiClient'

export type OutsourceWorkLocationRow = {
  id: number
  code: string
  name: string
  status: 'active' | 'inactive'
  city: { id: number; name: string; code: string | null } | null
  address: string
  latitude: number | null
  longitude: number | null
  radius_meters: number | null
  outsource_count: number
  created_at: string | null
}

export type OutsourceWorkLocationFilters = {
  search: string
  city_id: string
  status: string
  per_page: number
  sort: string
  direction: 'asc' | 'desc'
  page: number
}

export type OutsourceWorkLocationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type OutsourceWorkLocationResponse = {
  success: boolean
  data: OutsourceWorkLocationRow[]
  meta: OutsourceWorkLocationMeta
}

export type WorkLocationPayload = {
  name: string
  city_id?: number | null
  latitude?: number | null
  longitude?: number | null
  radius_meters?: number | null
}

export type UpdateWorkLocationPayload = {
  name?: string
  city_id?: number | null
  latitude?: number | null
  longitude?: number | null
  radius_meters?: number | null
  status?: 'active' | 'inactive'
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchOutsourceWorkLocations(
  filters: Partial<OutsourceWorkLocationFilters>,
): Promise<OutsourceWorkLocationResponse> {
  const params = new URLSearchParams()

  const entries: Record<string, string | number | undefined> = {
    search:    filters.search  || undefined,
    city_id:   filters.city_id || undefined,
    status:    filters.status !== 'all' ? (filters.status || undefined) : undefined,
    per_page:  filters.per_page,
    sort:      filters.sort,
    direction: filters.direction,
    page:      filters.page,
  }

  Object.entries(entries).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value))
  })

  const query = params.toString()
  return apiFetch<OutsourceWorkLocationResponse>(
    `/outsource-work-locations${query ? `?${query}` : ''}`,
  )
}

export async function fetchWorkLocationCities(): Promise<{ id: number; name: string; code: string | null }[]> {
  const res = await apiFetch<{ success: boolean; data: { id: number; name: string; code: string | null }[] }>(
    '/outsource-work-locations/cities',
  )
  return res.data
}

// ── CREATE ────────────────────────────────────────────────────────────────────

export async function createWorkLocation(
  payload: WorkLocationPayload,
): Promise<{ success: boolean; data: OutsourceWorkLocationRow }> {
  return apiFetch(`/outsource-work-locations`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

export async function updateWorkLocation(
  id: number,
  payload: UpdateWorkLocationPayload,
): Promise<{ success: boolean; data: OutsourceWorkLocationRow }> {
  return apiFetch(`/outsource-work-locations/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────────────────

export async function toggleWorkLocationStatus(
  id: number,
): Promise<{ success: boolean; data: { id: number; status: string } }> {
  return apiFetch(`/outsource-work-locations/${id}/toggle-status`, { method: 'POST' })
}

// ── DELETE ────────────────────────────────────────────────────────────────────

export async function deleteWorkLocation(
  id: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-work-locations/${id}`, { method: 'DELETE' })
}
