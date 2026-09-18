import { h } from 'vue'
import type { Column } from '@tanstack/vue-table'
import UButton from '@nuxt/ui/components/Button.vue'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UCheckbox from '@nuxt/ui/components/Checkbox.vue'

export { UButton, UBadge, UCheckbox }

/** Shared UTable theme used across dashboard + report pages. */
export const DATA_TABLE_UI = {
  base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
  thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold tracking-wide text-[var(--ui-text-muted)]',
  td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
  separator: 'h-0',
} as const

export type DataTableFilterOption = {
  label: string
  value: string
}

export type DataTableDisplayColumn = {
  id: string
  label: string
}

export type BadgeColor = 'success' | 'warning' | 'error' | 'neutral' | 'info' | 'primary' | 'secondary'

/**
 * Nuxt UI sortable column header (TanStack `column.toggleSorting`).
 * Imports UButton directly — `resolveComponent` fails inside TanStack header render.
 */
export function createSortableHeader<T>(column: Column<T>, label: string) {
  const isSorted = column.getIsSorted()

  return h(UButton, {
    color: 'neutral',
    variant: 'ghost',
    label,
    icon: isSorted
      ? (isSorted === 'asc' ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-wide-narrow')
      : 'i-lucide-arrow-up-down',
    class: '-mx-2.5',
    onClick: () => column.toggleSorting(column.getIsSorted() === 'asc'),
  })
}

/** Status badge cell helper for report/dashboard tables. */
export function createStatusBadge(
  status: string,
  color: BadgeColor = 'neutral',
  label?: string,
) {
  return h(UBadge, {
    class: 'capitalize',
    variant: 'subtle',
    color,
  }, () => label ?? status.replaceAll('_', ' '))
}
