<script setup lang="ts">
import { useDashboard } from '../composables/useDashboard'

const { isNotificationsSlideoverOpen } = useDashboard()

// Sample notifications — in production, replace with real API data
const notifications = [
  {
    id: 1,
    sender: { name: 'HR System', avatar: { icon: 'i-lucide-bell' } },
    body: 'Monthly recap for August is ready for review.',
    date: new Date(Date.now() - 1000 * 60 * 5).toISOString(),
    unread: true,
  },
  {
    id: 2,
    sender: { name: 'Attendance Engine', avatar: { icon: 'i-lucide-calendar-check-2' } },
    body: '3 employees have not checked in today.',
    date: new Date(Date.now() - 1000 * 60 * 30).toISOString(),
    unread: true,
  },
  {
    id: 3,
    sender: { name: 'Leave Module', avatar: { icon: 'i-lucide-calendar-off' } },
    body: 'New leave request from Budi Santoso pending approval.',
    date: new Date(Date.now() - 1000 * 60 * 60 * 2).toISOString(),
    unread: false,
  },
]

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}
</script>

<template>
  <USlideover
    v-model:open="isNotificationsSlideoverOpen"
    title="Notifications"
    description="Recent activity from MITO People Operations"
  >
    <template #body>
      <div class="space-y-1">
        <div
          v-for="n in notifications"
          :key="n.id"
          class="relative -mx-3 flex items-start gap-3 rounded-lg px-3 py-3 hover:bg-elevated/50 transition-colors cursor-default"
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
        </div>
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
          @click="isNotificationsSlideoverOpen = false"
        />
      </div>
    </template>
  </USlideover>
</template>
