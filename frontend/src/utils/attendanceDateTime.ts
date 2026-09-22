/**
 * Attendance display timezone: always Asia/Jakarta (GMT+7), 24-hour clock.
 * API timestamps may already include +07:00; Instant parsing still uses Date.
 */

export const ATTENDANCE_TIMEZONE = 'Asia/Jakarta'

const timeFormatter = new Intl.DateTimeFormat('en-GB', {
  timeZone: ATTENDANCE_TIMEZONE,
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
})

const timeWithSecondsFormatter = new Intl.DateTimeFormat('en-GB', {
  timeZone: ATTENDANCE_TIMEZONE,
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
})

const dateKeyFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: ATTENDANCE_TIMEZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

const longDateFormatter = new Intl.DateTimeFormat('id-ID', {
  timeZone: ATTENDANCE_TIMEZONE,
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const shortDateFormatter = new Intl.DateTimeFormat('id-ID', {
  timeZone: ATTENDANCE_TIMEZONE,
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})

function parseDate(iso: string | null | undefined): Date | null {
  if (!iso) return null
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? null : d
}

/** HH:mm in Asia/Jakarta */
export function formatAttendanceTime(iso: string | null | undefined, empty = '—'): string {
  const d = parseDate(iso)
  if (!d) return empty
  return timeFormatter.format(d)
}

/** HH:mm:ss in Asia/Jakarta */
export function formatAttendanceTimeWithSeconds(iso: string | Date | null | undefined, empty = '--:--:--'): string {
  const d = iso instanceof Date ? iso : parseDate(iso ?? null)
  if (!d || Number.isNaN(d.getTime())) return empty
  return timeWithSecondsFormatter.format(d)
}

/** YYYY-MM-DD in Asia/Jakarta */
export function attendanceDateKey(iso: string | Date | null | undefined): string | null {
  const d = iso instanceof Date ? iso : parseDate(iso ?? null)
  if (!d || Number.isNaN(d.getTime())) return null
  return dateKeyFormatter.format(d)
}

/**
 * Time only when same business day as attendance_date; otherwise `YYYY-MM-DD HH:mm`.
 */
export function formatAttendanceDateTime(
  iso: string | null | undefined,
  attendanceDate?: string | null,
  empty = '—',
): string {
  const d = parseDate(iso)
  if (!d) return empty
  const time = timeFormatter.format(d)
  const localDay = attendanceDateKey(d)
  if (!attendanceDate || !localDay || localDay === attendanceDate) {
    return time
  }
  return `${localDay} ${time}`
}

export function formatAttendanceLongDate(iso: string | Date | null | undefined, empty = '—'): string {
  const d = iso instanceof Date ? iso : parseDate(iso ?? null)
  if (!d || Number.isNaN(d.getTime())) return empty
  return longDateFormatter.format(d)
}

export function formatAttendanceShortDate(iso: string | null | undefined, empty = '—'): string {
  const d = parseDate(iso)
  if (!d) return empty
  return shortDateFormatter.format(d)
}
