import { ref, watch, onScopeDispose } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { createSharedComposable } from '@vueuse/core'

const _useDashboard = () => {
  const route = useRoute()
  const router = useRouter()

  const isNotificationsSlideoverOpen = ref(false)

  // Keyboard shortcuts — g+key navigate, n toggles notifications.
  // Guarded so the shared composable never registers the listener twice
  // (AdminLayout + DashboardPage both consume it) and always cleans up,
  // otherwise a stale window listener can fire router.push() mid-unmount
  // and crash Vue's patch/unmount cycle on navigation.
  if (typeof window !== 'undefined') {
    let gPressed = false
    let gTimer: ReturnType<typeof setTimeout> | undefined
    let attached = false

    function onKeydown(e: KeyboardEvent): void {
      // Skip when typing in an input/textarea/select or contentEditable
      const target = e.target as HTMLElement | null
      const tag = target?.tagName
      if (tag && ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return
      if (target?.isContentEditable) return

      if (e.key === 'g') {
        gPressed = true
        if (gTimer) clearTimeout(gTimer)
        gTimer = setTimeout(() => { gPressed = false }, 1000)
        return
      }

      if (gPressed) {
        gPressed = false
        if (gTimer) clearTimeout(gTimer)
        const nav: Record<string, string> = {
          h: '/dashboard',
          a: '/dashboard/reports/attendance',
          l: '/dashboard/reports/leave',
          o: '/dashboard/reports/overtime',
          p: '/dashboard/reports/penalties',
          m: '/dashboard/reports/monthly-recaps',
        }
        if (nav[e.key]) void router.push(nav[e.key])
        return
      }

      if (e.key === 'n') {
        isNotificationsSlideoverOpen.value = !isNotificationsSlideoverOpen.value
      }
    }

    if (!attached) {
      attached = true
      window.addEventListener('keydown', onKeydown)
      onScopeDispose(() => {
        attached = false
        window.removeEventListener('keydown', onKeydown)
        if (gTimer) clearTimeout(gTimer)
      })
    }
  }

  watch(() => route.fullPath, () => {
    isNotificationsSlideoverOpen.value = false
  })

  return { isNotificationsSlideoverOpen }
}

export const useDashboard = createSharedComposable(_useDashboard)
