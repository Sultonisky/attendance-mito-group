import { computed, ref } from 'vue'
import { createSharedComposable } from '@vueuse/core'
import { usePermission } from '../features/auth/composables/usePermission'
import { ApiError } from '../services/apiClient'
import {
  fetchAdminNotifications,
  fetchAdminUnreadCount,
  markAdminNotificationRead,
  markAllAdminNotificationsRead,
  type AdminNotification,
} from '../services/notificationApi'

const _useAdminNotifications = () => {
  const { hasRole } = usePermission()

  const notifications = ref<AdminNotification[]>([])
  const unreadCount = ref(0)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isSuperAdmin = computed(() => hasRole('SUPER_ADMIN'))
  const hasUnread = computed(() => unreadCount.value > 0)

  async function refreshUnreadCount(): Promise<void> {
    if (!isSuperAdmin.value) {
      unreadCount.value = 0
      return
    }

    try {
      const response = await fetchAdminUnreadCount()
      unreadCount.value = response.data.unread_count
    } catch {
      // Bell UX only — keep last known count on transient failure.
    }
  }

  async function loadNotifications(): Promise<void> {
    if (!isSuperAdmin.value) {
      notifications.value = []
      unreadCount.value = 0
      return
    }

    loading.value = true
    error.value = null

    try {
      const response = await fetchAdminNotifications()
      notifications.value = response.data
      unreadCount.value = response.meta.unread_count
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Failed to load notifications.'
      notifications.value = []
    } finally {
      loading.value = false
    }
  }

  async function markRead(id: string): Promise<void> {
    if (!isSuperAdmin.value) return

    await markAdminNotificationRead(id)
    const row = notifications.value.find((n) => n.id === id)
    if (row && row.unread) {
      row.unread = false
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
  }

  async function markAllRead(): Promise<void> {
    if (!isSuperAdmin.value) return

    await markAllAdminNotificationsRead()
    notifications.value = notifications.value.map((n) => ({ ...n, unread: false }))
    unreadCount.value = 0
  }

  return {
    isSuperAdmin,
    notifications,
    unreadCount,
    hasUnread,
    loading,
    error,
    refreshUnreadCount,
    loadNotifications,
    markRead,
    markAllRead,
  }
}

export const useAdminNotifications = createSharedComposable(_useAdminNotifications)
