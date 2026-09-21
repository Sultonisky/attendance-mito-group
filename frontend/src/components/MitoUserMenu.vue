<script setup lang="ts">
import { computed } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

defineProps<{
  collapsed?: boolean
}>()

const auth   = useAuthStore()
const router = useRouter()

const userInitial = computed(() =>
  auth.user?.name?.charAt(0).toUpperCase() ?? '?',
)
const userName = computed(() => auth.user?.name ?? 'Admin')
const userRole = computed(() => auth.roles.join(', ') || 'Admin')

async function handleLogout() {
  await auth.logout()
  await router.push({ name: 'login.admin' })
}

const items = computed<DropdownMenuItem[][]>(() => [
  [{
    type: 'label' as const,
    label: userName.value,
    avatar: { text: userInitial.value },
    // show role below name
    slot: 'user-label',
  }],
  [{
    label: 'Sign out',
    icon: 'i-lucide-log-out',
    color: 'error' as const,
    onSelect() {
      handleLogout()
    },
  }],
])
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{ trailingIcon: 'text-dimmed' }"
      v-bind="{
        label: collapsed ? undefined : userName,
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down',
        avatar: { text: userInitial },
      }"
    />

    <template #user-label>
      <div class="flex flex-col px-1">
        <span class="text-sm font-semibold text-[var(--ui-text-highlighted)]">{{ userName }}</span>
        <span class="text-xs text-[var(--ui-text-dimmed)]">{{ userRole }}</span>
      </div>
    </template>
  </UDropdownMenu>
</template>
