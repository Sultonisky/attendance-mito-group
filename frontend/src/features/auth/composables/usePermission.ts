import { computed } from 'vue'
import { useAuthStore } from '../../../stores/auth'

/**
 * UX-level permission helper.
 *
 * Frontend permission checks only hide/show UI. They are NEVER a security
 * boundary — the Laravel API authoritatively enforces permissions.
 */
export function usePermission() {
  const auth = useAuthStore()

  const can = (permission: string): boolean => auth.can(permission)

  const canAny = (permissions: string[]): boolean =>
    permissions.some((permission) => auth.can(permission))

  const hasRole = (role: string): boolean => auth.roles.includes(role)

  return {
    can,
    canAny,
    hasRole,
    permissions: computed(() => auth.permissions),
    roles: computed(() => auth.roles),
  }
}
