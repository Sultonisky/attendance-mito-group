import { apiFetch } from './apiClient'
import type { SystemHealthResponse } from '../types/dashboard'

export async function fetchSystemsHealth(): Promise<SystemHealthResponse> {
  return apiFetch<SystemHealthResponse>('/systems/health')
}
