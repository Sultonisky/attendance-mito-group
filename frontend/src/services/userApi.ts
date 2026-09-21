import { apiFetch } from './apiClient'

export type UserRole = 'SUPER_ADMIN' | 'ADMIN' | 'USER'

export type UserRow = {
  id: number
  name: string
  email: string
  status: 'active' | 'inactive'
  role: UserRole | null
  has_employee: boolean
  created_at: string | null
}

export type UserFilters = {
  search: string
  role: string
  status: string
  per_page: number
  sort: string
  direction: 'asc' | 'desc'
  page: number
}

export type UserMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type UserListResponse = {
  success: boolean
  data: UserRow[]
  meta: UserMeta
}

export type CreateUserPayload = {
  name: string
  email: string
  password: string
  role: UserRole
}

export type UpdateUserPayload = {
  name?: string
  email?: string
  password?: string | null
  role?: UserRole
  status?: 'active' | 'inactive'
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchUsers(
  filters: Partial<UserFilters>,
): Promise<UserListResponse> {
  const params = new URLSearchParams()

  const entries: Record<string, string | number | undefined> = {
    search:    filters.search    || undefined,
    role:      filters.role !== 'all' ? (filters.role || undefined) : undefined,
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
  return apiFetch<UserListResponse>(`/users${query ? `?${query}` : ''}`)
}

// ── CREATE ────────────────────────────────────────────────────────────────────

export async function createUser(
  payload: CreateUserPayload,
): Promise<{ success: boolean; data: UserRow }> {
  return apiFetch('/users', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

export async function updateUser(
  id: number,
  payload: UpdateUserPayload,
): Promise<{ success: boolean; data: UserRow }> {
  return apiFetch(`/users/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────────────────

export async function toggleUserStatus(
  id: number,
): Promise<{ success: boolean; data: { id: number; status: string } }> {
  return apiFetch(`/users/${id}/toggle-status`, { method: 'POST' })
}

// ── DELETE ────────────────────────────────────────────────────────────────────

export async function deleteUser(
  id: number,
): Promise<{ success: boolean; message?: string }> {
  return apiFetch<{ success: boolean; message?: string }>(`/users/${id}`, { method: 'DELETE' })
}

export async function fetchPermissions(): Promise<{ success: boolean; data: { id: number; name: string; description: string | null }[] }> {
  return apiFetch<{ success: boolean; data: { id: number; name: string; description: string | null }[] }>('/permissions')
}

export async function fetchUserPermissions(
  id: number,
): Promise<{ success: boolean; data: string[] }> {
  return apiFetch<{ success: boolean; data: string[] }>(`/users/${id}/permissions`, {
    method: 'GET',
  })
}

export async function syncUserPermissions(
  id: number,
  permissions: string[],
): Promise<{ success: boolean; data: string[] }> {
  return apiFetch<{ success: boolean; data: string[] }>(`/users/${id}/permissions`, {
    method: 'POST',
    body: JSON.stringify({ permissions }),
  })
}

