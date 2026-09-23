/**
 * SPA maintenance flag probe.
 *
 * Written by `php artisan mito:maintenance down` to:
 *   - backend-laravel/public/maintenance.json  (prod / artisan serve)
 *   - frontend/public/maintenance.json         (local Vite)
 *
 * Static file — works even while Laravel is in artisan-down mode.
 */

type MaintenanceFlag = {
  enabled?: boolean
  retry_after?: number
  message?: string
  enabled_at?: string
}

const FLAG_URL = '/maintenance.json'
const CACHE_MS = 4_000

let cache: { at: number; active: boolean } | null = null

export async function isMaintenanceFlagActive(force = false): Promise<boolean> {
  if (!force && cache && Date.now() - cache.at < CACHE_MS) {
    return cache.active
  }

  try {
    const response = await fetch(FLAG_URL, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) {
      cache = { at: Date.now(), active: false }
      return false
    }

    const body = (await response.json()) as MaintenanceFlag
    const active = body.enabled === true
    cache = { at: Date.now(), active }
    return active
  } catch {
    // Missing file / network blip → treat as not in flag-based maintenance.
    cache = { at: Date.now(), active: false }
    return false
  }
}

export function clearMaintenanceFlagCache(): void {
  cache = null
}
