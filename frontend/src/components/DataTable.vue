<script setup lang="ts" generic="T">
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, SortingState, RowSelectionState, VisibilityState } from '@tanstack/vue-table'
import { DATA_TABLE_UI } from '../utils/dataTable'
import type { ReportMeta } from '../composables/useReportPage'

withDefaults(defineProps<{
  data: T[]
  columns: TableColumn<T>[]
  loading?: boolean
  emptyIcon?: string
  emptyMessage?: string
  meta?: ReportMeta
  manualSorting?: boolean
  getRowId?: (row: T) => string
}>(), {
  loading: false,
  emptyIcon: 'i-lucide-inbox',
  emptyMessage: 'No records found.',
  manualSorting: false,
})

const sorting = defineModel<SortingState>('sorting', { default: () => [] })
const columnFilters = defineModel<ColumnFiltersState>('columnFilters', { default: () => [] })
const columnVisibility = defineModel<VisibilityState>('columnVisibility')
const rowSelection = defineModel<RowSelectionState>('rowSelection')

const emit = defineEmits<{
  'update:page': [page: number]
}>()
</script>

<template>
  <div class="space-y-4">
    <UTable
      v-model:sorting="sorting"
      v-model:column-filters="columnFilters"
      v-model:column-visibility="columnVisibility"
      v-model:row-selection="rowSelection"
      :data="data"
      :columns="columns"
      :loading="loading"
      :get-row-id="getRowId"
      :sorting-options="manualSorting ? { manualSorting: true } : undefined"
      class="shrink-0"
      :ui="DATA_TABLE_UI"
    />

    <div v-if="!loading && !data.length" class="py-16 text-center">
      <UIcon :name="emptyIcon" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
      <p class="text-sm text-[var(--ui-text-muted)]">{{ emptyMessage }}</p>
      <slot name="empty-extra" />
    </div>

    <div
      v-if="meta && meta.last_page > 1"
      class="flex items-center justify-between gap-4 border-t border-[var(--ui-border)] pt-4"
    >
      <p class="text-xs text-[var(--ui-text-muted)]">
        Page {{ meta.current_page }} of {{ meta.last_page }}
      </p>
      <UPagination
        :page="meta.current_page"
        :total="meta.last_page"
        :items-per-page="1"
        show-edges
        :disabled="loading"
        @update:page="emit('update:page', $event)"
      />
    </div>
  </div>
</template>
