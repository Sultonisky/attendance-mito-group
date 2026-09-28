/**
 * useReportPage — shared logic untuk semua report pages
 * Handles: error state, meta pagination, goToPage
 */
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, firstValidationMessage } from '../services/apiClient'
import { useAppToast } from './useAppToast'

export interface ReportMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type ReportApiErrorKind = 'auth' | 'forbidden' | 'validation' | 'network' | 'other'

export function useReportPage() {
  const router = useRouter()
  const toast = useAppToast()

  const loading     = ref(false)
  const error       = ref('')
  const filterError = ref('')

  const meta = reactive<ReportMeta>({
    current_page: 1,
    per_page: 25,
    total: 0,
    last_page: 1,
  })

  function clearErrors() {
    error.value = ''
    filterError.value = ''
  }

  /**
   * Call inside your load() try/catch.
   * 422 goes to `filterError` (+ toast) instead of `error`, so the page stays usable.
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
        filterError.value = firstValidationMessage(err)
          ?? (err.message && err.message !== `API request failed: ${err.status}`
            ? err.message
            : 'One or more filters are invalid. Adjust them and try again.')
        toast.error('Filter tidak valid', filterError.value)
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

  function clearErrors() {
    error.value = ''
    filterError.value = ''
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

  return { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage }
}
