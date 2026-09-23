/**
 * Soft navigation for global HTTP conditions (session expired / maintenance).
 *
 * Kept separate from apiClient to avoid hard circular imports; uses dynamic
 * import of the router/store when needed.
 *
 * Deliberately does NOT redirect:
 * - login pages (401 = wrong credentials)
 * - /outsource (401 = outsource PIN session, not Sanctum)
 * - generic 503 without Retry-After (AI / face service downtime)
 */

const ERROR_UNAUTHORIZED = 'error.unauthorized'
const ERROR_UNAVAILABLE = 'error.unavailable'

const SKIP_SESSION_EXPIRED_ROUTES = new Set([
  'login',
  'login.admin',
  'login.employee',
  'outsource',
  ERROR_UNAUTHORIZED,
])

let sessionExpiredNavigating = false
let maintenanceNavigating = false

async function currentRouteName(): Promise<string | symbol | null | undefined> {
  const { default: router } = await import('../router')
  return router.currentRoute.value.name
}

async function pushNamed(name: string): Promise<void> {
  const { default: router } = await import('../router')
  if (router.currentRoute.value.name === name) return
  await router.push({ name })
}

/**
 * UX redirect when a Sanctum session is gone mid-use.
 * Clears client auth state; portal anchor is left for login targeting.
 */
export async function navigateOnSessionExpired(): Promise<void> {
  if (sessionExpiredNavigating) return

  const routeName = await currentRouteName()
  if (typeof routeName === 'string' && SKIP_SESSION_EXPIRED_ROUTES.has(routeName)) {
    return
  }

  const { useAuthStore } = await import('../stores/auth')
  const { usePortalAnchor } = await import('../composables/usePortalAnchor')
  const auth = useAuthStore()
  const { getPortal } = usePortalAnchor()

  // Fresh anonymous visit — let the router guard send them to login.
  if (!auth.isAuthenticated && getPortal() === null) {
    return
  }

  sessionExpiredNavigating = true
  try {
    auth.clearUser()
    await pushNamed(ERROR_UNAUTHORIZED)
  } finally {
    sessionExpiredNavigating = false
  }
}

/**
 * UX redirect for Laravel maintenance mode (503 + Retry-After).
 * Does not fire for AI/face 503 responses that omit Retry-After.
 */
export async function navigateOnMaintenance(response: Response): Promise<void> {
  if (maintenanceNavigating) return
  if (!response.headers.has('Retry-After')) return

  const routeName = await currentRouteName()
  if (routeName === ERROR_UNAVAILABLE) return

  maintenanceNavigating = true
  try {
    await pushNamed(ERROR_UNAVAILABLE)
  } finally {
    maintenanceNavigating = false
  }
}
