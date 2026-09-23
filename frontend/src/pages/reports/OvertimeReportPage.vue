<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { useAppToast } from '../../composables/useAppToast'
import { fetchOvertimeReport } from '../../services/reports/overtimeReportApi'
import { approveOvertimeRequest, cancelOvertimeRequest, rejectOvertimeRequest } from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { OvertimeReportRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const toast = useAppToast()

const data          = ref<OvertimeReportRow[]>([])
const actionBusyId  = ref<number | null>(null)
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter  = ref('all')
const search        = ref('')

// ── Reject modal ──────────────────────────────────────────────────────────────
const showRejectModal = ref(false)
const rejectTargetId  = ref<number | null>(null)
const rejectReason    = ref('')
const rejectBusy      = ref(false)
const rejectError     = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'date',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All',       value: 'all'       },
  { label: 'Pending',   value: 'pending'   },
  { label: 'Approved',  value: 'approved'  },
  { label: 'Rejected',  value: 'rejected'  },
  { label: 'Cancelled', value: 'cancelled' },
]

const hideableColumns = [
  { id: 'employee_name',    label: 'Employee'  },
  { id: 'date',             label: 'Date'      },
  { id: 'potential_minutes',label: 'Potential' },
  { id: 'requested_minutes',label: 'Requested' },
  { id: 'approved_minutes', label: 'Approved'  },
  { id: 'actual_minutes',   label: 'Actual'    },
  { id: 'status',           label: 'Status'    },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const rowActions: AdminRowAction[] = [
  { key: 'approve', label: 'Approve', permission: 'overtime.approve', icon: 'Check', variant: 'primary' },
  { key: 'reject',  label: 'Reject',  permission: 'overtime.reject',  icon: 'X', variant: 'secondary', destructive: true },
  { key: 'cancel',  label: 'Cancel',  permission: 'overtime.cancel',  icon: 'X', variant: 'ghost' },
]

/** Actions valid per-status — only pending requests can be actioned. */
function overtimeActionsFor(status: string): AdminRowAction[] {
  if (status === 'pending') return rowActions
  return []
}

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  approved:  'success',
  pending:   'warning',
  rejected:  'error',
  cancelled: 'neutral',
}

const columns = computed<TableColumn<OvertimeReportRow>[]>(() => [
  {
    accessorKey: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
  },
  {
    accessorKey: 'date',
    header: ({ column }) => createSortableHeader(column, 'Date'),
  },
  {
    accessorKey: 'potential_minutes',
    header: ({ column }) => createSortableHeader(column, 'Potential'),
  },
  {
    accessorKey: 'requested_minutes',
    header: ({ column }) => createSortableHeader(column, 'Requested'),
  },
  {
    accessorKey: 'approved_minutes',
    header: ({ column }) => createSortableHeader(column, 'Approved'),
  },
  {
    accessorKey: 'actual_minutes',
    header: ({ column }) => createSortableHeader(column, 'Actual'),
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
    id: 'actions',
    header: 'Actions',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => {
      const oid = row.original.overtime_request_id
      if (!oid) return h('span', { class: 'text-xs text-[var(--ui-text-dimmed)]' }, 'No request')
      return h(AdminRowActions, {
        actions: overtimeActionsFor(row.original.status),
        busy: actionBusyId.value === oid,
        onAction: (key: string) => handleRowAction(key, oid),
      })
    },
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
  error.value   = ''
  try {
    const res = await fetchOvertimeReport({
      from:        filters.from,
      to:          filters.to,
      employee_id: filters.employee_id || null,
      per_page:    filters.per_page,
      sort:        filters.sort,
      direction:   filters.direction,
      page:        meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load overtime report. Please try again.')
  } finally {
    loading.value = false
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  if (action === 'reject') {
    rejectTargetId.value  = id
    rejectReason.value    = ''
    rejectError.value     = ''
    showRejectModal.value = true
    return
  }

  actionBusyId.value = id
  error.value        = ''
  try {
    if (action === 'approve') {
      await approveOvertimeRequest(id)
      toast.success('Overtime approved')
    }
    if (action === 'cancel') {
      await cancelOvertimeRequest(id)
      toast.success('Overtime cancelled')
    }
    await load()
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Unable to update this overtime request. Please try again.'
    toast.fromError(e, 'Unable to update this overtime request.')
  } finally {
    actionBusyId.value = null
  }
}

async function submitReject(): Promise<void> {
  if (!rejectReason.value.trim()) {
    rejectError.value = 'A reason is required to reject this request.'
    return
  }
  if (rejectTargetId.value === null) return

  rejectBusy.value  = true
  rejectError.value = ''
  try {
    await rejectOvertimeRequest(rejectTargetId.value, rejectReason.value.trim())
    showRejectModal.value = false
    rejectTargetId.value  = null
    toast.success('Overtime rejected')
    await load()
  } catch (e: unknown) {
    rejectError.value = e instanceof Error ? e.message : 'Unable to reject this request. Please try again.'
  } finally {
    rejectBusy.value = false
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string')   filters.to   = route.query.to
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="overtime-report">
    <template #header>
      <UDashboardNavbar title="Overtime">
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
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Failed to load"
          :description="error"
        >
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="overtime-report" :loading="loading" />
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
            empty-icon="i-lucide-bar-chart-3"
            empty-message="No overtime records found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Reject modal ───────────────────────────────────────────────────────── -->
  <UModal v-model:open="showRejectModal" title="Reject overtime request">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">Provide a reason for rejecting this overtime request.</p>
        <UFormField label="Reason" required>
          <UTextarea
            v-model="rejectReason"
            placeholder="Enter rejection reason…"
            :rows="3"
            class="w-full"
            autofocus
          />
        </UFormField>
        <UAlert v-if="rejectError" color="error" variant="subtle" :description="rejectError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="rejectBusy" @click="showRejectModal = false">
          Cancel
        </UButton>
        <UButton color="error" :loading="rejectBusy" @click="submitReject">
          Reject
        </UButton>
      </div>
    </template>
  </UModal>
</template>
