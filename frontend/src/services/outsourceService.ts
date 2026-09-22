import { apiFetch } from './apiClient'
import { getOutsourceDeviceFingerprint } from '../utils/outsourceDeviceFingerprint'

export interface City {
  id: number
  name: string
  code: string
}

export interface Store {
  id: number
  name: string
  city_id: number
  latitude?: number | null
  longitude?: number | null
  radius_meters?: number | null
}

export interface Outsource {
  id: number
  name: string
  outsource_code: string
}

export interface OutsourceAttendanceSnapshot {
  attendance_id: number
  status: string
  attendance_date: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
}

export interface OutsourceSessionPayload {
  status: 'NONE' | 'READY' | 'ACTIVE' | string
  expires_at: string | null
  outsource: Outsource | null
  store: (Pick<Store, 'id' | 'name'> & { city_id?: number | null }) | null
  attendance: OutsourceAttendanceSnapshot | null
  code?: string
}

export interface OutsourceSessionResponse {
  success: boolean
  data: OutsourceSessionPayload
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

export async function fetchOutsourceSessionCurrent(): Promise<OutsourceSessionResponse> {
  return apiFetch<OutsourceSessionResponse>('/outsource/session/current')
}

export async function initOutsourceSession(
  cityId: number,
  storeId: number,
  outsourceId: number,
): Promise<OutsourceSessionResponse> {
  return apiFetch<OutsourceSessionResponse>('/outsource/session/init', {
    method: 'POST',
    body: JSON.stringify({
      city_id: cityId,
      store_id: storeId,
      outsource_id: outsourceId,
      device_fingerprint: getOutsourceDeviceFingerprint(),
    }),
  })
}

export async function outsourceCheckIn(
  latitude: number,
  longitude: number,
  accuracy?: number,
): Promise<OutsourceAttendanceResponse> {
  return apiFetch<OutsourceAttendanceResponse>('/outsource/attendance/check-in', {
    method: 'POST',
    body: JSON.stringify({
      latitude,
      longitude,
      accuracy_meters: accuracy,
      source: 'web',
      device_fingerprint: getOutsourceDeviceFingerprint(),
    }),
  })
}

export async function outsourceCheckOut(
  latitude: number,
  longitude: number,
  accuracy?: number,
): Promise<OutsourceAttendanceResponse> {
  return apiFetch<OutsourceAttendanceResponse>('/outsource/attendance/check-out', {
    method: 'POST',
    body: JSON.stringify({
      latitude,
      longitude,
      accuracy_meters: accuracy,
      source: 'web',
      device_fingerprint: getOutsourceDeviceFingerprint(),
    }),
  })
}
