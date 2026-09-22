<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { fetchAttendanceReport } from '../../services/reports/attendanceReportApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { AttendanceReportRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<AttendanceReportRow[]>([])
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('all')
const search = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'attendance_date',
  direction: 'asc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Present', value: 'present' },
  { label: 'Late', value: 'late' },
  { label: 'Absent', value: 'absent' },
  { label: 'On leave', value: 'on_leave' },
]

const hideableColumns = [
  { id: 'employee_name', label: 'Employee' },
  { id: 'attendance_date', label: 'Date' },
  { id: 'status', label: 'Status' },
  { id: 'created_at', label: 'Created At' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present: 'success',
  late: 'warning',
  absent: 'error',
  on_leave: 'neutral',
}

const columns = computed<TableColumn<AttendanceReportRow>[]>(() => [
  {
    accessorKey: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
  },
  {
    accessorKey: 'attendance_date',
    header: ({ column }) => createSortableHeader(column, 'Date'),
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    filterFn: 'equals',
    cell: ({ row }) => {
      const status = row.getValue<string>('status')
      return createStatusBadge(status, statusColor[status] ?? 'neutral')
    },
  },
  {
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Created At'),
    cell: ({ row }) => {
      const value = row.getValue<string>('created_at')
      if (!value) return '—'
      const date = new Date(value)
      return isNaN(date.getTime()) ? '—' : date.toLocaleString()
    },
  },
])

watch(search, () => {
  const next: ColumnFiltersState = []
  if (search.value.trim()) next.push({ id: 'employee_name', value: search.value.trim() })
  if (statusFilter.value !== 'all') next.push({ id: 'status', value: statusFilter.value })
  columnFilters.value = next
})

watch(statusFilter, () => {
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
    const res = await fetchAttendanceReport({
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
    await handleApiError(err, 'Unable to load attendance report. Please try again.')
  }
  finally {
    loading.value = false
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
  <UDashboardPanel id="attendance-report">
    <template #header>
      <UDashboardNavbar title="Attendance">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="loading"
            @click="load"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Failed to load"
          :description="error"
        >
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">
              Retry
            </UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar
            :total="meta.total"
            :rows="data"
            filename="attendance-report"
            :loading="loading"
          />

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
            empty-icon="i-lucide-calendar-x"
            empty-message="No attendance records found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
