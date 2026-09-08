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
