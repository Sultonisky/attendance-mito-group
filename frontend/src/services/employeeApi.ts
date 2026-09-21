import { apiFetch } from './apiClient'

export type EmployeeRow = {
  id: number
  employee_code: string
  full_name: string
  email: string | null
  phone: string | null
  employment_status: string
  join_date: string | null
  end_date: string | null
  job_position: string | null
  department: string | null
  division: string | null
  branch: string | null
  job_level: string | null
  grade: string | null
  direct_superior_id: number | null
  indirect_superior_id: number | null
  user_id: number | null
  created_at: string | null
  updated_at: string | null
}

export type EmployeeMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type EmployeeListResponse = {
  success: boolean
  data: EmployeeRow[]
  meta: EmployeeMeta
}

export type CreateEmployeePayload = {
  employee_code: string
  full_name: string
  email?: string | null
  phone?: string | null
  employment_status: string
  join_date: string
  end_date?: string | null
  job_position?: string | null
  department?: string | null
  division?: string | null
  branch?: string | null
  job_level?: string | null
  grade?: string | null
  direct_superior_id?: number | null
  indirect_superior_id?: number | null
  user_id?: number | null
}

export type UpdateEmployeePayload = {
  employee_code?: string
  full_name?: string
  email?: string | null
  phone?: string | null
  employment_status?: string
  join_date?: string
  end_date?: string | null
  job_position?: string | null
  department?: string | null
  division?: string | null
  branch?: string | null
  job_level?: string | null
  grade?: string | null
  direct_superior_id?: number | null
  indirect_superior_id?: number | null
  user_id?: number | null
}

// ── READ ──────────────────────────────────────────────────────────────────────

export async function fetchEmployees(filters?: {
  search?: string
  per_page?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
}): Promise<EmployeeListResponse> {
  const params = new URLSearchParams()
  if (filters?.search) params.set('search', filters.search)
  if (filters?.per_page) params.set('per_page', String(filters.per_page))
  if (filters?.sort) params.set('sort', filters.sort)
  if (filters?.direction) params.set('direction', filters.direction)
  if (filters?.page) params.set('page', String(filters.page))

  const query = params.toString()
  return apiFetch<EmployeeListResponse>(`/employees${query ? `?${query}` : ''}`)
}

// ── CREATE ────────────────────────────────────────────────────────────────────

export async function createEmployee(
  payload: CreateEmployeePayload,
): Promise<{ success: boolean; data: EmployeeRow }> {
  return apiFetch<{ success: boolean; data: EmployeeRow }>('/employees', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

export async function updateEmployee(
  id: number,
  payload: UpdateEmployeePayload,
): Promise<{ success: boolean; data: EmployeeRow }> {
  return apiFetch<{ success: boolean; data: EmployeeRow }>(`/employees/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

// ── DELETE ────────────────────────────────────────────────────────────────────

export async function deleteEmployee(
  id: number,
): Promise<{ success: boolean }> {
  return apiFetch<{ success: boolean }>(`/employees/${id}`, { method: 'DELETE' })
}
