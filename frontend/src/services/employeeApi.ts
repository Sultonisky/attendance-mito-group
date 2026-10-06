import { apiFetch } from './apiClient'
import type { WorkAreaType } from './employeeWorkLocationApi'

export type EmployeeAssignedWorkLocation = {
  id: number
  name: string
  city: string
  area_type: WorkAreaType
  area_type_label: string
}

export type EmployeeRow = {
  id: number
  employee_code: string
  nik: string | null
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
  work_locations?: EmployeeAssignedWorkLocation[]
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
  nik?: string | null
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
  work_location_ids?: number[]
}

export type UpdateEmployeePayload = Partial<CreateEmployeePayload>

export type HrisEmployeeRow = {
  employee_id: string
  nik: string | null
  full_name: string | null
  status_employee: string | null
  job_position: string | null
  division: string | null
  department: string | null
  branch_name: string | null
  job_position_location: string | null
  area_kerja: string | null
  lokasi_kerja: string | null
}

export type HrisEmployeeMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type HrisEmployeeFilterOptions = {
  work_locations: string[]
  work_areas: string[]
}

export type HrisEmployeeListResponse = {
  success: boolean
  data: HrisEmployeeRow[]
  links: Record<string, string | null>
  meta: HrisEmployeeMeta
  filters: HrisEmployeeFilterOptions
}

export type HrisWorkLocationOptions = {
  work_locations: string[]
  work_areas: string[]
}

export async function fetchEmployees(filters?: {
  search?: string
  work_location_id?: number | string
  per_page?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
}): Promise<EmployeeListResponse> {
  const params = new URLSearchParams()
  if (filters?.search) params.set('search', filters.search)
  if (filters?.work_location_id) params.set('work_location_id', String(filters.work_location_id))
  if (filters?.per_page) params.set('per_page', String(filters.per_page))
  if (filters?.sort) params.set('sort', filters.sort)
  if (filters?.direction) params.set('direction', filters.direction)
  if (filters?.page) params.set('page', String(filters.page))

  const query = params.toString()
  return apiFetch<EmployeeListResponse>(`/employees${query ? `?${query}` : ''}`)
}

export async function createEmployee(
  payload: CreateEmployeePayload,
): Promise<{ success: boolean; data: EmployeeRow }> {
  return apiFetch<{ success: boolean; data: EmployeeRow }>('/employees', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateEmployee(
  id: number,
  payload: UpdateEmployeePayload,
): Promise<{ success: boolean; data: EmployeeRow }> {
  return apiFetch<{ success: boolean; data: EmployeeRow }>(`/employees/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function deleteEmployee(id: number): Promise<{ success: boolean }> {
  return apiFetch<{ success: boolean }>(`/employees/${id}`, { method: 'DELETE' })
}

export async function fetchHrisEmployees(filters?: {
  search?: string
  per_page?: number
  page?: number
  work_location?: string
  work_area?: string
}): Promise<HrisEmployeeListResponse> {
  const params = new URLSearchParams()
  if (filters?.search) params.set('search', filters.search)
  if (filters?.per_page) params.set('per_page', String(filters.per_page))
  if (filters?.page) params.set('page', String(filters.page))
  if (filters?.work_location) params.set('work_location', filters.work_location)
  if (filters?.work_area) params.set('work_area', filters.work_area)

  const query = params.toString()
  return apiFetch<HrisEmployeeListResponse>(`/employees/hris${query ? `?${query}` : ''}`)
}

export async function fetchHrisWorkLocationOptions(): Promise<HrisWorkLocationOptions> {
  const response = await apiFetch<{ success: boolean; data: HrisWorkLocationOptions }>(
    '/employee-work-locations/options',
  )
  return response.data
}

export async function createHrisWorkLocationOption(
  payload: { type: 'work_location' | 'work_area'; name: string },
): Promise<{ success: boolean; data: { type: 'work_location' | 'work_area'; name: string } }> {
  return apiFetch('/employee-work-locations/options', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
