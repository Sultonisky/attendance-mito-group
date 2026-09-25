import { apiFetch } from './apiClient'

/** One table row = one pin/address under a cabang. */
export type OutsourceWorkLocationRow = {
  id: number
  work_location_id: number
  pin_name: string
  address: string | null
  latitude: number | null
  longitude: number | null
  radius_meters: number | null
  status: 'active' | 'inactive'
  cabang: { id: number; name: string; code: string | null }
  city: { id: number; name: string; code: string | null } | null
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

export async function createWorkLocationCity(
  payload: { name: string; code?: string | null },
): Promise<{ success: boolean; data: { id: number; name: string; code: string | null } }> {
  return apiFetch('/outsource-work-locations/cities', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function createWorkLocation(
  payload: WorkLocationPayload,
): Promise<{ success: boolean; data: { id: number; name: string } }> {
  return apiFetch(`/outsource-work-locations`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateWorkLocation(
  id: number,
  payload: UpdateWorkLocationPayload,
): Promise<{ success: boolean; data: { id: number } }> {
  return apiFetch(`/outsource-work-locations/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function toggleWorkLocationStatus(
  id: number,
): Promise<{ success: boolean; data: { id: number; status: string } }> {
  return apiFetch(`/outsource-work-locations/${id}/toggle-status`, { method: 'POST' })
}

export async function deleteWorkLocation(
  id: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-work-locations/${id}`, { method: 'DELETE' })
}

export type WorkLocationPinRow = {
  id: number
  work_location_id: number
  name: string
  address: string | null
  latitude: number | null
  longitude: number | null
  radius_meters: number | null
  status: 'active' | 'inactive'
  created_at: string | null
  updated_at: string | null
}

export type WorkLocationPinPayload = {
  name: string
  address?: string | null
  latitude: number
  longitude: number
  radius_meters?: number | null
  status?: 'active' | 'inactive'
}

export async function fetchWorkLocationPins(
  workLocationId: number,
): Promise<WorkLocationPinRow[]> {
  const res = await apiFetch<{ success: boolean; data: WorkLocationPinRow[] }>(
    `/outsource-work-locations/${workLocationId}/pins`,
  )
  return Array.isArray(res.data) ? res.data : []
}

export async function createWorkLocationPin(
  workLocationId: number,
  payload: WorkLocationPinPayload,
): Promise<{ success: boolean; data: WorkLocationPinRow }> {
  return apiFetch(`/outsource-work-locations/${workLocationId}/pins`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateWorkLocationPin(
  workLocationId: number,
  pinId: number,
  payload: Partial<WorkLocationPinPayload>,
): Promise<{ success: boolean; data: WorkLocationPinRow }> {
  return apiFetch(`/outsource-work-locations/${workLocationId}/pins/${pinId}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function deleteWorkLocationPin(
  workLocationId: number,
  pinId: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-work-locations/${workLocationId}/pins/${pinId}`, {
    method: 'DELETE',
  })
}

export type WorkLocationPinOutsourceRow = {
  id: number
  name: string
  outsource_code: string
  status: string
  assignment_scope: 'pin' | 'all_cabang_pins'
}

export async function fetchWorkLocationPinOutsources(
  workLocationId: number,
  pinId: number,
): Promise<WorkLocationPinOutsourceRow[]> {
  const res = await apiFetch<{ success: boolean; data: WorkLocationPinOutsourceRow[] }>(
    `/outsource-work-locations/${workLocationId}/pins/${pinId}/outsources`,
  )
  return Array.isArray(res.data) ? res.data : []
}
