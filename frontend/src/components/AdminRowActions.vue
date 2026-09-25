<script setup lang="ts">
import { computed } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import type { AppIconName } from './iconNames'
import { usePermission } from '../features/auth/composables/usePermission'

export type AdminRowAction = {
  key: string
  label: string
  permission: string
  icon: AppIconName
  variant?: 'primary' | 'secondary' | 'ghost'
  destructive?: boolean
}

const props = defineProps<{
  actions: AdminRowAction[]
  busy?:   boolean
}>()

const emit = defineEmits<{ action: [key: string] }>()

const { can } = usePermission()

const visibleActions = computed(() =>
  props.actions.filter(a => can(a.permission)),
)

const dropdownItems = computed<DropdownMenuItem[][]>(() => [
  visibleActions.value.map(a => ({
    label: a.label,
    icon:  `i-lucide-${iconToLucide(a.icon)}`,
    color: a.destructive ? ('error' as const) : undefined,
    disabled: props.busy,
    onSelect() { emit('action', a.key) },
  })),
])

/** Map AppIconName → lucide kebab name for i-lucide-* */
function iconToLucide(name: AppIconName): string {
  const map: Partial<Record<AppIconName, string>> = {
    Check:         'check',
    X:             'x',
    Eye:           'eye',
    ArrowRight:    'arrow-right',
    ArrowLeft:     'arrow-left',
    Download:      'download',
    FileText:      'file-text',
  }
  return map[name] ?? name.toLowerCase().replace(/([A-Z])/g, '-$1').replace(/^-/, '').toLowerCase()
}
</script>

<template>
  <div v-if="visibleActions.length" class="flex justify-end">
    <UDropdownMenu :items="dropdownItems">
      <UButton
        size="xs"
        color="neutral"
        variant="ghost"
        icon="i-lucide-more-horizontal"
        :loading="busy"
        :disabled="busy"
        aria-label="Row actions"
      />
    </UDropdownMenu>
  </div>
</template>
