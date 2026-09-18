<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import { useReportPage } from '../../composables/useReportPage'
import { fetchOvertimeReport } from '../../services/reports/overtimeReportApi'
import { approveOvertimeRequest, cancelOvertimeRequest, rejectOvertimeRequest } from '../../services/adminCrudApi'
import ReportFilterBar from '../../components/ReportFilterBar.vue'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import type { OvertimeReportRow, ReportSortOption } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route  = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage, sortColumn } = useReportPage()

const data         = ref<OvertimeReportRow[]>([])
const actionBusyId = ref<number | null>(null)

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '', per_page: 25, sort: 'date', direction: 'desc' as 'asc' | 'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Date',              value: 'date'              },
  { label: 'Status',            value: 'status'            },
  { label: 'Employee Name',     value: 'employee_name'     },
  { label: 'Potential Minutes', value: 'potential_minutes' },
  { label: 'Approved Minutes',  value: 'approved_minutes'  },
  { label: 'Created At',        value: 'created_at'        },
]

const rowActions: AdminRowAction[] = [
  { key: 'approve', label: 'Approve', permission: 'overtime.approve', icon: 'Check', variant: 'primary' },
  { key: 'reject',  label: 'Reject',  permission: 'overtime.reject',  icon: 'X',     variant: 'secondary', destructive: true },
  { key: 'cancel',  label: 'Cancel',  permission: 'overtime.cancel',  icon: 'X',     variant: 'ghost' },
]

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  approved: 'success', pending: 'warning', rejected: 'error', cancelled: 'neutral',
}

const UBadge = resolveComponent('UBadge')

const columns = computed<TableColumn<OvertimeReportRow>[]>(() => [
  { accessorKey: 'employee_name',     header: 'Employee'  },
  { accessorKey: 'date',              header: 'Date'      },
  { accessorKey: 'potential_minutes', header: 'Potential' },
  { accessorKey: 'requested_minutes', header: 'Requested' },
  { accessorKey: 'approved_minutes',  header: 'Approved'  },
  { accessorKey: 'actual_minutes',    header: 'Actual'    },
  {
    accessorKey: 'status', header: 'Status',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return h(UBadge, { color: statusColor[s] ?? 'neutral', variant: 'subtle', class: 'capitalize' }, () => s)
    },
  },
  {
    id: 'actions', header: 'Actions',
    cell: ({ row }) => {
      const oid = row.original.overtime_request_id
      if (!oid) return h('span', { class: 'text-xs text-[var(--ui-text-dimmed)]' }, 'No request')
      return h(AdminRowActions, {
        actions: rowActions,
        busy: actionBusyId.value === oid,
        onAction: (key: string) => handleRowAction(key, oid),
      })
    },
  },
])

async function load(): Promise<void> {
  loading.value = true; error.value = ''
  try {
    const res = await fetchOvertimeReport({
      from: filters.from, to: filters.to,
      employee_id: filters.employee_id || null,
      per_page: filters.per_page, sort: filters.sort,
      direction: filters.direction, page: meta.current_page,
    })
    data.value = res.data; applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load overtime report. Please try again.')
  } finally { loading.value = false }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id; error.value = ''
  try {
    if (action === 'approve') await approveOvertimeRequest(id)
    if (action === 'cancel')  await cancelOvertimeRequest(id)
    if (action === 'reject') {
      const reason = window.prompt('Reason for rejection')
      if (reason === null) return
      await rejectOvertimeRequest(id, reason)
    }
    await load()
  } catch { error.value = 'Unable to update this overtime request. Please try again.'
  } finally { actionBusyId.value = null }
}

onMounted(() => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to   === 'string') filters.to   = route.query.to
  load()
})
</script>

<template>
  <UDashboardPanel id="overtime-report">
    <template #header>
      <UDashboardNavbar title="Overtime">
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
          <template #actions><UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton></template>
        </UAlert>
        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="overtime-report" :loading="loading" />
          <UTable :data="data" :columns="columns" :loading="loading" :ui="{
            base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
            thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-muted)]',
            td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
          }" />
          <div v-if="!loading && !data.length" class="py-16 text-center">
            <UIcon name="i-lucide-bar-chart-3" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
            <p class="text-sm text-[var(--ui-text-muted)]">No overtime records found for the selected filters.</p>
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
