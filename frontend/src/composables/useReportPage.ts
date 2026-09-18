/**
 * useReportPage — shared logic untuk semua report pages
 * Handles: error state, meta pagination, goToPage
 */
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../services/apiClient'

export interface ReportMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

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

  /** Call inside your load() try/catch */
  async function handleApiError(err: unknown, fallbackMessage: string): Promise<boolean> {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'login.admin' })
        return true // handled — caller should return
      }
      if (err.status === 403) {
        error.value = 'You do not have permission to view this report.'
        return true
      }
    }
    error.value = err instanceof TypeError
      ? 'Unable to connect to the API. Check that Laravel is running.'
      : fallbackMessage
    return false
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
