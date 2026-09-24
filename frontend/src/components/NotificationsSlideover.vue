<script setup lang="ts">
import { watch } from 'vue'
import { useRouter } from 'vue-router'
import { useDashboard } from '../composables/useDashboard'
import { useAdminNotifications } from '../composables/useAdminNotifications'

const router = useRouter()
const { isNotificationsSlideoverOpen } = useDashboard()
const {
  isSuperAdmin,
  notifications,
  loading,
  error,
  loadNotifications,
  markRead,
  markAllRead,
} = useAdminNotifications()

watch(isNotificationsSlideoverOpen, (open) => {
  if (open && isSuperAdmin.value) {
    void loadNotifications()
  }
})

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

async function onSelect(id: string, href: string | null): Promise<void> {
  await markRead(id).catch(() => undefined)
  isNotificationsSlideoverOpen.value = false
  if (href) {
    await router.push(href)
  }
}

async function onMarkAllRead(): Promise<void> {
  await markAllRead().catch(() => undefined)
}
</script>

<template>
  <USlideover
    v-if="isSuperAdmin"
    v-model:open="isNotificationsSlideoverOpen"
    title="Notifications"
    description="Operational alerts for SUPER_ADMIN"
  >
    <template #body>
      <div v-if="loading" class="flex items-center justify-center py-10 text-sm text-[var(--ui-text-dimmed)]">
        Loading…
      </div>

      <div v-else-if="error" class="rounded-lg bg-error/10 px-3 py-3 text-sm text-error">
        {{ error }}
      </div>

      <div v-else-if="notifications.length === 0" class="py-10 text-center text-sm text-[var(--ui-text-dimmed)]">
        No notifications yet.
      </div>

      <div v-else class="space-y-1">
        <button
          v-for="n in notifications"
          :key="n.id"
          type="button"
          class="relative -mx-3 flex w-[calc(100%+1.5rem)] items-start gap-3 rounded-lg px-3 py-3 text-left hover:bg-elevated/50 transition-colors"
          @click="onSelect(n.id, n.href)"
        >
          <UChip color="error" :show="n.unread" inset>
            <UAvatar
              v-bind="n.sender.avatar"
              :alt="n.sender.name"
              size="md"
              :ui="{ fallback: 'bg-[var(--mito-accent-bg)] text-[var(--mito-red)]' }"
            />
          </UChip>

          <div class="flex-1 text-sm">
            <p class="flex items-center justify-between gap-2">
              <span class="font-semibold text-[var(--ui-text-highlighted)]">{{ n.sender.name }}</span>
              <time class="shrink-0 text-xs text-[var(--ui-text-dimmed)]">{{ timeAgo(n.date) }}</time>
            </p>
            <p class="mt-0.5 text-[var(--ui-text-muted)]">{{ n.body }}</p>
          </div>
        </button>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end border-t border-[var(--ui-border)] pt-4">
        <UButton
          color="primary"
          variant="subtle"
          size="sm"
          label="Mark all as read"
          icon="i-lucide-check-check"
          :disabled="notifications.every((n) => !n.unread)"
          @click="onMarkAllRead"
        />
      </div>
    </template>
  </USlideover>
</template>
