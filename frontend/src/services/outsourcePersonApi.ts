import { apiFetch } from './apiClient'

export type OutsourcePersonRow = {
  id: number
  outsource_code: string
  name: string
  status: 'active' | 'inactive'
  has_password?: boolean
  city: { id: number; name: string } | null
  store: { id: number; name: string } | null
  pin_ids?: number[]
  created_at: string | null
}

export type OutsourcePersonFilters = {
  search: string
  city_id: string
  store_id: string
  status: string
  per_page: number
  sort: string
  direction: 'asc' | 'desc'
  page: number
}

export type OutsourcePersonMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type OutsourcePersonResponse = {
  success: boolean
  data: OutsourcePersonRow[]
  meta: OutsourcePersonMeta
}

export type OutsourcePersonPayload = {
  name: string
  password?: string | null
  store_id?: number | null
  pin_ids?: number[] | null
}

export type UpdateOutsourcePersonPayload = {
  name?: string
  password?: string | null
  status?: 'active' | 'inactive'
  store_id?: number | null
  pin_ids?: number[] | null
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchOutsourcePersons(
  filters: Partial<OutsourcePersonFilters>,
): Promise<OutsourcePersonResponse> {
  const params = new URLSearchParams()

  const entries: Record<string, string | number | undefined> = {
    search:    filters.search    || undefined,
    city_id:   filters.city_id   || undefined,
    store_id:  filters.store_id  || undefined,
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
  return apiFetch<OutsourcePersonResponse>(
    `/outsource-persons${query ? `?${query}` : ''}`,
  )
}

export async function fetchOutsourcePerson(id: number): Promise<{ success: boolean; data: OutsourcePersonRow }> {
  return apiFetch(`/outsource-persons/${id}`)
}

// ── CREATE ────────────────────────────────────────────────────────────────────

export async function createOutsourcePerson(
  payload: OutsourcePersonPayload,
): Promise<{ success: boolean; data: OutsourcePersonRow }> {
  return apiFetch(`/outsource-persons`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

export async function updateOutsourcePerson(
  id: number,
  payload: UpdateOutsourcePersonPayload,
): Promise<{ success: boolean; data: OutsourcePersonRow }> {
  return apiFetch(`/outsource-persons/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────────────────

export async function toggleOutsourcePersonStatus(
  id: number,
): Promise<{ success: boolean; data: { id: number; status: string } }> {
  return apiFetch(`/outsource-persons/${id}/toggle-status`, { method: 'POST' })
}

// ── DELETE ────────────────────────────────────────────────────────────────────

export async function deleteOutsourcePerson(
  id: number,
): Promise<{ success: boolean }> {
  return apiFetch(`/outsource-persons/${id}`, { method: 'DELETE' })
}
