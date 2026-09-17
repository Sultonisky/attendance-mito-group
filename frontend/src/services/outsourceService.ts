import { apiFetch } from './apiClient'

export interface City {
  id: number
  name: string
  code: string
}

export interface Store {
  id: number
  name: string
  city_id: number
}

export interface Outsource {
  id: number
  name: string
  outsource_code: string
}

export interface OutsourceSessionResponse {
  success: boolean
  data: {
    session_token: string
    expires_at: string
    outsource: Outsource
    store: Store
  }
}

export interface OutsourceAttendanceResponse {
  success: boolean
  data: {
    attendance_id: number
    status: string
    attendance_date: string
    check_in_at: string | null
    check_out_at: string | null
    duration_minutes: number | null
  }
}

export async function fetchOutsourceCities(): Promise<City[]> {
  return apiFetch<City[]>('/outsource/cities')
}

export async function fetchOutsourceStores(cityId?: number): Promise<Store[]> {
  const query = cityId ? `?city_id=${cityId}` : ''
  return apiFetch<Store[]>(`/outsource/stores${query}`)
}

export async function fetchOutsourceOutsources(storeId?: number): Promise<Outsource[]> {
  const query = storeId ? `?store_id=${storeId}` : ''
  return apiFetch<Outsource[]>(`/outsource/outsources${query}`)
}

export async function initOutsourceSession(
  cityId: number,
  storeId: number,
  outsourceId: number,
): Promise<OutsourceSessionResponse> {
  return apiFetch<OutsourceSessionResponse>('/outsource/session/init', {
    method: 'POST',
    headers: { Authorization: `Bearer ${localStorage.getItem('outsource_session_token') ?? ''}` },
    body: JSON.stringify({ city_id: cityId, store_id: storeId, outsource_id: outsourceId }),
  })
}

export async function outsourceCheckIn(
  token: string,
  latitude: number,
  longitude: number,
  accuracy?: number,
): Promise<OutsourceAttendanceResponse> {
  return apiFetch<OutsourceAttendanceResponse>('/outsource/attendance/check-in', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      latitude,
      longitude,
      accuracy_meters: accuracy,
      source: 'web',
    }),
  })
}

export async function outsourceCheckOut(
  token: string,
  latitude: number,
  longitude: number,
  accuracy?: number,
): Promise<OutsourceAttendanceResponse> {
  return apiFetch<OutsourceAttendanceResponse>('/outsource/attendance/check-out', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      latitude,
      longitude,
      accuracy_meters: accuracy,
      source: 'web',
    }),
  })
}
