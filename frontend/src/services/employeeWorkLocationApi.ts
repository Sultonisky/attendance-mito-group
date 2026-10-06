import { apiFetch } from './apiClient'

export type WorkAreaType = string

export type EmployeeWorkLocationRow = {
  id: number
  code: string
  name: string
  city: string
  area_type: WorkAreaType
  area_type_label: string
  address: string | null
  latitude: number | null
  longitude: number | null
  radius_meters: number | null
  status: 'active' | 'inactive'
  employee_count?: number
  created_at: string | null
  updated_at: string | null
}

export type AssignedEmployeeRow = {
  id: number
  employee_code: string
  nik: string
  full_name: string
}

export type EmployeeWorkLocationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type EmployeeWorkLocationPayload = {
  name: string
  city: string
  area_type: WorkAreaType
  address?: string | null
  latitude: number
  longitude: number
  radius_meters?: number | null
  status?: 'active' | 'inactive'
}

export async function fetchHrisEmployeeWorkLocations(
  nik: string,
): Promise<{ success: boolean; data: EmployeeWorkLocationRow[] }> {
  return apiFetch(`/employees/hris/${encodeURIComponent(nik)}/work-locations`)
}

export async function syncHrisEmployeeWorkLocations(
  nik: string,
  workLocationIds: number[],
): Promise<{ success: boolean; data: { employee_nik: string; work_location_ids: number[] } }> {
  return apiFetch(`/employees/hris/${encodeURIComponent(nik)}/work-locations`, {
    method: 'PUT',
    body: JSON.stringify({ work_location_ids: workLocationIds }),
  })
}

export async function fetchEmployeeWorkLocations(filters?: {
  search?: string
  city?: string
  area_type?: string
  status?: string
  per_page?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
}): Promise<{ success: boolean; data: EmployeeWorkLocationRow[]; meta: EmployeeWorkLocationMeta }> {
  const params = new URLSearchParams()
  Object.entries(filters ?? {}).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') params.set(key, String(value))
  })

  const query = params.toString()
  return apiFetch(`/employee-work-locations${query ? `?${query}` : ''}`)
}

export async function fetchEmployeeWorkLocationCities(): Promise<string[]> {
  const res = await apiFetch<{ success: boolean; data: string[] }>('/employee-work-locations/cities')
  return res.data
}

export async function fetchEmployeeWorkLocationEmployees(
  id: number,
): Promise<AssignedEmployeeRow[]> {
  const response = await apiFetch<{ success: boolean; data: AssignedEmployeeRow[] }>(
    `/employee-work-locations/${id}/employees`,
  )
  return response.data
}

export async function createEmployeeWorkLocation(
  payload: EmployeeWorkLocationPayload,
): Promise<{ success: boolean; data: EmployeeWorkLocationRow }> {
  return apiFetch('/employee-work-locations', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function updateEmployeeWorkLocation(
  id: number,
  payload: Partial<EmployeeWorkLocationPayload>,
): Promise<{ success: boolean; data: EmployeeWorkLocationRow }> {
  return apiFetch(`/employee-work-locations/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export async function deleteEmployeeWorkLocation(id: number): Promise<{ success: boolean }> {
  return apiFetch(`/employee-work-locations/${id}`, { method: 'DELETE' })
}
