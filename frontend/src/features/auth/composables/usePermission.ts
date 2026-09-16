import { computed, type WritableComputedRef } from 'vue'
import { useAuthStore } from '../../../stores/auth'

type UsePermissionReturn = {
  can: (permission: string) => boolean
  canAny: (permissions: string[]) => boolean
  hasRole: (role: string) => boolean
  permissions: WritableComputedRef<string[]>
  roles: WritableComputedRef<string[]>
}

/**
 * UX-level permission helper.
 *
 * Frontend permission checks only hide/show UI. They are NEVER a security
 * boundary — the Laravel API authoritatively enforces permissions.
 */
export function usePermission(): UsePermissionReturn {
  const auth = useAuthStore()

  const can = (permission: string): boolean => auth.can(permission)

  const canAny = (permissions: string[]): boolean =>
    permissions.some((permission) => auth.can(permission))

  const hasRole = (role: string): boolean => auth.roles.includes(role)

  return {
    can,
    canAny,
    hasRole,
    permissions: computed(() => auth.permissions) as WritableComputedRef<string[]>,
    roles: computed(() => auth.roles) as WritableComputedRef<string[]>,
  }
}
