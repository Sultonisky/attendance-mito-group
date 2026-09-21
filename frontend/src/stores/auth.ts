import { defineStore } from 'pinia'
import { apiFetch, fetchCsrfCookie, ApiError } from '../services/apiClient'
import { usePortalAnchor } from '../composables/usePortalAnchor'
import type { ApiResponse, AuthUser } from '../types/api'

type AuthState = {
  user: AuthUser | null
  isLoading: boolean
  isInitialized: boolean
}

/**
 * Client-side authentication state.
 *
 * This store drives UX only. It is NOT the authorization authority:
 * the Laravel API enforces authentication and permissions server-side.
 */
export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    isLoading: false,
    isInitialized: false,
  }),

  getters: {
    isAuthenticated: (state): boolean => state.user !== null,
    roles: (state): string[] => state.user?.roles ?? [],
    permissions: (state): string[] => state.user?.permissions ?? [],
  },

  actions: {
    /**
     * UX-level permission check. Never use it as a security boundary.
     */
    can(permission: string): boolean {
      if (this.roles.includes('SUPER_ADMIN')) {
        return true
      }
      return this.permissions.includes(permission)
    },

    setUser(user: AuthUser | null) {
      this.user = user
    },

    clearUser() {
      this.user = null
    },

    /**
     * Load the current session user from the API (session cookie based).
     *
     * Cross-session takeover guard:
     * If this tab has a portal anchor (set at login time) and the server
     * session now belongs to a different role — because another tab logged in
     * and overwrote the shared cookie — we treat it as "not authenticated"
     * for this tab. The router guard then redirects to the correct login page.
     * We do NOT throw here so the guard's `.catch(() => undefined)` doesn't
     * swallow the mismatch; instead we simply leave `user` as null.
     */
    async fetchCurrentUser(): Promise<void> {
      const { isRoleConsistentWithPortal, clearPortal } = usePortalAnchor()
      try {
        const response = await apiFetch<ApiResponse<{ user: AuthUser }>>(
          '/auth/me',
        )
        const user = response.data.user
        const isAdmin = user.roles.some((r) => ['ADMIN', 'SUPER_ADMIN'].includes(r))

        // Detect session takeover: server returned a user whose role does not
        // match the portal this tab was anchored to at login time.
        if (!isRoleConsistentWithPortal(isAdmin)) {
          // Clear the anchor so the guard can redirect cleanly to login.
          clearPortal()
          this.clearUser()
          return
        }

        this.setUser(user)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          this.clearUser()
        } else {
          throw error
        }
      } finally {
        this.isInitialized = true
      }
    },

    /**
     * Authenticate against the Laravel API and load the user.
     */
    async login(email: string, password: string): Promise<void> {
      this.isLoading = true

      try {
        await fetchCsrfCookie()

        const response = await apiFetch<
          ApiResponse<{ user: AuthUser }> & { success: boolean }
        >('/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        })

        this.setUser(response.data.user)
      } finally {
        this.isLoading = false
      }
    },

    /**
     * Invalidate the session on the server and clear client state.
     * Also clears the portal anchor so the tab is no longer bound to a portal.
     */
    async logout(): Promise<void> {
      this.isLoading = true
      const { clearPortal } = usePortalAnchor()

      try {
        await apiFetch<{ success: boolean }>('/logout', { method: 'POST' })
      } finally {
        this.clearUser()
        clearPortal()
        this.isLoading = false
      }
    },
  },
})
