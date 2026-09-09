export type ApiResponse<T> = {
  data: T
  message?: string
  meta?: {
    page?: number
    total?: number
  }
}

export type UserSummary = {
  id: number
  name: string
  email: string
}

/**
 * Safe authenticated-user profile returned by the Laravel API.
 * Never contains passwords, tokens, or biometric data.
 */
export type AuthUser = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}
