import { ref, watch, type Ref } from 'vue'
import type { SortingState } from '@tanstack/vue-table'

type SortFilters = {
  sort: string
  direction: 'asc' | 'desc'
}

/**
 * Bridges Nuxt UI / TanStack `sorting` state to server-side report filters.
 * Use with `manualSorting: true` so UTable does not re-sort the page locally.
 */
export function useDataTableSort(
  filters: SortFilters,
  onChange: () => void,
  options?: { immediate?: boolean },
): { sorting: Ref<SortingState> } {
  const sorting = ref<SortingState>([{
    id: filters.sort,
    desc: filters.direction === 'desc',
  }])

  let skipNext = false

  watch(
    sorting,
    (value) => {
      if (skipNext) {
        skipNext = false
        return
      }

      const first = value[0]
      if (!first) return

      const nextDirection: 'asc' | 'desc' = first.desc ? 'desc' : 'asc'
      if (filters.sort === first.id && filters.direction === nextDirection) return

      filters.sort = first.id
      filters.direction = nextDirection
      onChange()
    },
    { deep: true },
  )

  watch(
    () => [filters.sort, filters.direction] as const,
    ([sort, direction]) => {
      const current = sorting.value[0]
      const desc = direction === 'desc'
      if (current?.id === sort && current.desc === desc) return
      skipNext = true
      sorting.value = [{ id: sort, desc }]
    },
  )

  if (options?.immediate) {
    // no-op: initial state already mirrors filters
  }

  return { sorting }
}
