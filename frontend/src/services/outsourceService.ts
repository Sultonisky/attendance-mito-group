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
  const response = await apiFetch<{ success: boolean; data: City[] }>('/outsource/cities')
  return Array.isArray(response?.data) ? response.data : []
}

export async function fetchOutsourceStores(cityId?: number): Promise<Store[]> {
  const query = cityId ? `?city_id=${cityId}` : ''
  const response = await apiFetch<{ success: boolean; data: Store[] }>(`/outsource/stores${query}`)
  return Array.isArray(response?.data) ? response.data : []
}

export async function fetchOutsourceOutsources(storeId?: number): Promise<Outsource[]> {
  const query = storeId ? `?store_id=${storeId}` : ''
  const response = await apiFetch<{ success: boolean; data: Outsource[] }>(`/outsource/outsources${query}`)
  return Array.isArray(response?.data) ? response.data : []
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

export interface StoredOutsourceSession {
  session_token: string
  expires_at: string
  outsource: Outsource
  store: Store
  attendance_id?: number | null
  check_in_at?: string | null
  check_out_at?: string | null
  duration_minutes?: number | null
  status?: string | null
}

const STORAGE_KEY = 'mito_outsource_session'

export function getStoredOutsourceSession(): StoredOutsourceSession | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredOutsourceSession
    if (parsed && parsed.expires_at) {
      if (new Date(parsed.expires_at).getTime() <= Date.now()) {
        localStorage.removeItem(STORAGE_KEY)
        return null
      }
    }
    return parsed
  } catch {
    return null
  }
}

export function saveStoredOutsourceSession(session: StoredOutsourceSession): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
    localStorage.setItem('outsource_session_token', session.session_token)
  } catch {
    // Ignore storage quota errors
  }
}

export function clearStoredOutsourceSession(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
    localStorage.removeItem('outsource_session_token')
  } catch {
    // Ignore errors
  }
}
