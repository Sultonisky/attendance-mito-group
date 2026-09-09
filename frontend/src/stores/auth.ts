import { defineStore } from 'pinia'
import { apiFetch, fetchCsrfCookie, ApiError } from '../services/apiClient'
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
     */
    async fetchCurrentUser(): Promise<void> {
      try {
        const response = await apiFetch<ApiResponse<{ user: AuthUser }>>(
          '/auth/me',
        )
        this.setUser(response.data.user)
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
     */
    async logout(): Promise<void> {
      this.isLoading = true

      try {
        await apiFetch<{ success: boolean }>('/logout', { method: 'POST' })
      } finally {
        this.clearUser()
        this.isLoading = false
      }
    },
  },
})
