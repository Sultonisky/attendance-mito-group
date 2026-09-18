<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import { useReportPage } from '../../composables/useReportPage'
import { fetchAttendanceReport } from '../../services/reports/attendanceReportApi'
import ReportFilterBar from '../../components/ReportFilterBar.vue'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import type { AttendanceReportRow, ReportSortOption } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route  = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage, sortColumn } = useReportPage()

const data = ref<AttendanceReportRow[]>([])

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'attendance_date',
  direction: 'asc' as 'asc' | 'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Attendance Date', value: 'attendance_date' },
  { label: 'Employee Name',   value: 'employee_name'   },
  { label: 'Status',          value: 'status'          },
  { label: 'Created At',      value: 'created_at'      },
]

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present:    'success',
  late:       'warning',
  absent:     'error',
  on_leave:   'neutral',
}

const columns = computed<TableColumn<AttendanceReportRow>[]>(() => [
  { accessorKey: 'employee_name', header: 'Employee' },
  { accessorKey: 'attendance_date', header: 'Date' },
  {
    accessorKey: 'status',
    header: 'Status',
    cell: ({ row }) => {
      const status = row.getValue<string>('status')
      return h(resolveComponent('UBadge'), {
        color: statusColor[status] ?? 'neutral',
        variant: 'subtle',
        class: 'capitalize',
      }, () => status?.replace('_', ' ') ?? '-')
    },
  },
  {
    accessorKey: 'created_at',
    header: 'Created At',
    cell: ({ row }) => new Date(row.getValue<string>('created_at') + 'Z').toLocaleString(),
  },
])

async function load(): Promise<void> {
  loading.value = true
  error.value   = ''
  try {
    const res = await fetchAttendanceReport({
      from: filters.from, to: filters.to,
      employee_id: filters.employee_id || null,
      per_page: filters.per_page, sort: filters.sort,
      direction: filters.direction, page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load attendance report. Please try again.')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to   === 'string') filters.to   = route.query.to
  load()
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
            color="neutral" variant="outline" size="sm"
            icon="i-lucide-refresh-cw" :loading="loading"
            @click="load"
          >Refresh</UButton>
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <template #left>
          <ReportFilterBar
            :filters="filters" :loading="loading" :sort-options="sortOptions"
            @update:from="filters.from = $event"
            @update:to="filters.to = $event"
            @update:employee_id="filters.employee_id = $event"
            @update:per_page="filters.per_page = $event"
            @update:sort="v => sortColumn(v, filters.direction, filters, load)"
            @update:direction="v => sortColumn(filters.sort, v, filters, load)"
            @search="() => { meta.current_page = 1; load() }"
          />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">

        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert"
          title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="attendance-report" :loading="loading" />

          <UTable
            :data="data"
            :columns="columns"
            :loading="loading"
            :ui="{
              base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
              thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
              tbody: '[&>tr]:last:[&>td]:border-b-0',
              th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-muted)]',
              td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
            }"
          />

          <div v-if="!loading && !data.length" class="py-16 text-center">
            <UIcon name="i-lucide-calendar-x" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
            <p class="text-sm text-[var(--ui-text-muted)]">No attendance records found for the selected filters.</p>
          </div>

          <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-[var(--ui-border)] pt-4">
            <p class="text-xs text-[var(--ui-text-muted)]">
              Page {{ meta.current_page }} of {{ meta.last_page }}
            </p>
            <UPagination
              :page="meta.current_page" :total="meta.last_page" :items-per-page="1"
              show-edges :disabled="loading"
              @update:page="goToPage($event, load)"
            />
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
