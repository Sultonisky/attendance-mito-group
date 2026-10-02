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

export interface OutsourcePin {
  id: number
  name: string
  address?: string | null
  latitude?: number | null
  longitude?: number | null
  radius_meters?: number | null
  work_location_id?: number | null
  cabang_name?: string | null
  city_id?: number | null
  city_name?: string | null
}

export function formatOutsourcePinLabel(pin: OutsourcePin): string {
  const place = formatCityCabangLabel(pin.city_name, pin.cabang_name)
  if (place) return `${place} — ${pin.name}`
  return pin.address ? `${pin.name} — ${pin.address}` : pin.name
}

/** Join kota + cabang, but show once when names are the same (OS import: 1 cabang per kota). */
export function formatCityCabangLabel(
  cityName?: string | null,
  cabangName?: string | null,
  separator = ' / ',
): string {
  const city = (cityName ?? '').trim()
  const cabang = (cabangName ?? '').trim()
  if (city && cabang) {
    if (city.toLocaleLowerCase('id') === cabang.toLocaleLowerCase('id')) {
      return city
    }
    return `${city}${separator}${cabang}`
  }
  return city || cabang
}

export interface OutsourceAttendanceLocation {
  occurred_at: string | null
  pin: { id: number; name: string; address: string | null } | null
  work_location: { id: number; name: string } | null
}

export interface OutsourceAttendanceSnapshot {
  attendance_id: number
  status: string
  session_status?: 'open' | 'closed' | 'expired' | string
  attendance_date: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
  check_in_location?: OutsourceAttendanceLocation | null
  check_out_location?: OutsourceAttendanceLocation | null
}

export interface OutsourceSessionPayload {
  status: 'NONE' | 'READY' | 'ACTIVE' | 'COMPLETED' | string
  expires_at: string | null
  outsource: Outsource | null
  store: (Pick<Store, 'id' | 'name' | 'latitude' | 'longitude'> & {
    city_id?: number | null
    city_name?: string | null
  }) | null
  city?: { id: number; name: string } | null
  pins?: OutsourcePin[]
  attendance: OutsourceAttendanceSnapshot | null
  can_clock_in?: boolean
  can_clock_out?: boolean
  code?: string
}

/**
 * Outsource own attendance history (greeting page).
 * Frontend currently DISABLED via ENABLE_OUTSOURCE_ATTENDANCE_HISTORY — keep API for later.
 */
export interface OutsourceHistoryItem {
  attendance_id: number
  attendance_date: string
  status: string
  check_in_at: string | null
  check_out_at: string | null
  duration_minutes: number | null
  session_count: number
}

export interface OutsourceHistoryLocation {
  pin_name: string | null
  pin_address: string | null
}

export interface OutsourcePeriodHistorySession {
  status: string
  check_in_at: string | null
  check_out_at: string | null
  /** Business dates (Asia/Jakarta, YYYY-MM-DD). */
  check_in_date: string | null
  check_out_date: string | null
  crosses_midnight: boolean
  check_out_day_offset: number | null
  duration_minutes: number | null
  check_in_location: OutsourceHistoryLocation | null
  check_out_location: OutsourceHistoryLocation | null
}

/** One item per day; days without a clock-in are "absent" (today: "pending"). */
export interface OutsourcePeriodHistoryItem extends Omit<OutsourceHistoryItem, 'attendance_id'> {
  attendance_id: number | null
  attended: boolean
  has_open_session: boolean
  check_out_date: string | null
  /** Days between attendance_date (clock-in day) and the last clock-out day. */
  check_out_day_offset: number | null
  sessions: OutsourcePeriodHistorySession[]
}

/** Monthly period keyed by end month: "2026-09" = 25 Aug – 24 Sep 2026 (cutoff set on the server). */
export interface OutsourcePeriodHistory {
  period: {
    key: string
    start_date: string
    end_date: string
    is_current: boolean
    /** null on the first available period (25 Aug – 24 Sep 2026). */
    previous_key: string | null
    next_key: string | null
  }
  summary: {
    /** Days listed: period start up to today (or period end). */
    days_listed: number
    days_attended: number
    days_absent: number
    days_complete: number
    days_incomplete: number
    days_cross_midnight: number
    total_sessions: number
    total_duration_minutes: number
    average_duration_minutes: number | null
  }
  items: OutsourcePeriodHistoryItem[]
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
    check_in_location?: OutsourceAttendanceLocation | null
    check_out_location?: OutsourceAttendanceLocation | null
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

export async function fetchOutsourcePins(): Promise<OutsourcePin[]> {
  const response = await apiFetch<{ success: boolean; data: OutsourcePin[] }>('/outsource/pins')
  return Array.isArray(response?.data) ? response.data : []
}

/** Disabled on FE for now — endpoint kept; re-enable with ENABLE_OUTSOURCE_ATTENDANCE_HISTORY. */
export async function fetchOutsourceAttendanceHistory(
  limit = 14,
): Promise<OutsourceHistoryItem[]> {
  const response = await apiFetch<{ success: boolean; data: OutsourceHistoryItem[] }>(
    `/outsource/attendance/history?limit=${limit}`,
  )
  return Array.isArray(response?.data) ? response.data : []
}

/** Own attendance for one monthly period; omit `period` for the current one. */
export async function fetchOutsourcePeriodHistory(
  period?: string | null,
): Promise<OutsourcePeriodHistory> {
  const query = period ? `?period=${encodeURIComponent(period)}` : ''
  const response = await apiFetch<{ success: boolean; data: OutsourcePeriodHistory }>(
    `/outsource/attendance/history/period${query}`,
  )
  return response.data
}

export async function loginOutsourceSession(
  outsourceCode: string,
  password: string,
): Promise<OutsourceSessionResponse> {
  return apiFetch<OutsourceSessionResponse>('/outsource/login', {
    method: 'POST',
    body: JSON.stringify({
      outsource_code: outsourceCode,
      password,
      device_fingerprint: getOutsourceDeviceFingerprint(),
    }),
  })
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
  pinId: number,
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
      pin_id: pinId,
    }),
  })
}

export async function outsourceCheckOut(
  latitude: number,
  longitude: number,
  pinId: number,
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
      pin_id: pinId,
    }),
  })
}
