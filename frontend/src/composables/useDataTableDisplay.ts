import { computed, toValue, type MaybeRefOrGetter, type Ref } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import type { DataTableDisplayColumn } from '../utils/dataTable'

/**
 * Builds the Nuxt UI "Display" dropdown checklist for column visibility.
 */
export function useDataTableDisplay(
  columns: MaybeRefOrGetter<DataTableDisplayColumn[]>,
  columnVisibility: Ref<Record<string, boolean> | undefined>,
) {
  const displayItems = computed<DropdownMenuItem[]>(() =>
    toValue(columns).map(column => ({
      label: column.label,
      type: 'checkbox' as const,
      checked: columnVisibility.value?.[column.id] !== false,
      onUpdateChecked(checked: boolean) {
        columnVisibility.value = {
          ...columnVisibility.value,
          [column.id]: checked,
        }
      },
      onSelect(e?: Event) {
        e?.preventDefault()
      },
    })),
  )

  return { displayItems }
}
