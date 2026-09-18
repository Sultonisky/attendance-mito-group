<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import { useReportPage } from '../../composables/useReportPage'
import { fetchLeaveReport } from '../../services/reports/leaveReportApi'
import { approveLeaveRequest, cancelLeaveRequest, rejectLeaveRequest } from '../../services/adminCrudApi'
import ReportFilterBar from '../../components/ReportFilterBar.vue'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import type { LeaveReportRow, ReportSortOption } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route  = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage, sortColumn } = useReportPage()

const data          = ref<LeaveReportRow[]>([])
const actionBusyId  = ref<number | null>(null)

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'start_date',
  direction: 'desc' as 'asc' | 'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Start Date',    value: 'start_date'    },
  { label: 'End Date',      value: 'end_date'      },
  { label: 'Status',        value: 'status'        },
  { label: 'Employee Name', value: 'employee_name' },
  { label: 'Created At',    value: 'created_at'    },
]

const rowActions: AdminRowAction[] = [
  { key: 'approve', label: 'Approve', permission: 'leave.approve', icon: 'Check',  variant: 'primary' },
  { key: 'reject',  label: 'Reject',  permission: 'leave.reject',  icon: 'X',      variant: 'secondary', destructive: true },
  { key: 'cancel',  label: 'Cancel',  permission: 'leave.cancel',  icon: 'X',      variant: 'ghost' },
]

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'info'> = {
  approved: 'success', pending: 'warning', rejected: 'error', cancelled: 'neutral',
}

const UBadge = resolveComponent('UBadge')

const columns = computed<TableColumn<LeaveReportRow>[]>(() => [
  { accessorKey: 'employee_name',   header: 'Employee' },
  { accessorKey: 'leave_type_name', header: 'Type'     },
  { accessorKey: 'start_date',      header: 'Start'    },
  { accessorKey: 'end_date',        header: 'End'      },
  {
    accessorKey: 'status', header: 'Status',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return h(UBadge, { color: statusColor[s] ?? 'neutral', variant: 'subtle', class: 'capitalize' }, () => s)
    },
  },
  { accessorKey: 'reason', header: 'Reason' },
  {
    id: 'actions', header: 'Actions',
    cell: ({ row }) => h(AdminRowActions, {
      actions: rowActions,
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original.id),
    }),
  },
])

async function load(): Promise<void> {
  loading.value = true; error.value = ''
  try {
    const res = await fetchLeaveReport({
      from: filters.from, to: filters.to,
      employee_id: filters.employee_id || null,
      per_page: filters.per_page, sort: filters.sort,
      direction: filters.direction, page: meta.current_page,
    })
    data.value = res.data; applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load leave report. Please try again.')
  } finally { loading.value = false }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id; error.value = ''
  try {
    if (action === 'approve') await approveLeaveRequest(id)
    if (action === 'cancel')  await cancelLeaveRequest(id)
    if (action === 'reject') {
      const reason = window.prompt('Reason for rejection')
      if (reason === null) return
      await rejectLeaveRequest(id, reason)
    }
    await load()
  } catch { error.value = 'Unable to update this leave request. Please try again.'
  } finally { actionBusyId.value = null }
}

onMounted(() => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to   === 'string') filters.to   = route.query.to
  load()
})
</script>

<template>
  <UDashboardPanel id="leave-report">
    <template #header>
      <UDashboardNavbar title="Leave">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">Refresh</UButton>
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <template #left>
          <ReportFilterBar
            :filters="filters" :loading="loading" :sort-options="sortOptions"
            @update:from="filters.from = $event" @update:to="filters.to = $event"
            @update:employee_id="filters.employee_id = $event" @update:per_page="filters.per_page = $event"
            @update:sort="v => sortColumn(v, filters.direction, filters, load)"
            @update:direction="v => sortColumn(filters.sort, v, filters, load)"
            @search="() => { meta.current_page = 1; load() }"
          />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="leave-report" :loading="loading" />
          <UTable :data="data" :columns="columns" :loading="loading" :ui="{
            base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
            thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-muted)]',
            td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
          }" />
          <div v-if="!loading && !data.length" class="py-16 text-center">
            <UIcon name="i-lucide-calendar-off" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
            <p class="text-sm text-[var(--ui-text-muted)]">No leave records found for the selected filters.</p>
          </div>
          <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-[var(--ui-border)] pt-4">
            <p class="text-xs text-[var(--ui-text-muted)]">Page {{ meta.current_page }} of {{ meta.last_page }}</p>
            <UPagination :page="meta.current_page" :total="meta.last_page" :items-per-page="1" show-edges :disabled="loading" @update:page="goToPage($event, load)" />
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
