<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { fetchPenaltyReport } from '../../services/reports/penaltyReportApi'
import { adjustPenalty, voidPenalty } from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { PenaltyReportRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<PenaltyReportRow[]>([])
const actionBusyId = ref<number | null>(null)
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('all')
const search = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'occurred_at',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Active', value: 'active' },
  { label: 'Adjusted', value: 'adjusted' },
  { label: 'Voided', value: 'voided' },
]

const hideableColumns = [
  { id: 'employee_name', label: 'Employee' },
  { id: 'penalty_rule_name', label: 'Rule' },
  { id: 'source', label: 'Source' },
  { id: 'violation_type', label: 'Violation' },
  { id: 'original_points', label: 'Original' },
  { id: 'adjusted_points', label: 'Adjusted' },
  { id: 'final_points', label: 'Final' },
  { id: 'status', label: 'Status' },
  { id: 'occurred_at', label: 'Occurred At' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const rowActions: AdminRowAction[] = [
  { key: 'adjust', label: 'Adjust', permission: 'penalty.adjust', icon: 'ArrowRight', variant: 'secondary' },
  { key: 'void', label: 'Void', permission: 'penalty.void', icon: 'X', variant: 'ghost', destructive: true },
]

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  active: 'error',
  adjusted: 'warning',
  voided: 'neutral',
}

const columns = computed<TableColumn<PenaltyReportRow>[]>(() => [
  {
    accessorKey: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
  },
  {
    accessorKey: 'penalty_rule_name',
    header: ({ column }) => createSortableHeader(column, 'Rule'),
  },
  {
    accessorKey: 'source',
    header: ({ column }) => createSortableHeader(column, 'Source'),
  },
  {
    accessorKey: 'violation_type',
    header: ({ column }) => createSortableHeader(column, 'Violation'),
  },
  {
    accessorKey: 'original_points',
    header: ({ column }) => createSortableHeader(column, 'Original'),
  },
  {
    accessorKey: 'adjusted_points',
    header: ({ column }) => createSortableHeader(column, 'Adjusted'),
  },
  {
    accessorKey: 'final_points',
    header: ({ column }) => createSortableHeader(column, 'Final'),
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    filterFn: 'equals',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return createStatusBadge(s, statusColor[s] ?? 'neutral')
    },
  },
  {
    accessorKey: 'occurred_at',
    header: ({ column }) => createSortableHeader(column, 'Occurred At'),
    cell: ({ row }) => new Date(row.getValue<string>('occurred_at') + 'Z').toLocaleString(),
  },
  {
    id: 'actions',
    header: 'Actions',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => h(AdminRowActions, {
      actions: rowActions,
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original.id),
    }),
  },
])

watch([search, statusFilter], () => {
  const next: ColumnFiltersState = []
  if (search.value.trim()) next.push({ id: 'employee_name', value: search.value.trim() })
  if (statusFilter.value !== 'all') next.push({ id: 'status', value: statusFilter.value })
  columnFilters.value = next
})

watch(
  () => [filters.from, filters.to, filters.employee_id, filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    load()
  },
)

const ready = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await fetchPenaltyReport({
      from: filters.from,
      to: filters.to,
      employee_id: filters.employee_id || null,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  }
  catch (err) {
    await handleApiError(err, 'Unable to load penalty report. Please try again.')
  }
  finally {
    loading.value = false
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id
  error.value = ''
  try {
    const reason = window.prompt(action === 'adjust' ? 'Adjustment reason' : 'Void reason')
    if (reason === null) return
    if (action === 'adjust') {
      const points = Number(window.prompt('Final points', '0'))
      if (!Number.isFinite(points)) return
      await adjustPenalty(id, points, reason)
    }
    else {
      await voidPenalty(id, reason)
    }
    await load()
  }
  catch {
    error.value = 'Unable to update this penalty. Please try again.'
  }
  finally {
    actionBusyId.value = null
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string') filters.to = route.query.to
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="penalty-report">
    <template #header>
      <UDashboardNavbar title="Penalties">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>
        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="penalty-report" :loading="loading" />
          <DataTableToolbar
            v-model:search="search"
            v-model:status="statusFilter"
            v-model:from="filters.from"
            v-model:to="filters.to"
            v-model:employee-id="filters.employee_id"
            v-model:per-page="filters.per_page"
            search-placeholder="Filter employees..."
            :status-options="statusOptions"
            :display-items="displayItems"
            show-date-range
            show-employee-id
            show-per-page
          />
          <DataTable
            v-model:sorting="sorting"
            v-model:column-filters="columnFilters"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-triangle-alert"
            empty-message="No penalty records found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
