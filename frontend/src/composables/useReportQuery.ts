import { reactive, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch, ApiError } from '../services/apiClient'
import type { ReportMeta } from '../types/reports'

type ReportState = {
  loading: boolean
  error: string
  meta: ReportMeta
}

type ReportFilters = {
  from: string
  to: string
  employee_id: string
  per_page: number
  sort: string
  direction: 'asc' | 'desc'
}

const defaultFilters: ReportFilters = {
  from: '',
  to: '',
  employee_id: '',
  per_page: 25,
  sort: '',
  direction: 'asc',
}

export function useReportQuery(
  buildUrl: (filters: ReportFilters) => string,
) {
  const router = useRouter()
  const state = reactive<ReportState>({
    loading: false,
    error: '',
    meta: {
      current_page: 1,
      per_page: 25,
      total: 0,
      last_page: 1,
    },
  })

  const data = shallowRef<unknown[]>([])
  const filters = reactive<ReportFilters>({ ...defaultFilters })

  async function fetch(): Promise<void> {
    state.loading = true
    state.error = ''

    try {
      const response = await apiFetch<{ success: boolean; data: unknown[]; meta: ReportMeta }>(buildUrl(filters))
      data.value = response.data
      state.meta = response.meta
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        if (err.status === 401) {
          await router.push({ name: 'error.unauthorized' })
          return
        }

        if (err.status === 403) {
          state.error = 'You do not have permission to view this report.'
          return
        }
      }

      state.error = 'Unable to load report data. Please try again.'
    } finally {
      state.loading = false
    }
  }

  function goToPage(page: number): void {
    state.meta.current_page = page
    fetch()
  }

  function updateFilter<K extends keyof ReportFilters>(key: K, value: ReportFilters[K]): void {
    filters[key] = value
  }

  function applyFilters(): void {
    state.meta.current_page = 1
    fetch()
  }

  return {
    state,
    data,
    filters,
    fetch,
    goToPage,
    updateFilter,
    applyFilters,
  }
}
