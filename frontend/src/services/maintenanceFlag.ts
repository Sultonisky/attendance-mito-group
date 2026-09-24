/**
 * SPA maintenance flag probe.
 *
 * Runtime file is gitignored. Sources (priority):
 *   - Written by `php artisan mito:maintenance down|up` under public/
 *   - Dockerfile default enabled:false (production image)
 *   - Vite middleware fallback (local) / Laravel route fallback (prod if missing)
 *
 * Probe treats enabled === true only; missing/disabled ⇒ not in maintenance.
 */

export type MaintenanceFlag = {
  enabled?: boolean
  retry_after?: number
  message?: string
  enabled_at?: string
}

const FLAG_URL = '/maintenance.json'
const CACHE_MS = 4_000

let cache: { at: number; active: boolean; flag: MaintenanceFlag | null } | null = null

export async function fetchMaintenanceFlag(force = false): Promise<MaintenanceFlag | null> {
  if (!force && cache && Date.now() - cache.at < CACHE_MS) {
    return cache.flag
  }

  try {
    const response = await fetch(FLAG_URL, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) {
      cache = { at: Date.now(), active: false, flag: null }
      return null
    }

    const body = (await response.json()) as MaintenanceFlag
    const active = body.enabled === true
    cache = { at: Date.now(), active, flag: body }
    return body
  } catch {
    // Missing file / network blip → treat as not in flag-based maintenance.
    cache = { at: Date.now(), active: false, flag: null }
    return null
  }
}

export async function isMaintenanceFlagActive(force = false): Promise<boolean> {
  const flag = await fetchMaintenanceFlag(force)
  return flag?.enabled === true
}

export function clearMaintenanceFlagCache(): void {
  cache = null
}
