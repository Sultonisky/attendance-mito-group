import { apiFetch } from './apiClient'

export type AdminNotification = {
  id: string
  sender: {
    name: string
    avatar: { icon: string }
  }
  body: string
  href: string | null
  date: string
  unread: boolean
}

export type AdminNotificationListResponse = {
  success: boolean
  data: AdminNotification[]
  meta: { unread_count: number }
}

export type AdminNotificationUnreadResponse = {
  success: boolean
  data: { unread_count: number }
}

export async function fetchAdminNotifications(): Promise<AdminNotificationListResponse> {
  return apiFetch('/notifications')
}

export async function fetchAdminUnreadCount(): Promise<AdminNotificationUnreadResponse> {
  return apiFetch('/notifications/unread-count')
}

export async function markAdminNotificationRead(
  id: string,
): Promise<{ success: boolean; data: AdminNotification }> {
  return apiFetch(`/notifications/${id}/read`, { method: 'POST' })
}

export async function markAllAdminNotificationsRead(): Promise<AdminNotificationUnreadResponse> {
  return apiFetch('/notifications/read-all', { method: 'POST' })
}
