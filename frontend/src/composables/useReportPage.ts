/**
 * useReportPage — shared logic untuk semua report pages
 * Handles: error state, meta pagination, goToPage
 */
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, firstValidationMessage } from '../services/apiClient'

export interface ReportMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type ReportApiErrorKind = 'auth' | 'forbidden' | 'validation' | 'network' | 'other'

export function useReportPage() {
  const router = useRouter()

  const loading = ref(false)
  const error   = ref('')

  const meta = reactive<ReportMeta>({
    current_page: 1,
    per_page: 25,
    total: 0,
    last_page: 1,
  })

  /**
   * Call inside your load() try/catch.
   * Returns the error kind so callers can keep the table visible for filter/validation
   * issues (toast + soft banner) instead of replacing the page with "Failed to load".
   */
  async function handleApiError(err: unknown, fallbackMessage: string): Promise<ReportApiErrorKind> {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'error.unauthorized' })
        return 'auth'
      }
      if (err.status === 403) {
        error.value = 'You do not have permission to view this report.'
        return 'forbidden'
      }
      if (err.status === 422) {
        error.value = firstValidationMessage(err)
          ?? (err.message && err.message !== `API request failed: ${err.status}`
            ? err.message
            : 'One or more filters are invalid. Adjust them and try again.')
        return 'validation'
      }
    }
    if (err instanceof TypeError) {
      error.value = 'Unable to connect to the API. Check that Laravel is running.'
      return 'network'
    }
    error.value = fallbackMessage
    return 'other'
  }

  function applyMeta(responseMeta: Partial<ReportMeta>) {
    if (responseMeta.current_page !== undefined) meta.current_page = responseMeta.current_page
    if (responseMeta.per_page     !== undefined) meta.per_page     = responseMeta.per_page
    if (responseMeta.total        !== undefined) meta.total        = responseMeta.total
    if (responseMeta.last_page    !== undefined) meta.last_page    = responseMeta.last_page
  }

  function goToPage(page: number, load: () => void) {
    if (page < 1 || page > meta.last_page) return
    meta.current_page = page
    load()
  }

  return { loading, error, meta, handleApiError, applyMeta, goToPage }
}
