import { apiFetch } from './apiClient'

export type PermissionRow = {
  id: number
  name: string
  guard_name: string
  description: string | null
  created_at: string | null
  updated_at: string | null
}

export type PermissionMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type PermissionListResponse = {
  success: boolean
  data: PermissionRow[]
  meta: PermissionMeta
}

export type CreatePermissionPayload = {
  name: string
}

export type UpdatePermissionPayload = {
  name: string
}

export type PermissionUser = {
  id: number
  name: string
  email: string
  status: string
}

export type PermissionUsersResponse = {
  success: boolean
  data: PermissionUser[]
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchPermissions(filters?: {
  search?: string
  per_page?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
}): Promise<PermissionListResponse> {
  const params = new URLSearchParams()
  if (filters?.search) params.set('search', filters.search)
  if (filters?.per_page) params.set('per_page', String(filters.per_page))
  if (filters?.sort) params.set('sort', filters.sort)
  if (filters?.direction) params.set('direction', filters.direction)
  if (filters?.page) params.set('page', String(filters.page))

  const query = params.toString()
  return apiFetch<PermissionListResponse>(`/permissions${query ? `?${query}` : ''}`)
}

export async function fetchPermissionUsers(
  id: number,
): Promise<PermissionUsersResponse> {
  return apiFetch<PermissionUsersResponse>(`/permissions/${id}/users`)
}

// ── CREATE ────────────────────────────────────────────────────────────────────

export async function createPermission(
  payload: CreatePermissionPayload,
): Promise<{ success: boolean; data: PermissionRow }> {
  return apiFetch<{ success: boolean; data: PermissionRow }>('/permissions', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

export async function updatePermission(
  id: number,
  payload: UpdatePermissionPayload,
): Promise<{ success: boolean; data: PermissionRow }> {
  return apiFetch<{ success: boolean; data: PermissionRow }>(`/permissions/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

// ── DELETE ────────────────────────────────────────────────────────────────────

export async function deletePermission(
  id: number,
): Promise<{ success: boolean }> {
  return apiFetch<{ success: boolean }>(`/permissions/${id}`, { method: 'DELETE' })
}
