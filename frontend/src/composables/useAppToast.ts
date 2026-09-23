import { useToast } from '@nuxt/ui/composables/useToast'

/**
 * Thin wrapper around Nuxt UI `useToast` for consistent dashboard feedback.
 * Validation errors stay inline in modals; use these for completed (or failed) API actions.
 */
export function useAppToast() {
  const toast = useToast()

  function success(title: string, description?: string): void {
    toast.add({
      title,
      description,
      color: 'success',
      icon: 'i-lucide-circle-check',
    })
  }

  function error(title: string, description?: string): void {
    toast.add({
      title,
      description,
      color: 'error',
      icon: 'i-lucide-circle-alert',
    })
  }

  function fromError(err: unknown, fallback = 'Something went wrong. Please try again.'): void {
    error('Action failed', err instanceof Error && err.message ? err.message : fallback)
  }

  return { success, error, fromError, toast }
}
