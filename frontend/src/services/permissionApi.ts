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
}

export type CreatePermissionPayload = {
  name: string
}

export type UpdatePermissionPayload = {
  name: string
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchPermissions(): Promise<PermissionListResponse> {
  return apiFetch<PermissionListResponse>('/permissions')
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
