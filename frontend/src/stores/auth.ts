import { defineStore } from 'pinia'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as null | { id: number; name: string; email: string },
    isAuthenticated: false,
  }),
  actions: {
    setUser(user: { id: number; name: string; email: string } | null) {
      this.user = user
      this.isAuthenticated = Boolean(user)
    },
  },
})
