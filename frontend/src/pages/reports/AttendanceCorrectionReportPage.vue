<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { useAppToast } from '../../composables/useAppToast'
import {
  approveAttendanceCorrection,
  cancelAttendanceCorrection,
  fetchAttendanceCorrections,
  rejectAttendanceCorrection,
} from '../../services/attendanceCorrectionApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import { formatAttendanceDateTime, formatAttendanceShortDate } from '../../utils/attendanceDateTime'
import type { AttendanceCorrectionRequest } from '../../types/attendanceCorrection'
import { defaultReportDates } from '../../types/reportDates'

type CorrectionRow = AttendanceCorrectionRequest & {
  employee_name: string
  type_label: string
  check_in_label: string
  check_out_label: string
}

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const toast = useAppToast()

const data = ref<CorrectionRow[]>([])
const actionBusyId = ref<number | null>(null)
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('pending')
const search = ref('')

const showRejectModal = ref(false)
const rejectTargetId = ref<number | null>(null)
const rejectReason = ref('')
const rejectBusy = ref(false)
const rejectError = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'id',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Cancelled', value: 'cancelled' },
]

const hideableColumns = [
  { id: 'employee_name', label: 'Employee' },
  { id: 'type_label', label: 'Type' },
  { id: 'attendance_date', label: 'Date' },
  { id: 'check_in_label', label: 'Clock in' },
  { id: 'check_out_label', label: 'Clock out' },
  { id: 'status', label: 'Status' },
  { id: 'reason', label: 'Reason' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const rowActions: AdminRowAction[] = [
  { key: 'approve', label: 'Approve', permission: 'attendance.correction.approve', icon: 'Check', variant: 'primary' },
  { key: 'reject', label: 'Reject', permission: 'attendance.correction.reject', icon: 'X', variant: 'secondary', destructive: true },
  { key: 'cancel', label: 'Cancel', permission: 'attendance.correction.cancel', icon: 'X', variant: 'ghost' },
]

function actionsFor(status: string): AdminRowAction[] {
  if (status === 'pending') return rowActions
  return []
}

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral' | 'info'> = {
  approved: 'success',
  pending: 'warning',
  rejected: 'error',
  cancelled: 'neutral',
}

function typeLabel(type: string): string {
  if (type === 'clock_in') return 'Clock in'
  if (type === 'clock_out') return 'Clock out'
  if (type === 'both') return 'Both'
  return type
}

function mapRow(item: AttendanceCorrectionRequest): CorrectionRow {
  return {
    ...item,
    employee_name: item.employee_name || `Employee #${item.employee_id}`,
    type_label: typeLabel(item.request_type),
    check_in_label: formatAttendanceDateTime(item.requested_check_in_at, item.attendance_date, '—'),
    check_out_label: formatAttendanceDateTime(item.requested_check_out_at, item.attendance_date, '—'),
  }
}

const columns = computed<TableColumn<CorrectionRow>[]>(() => [
  {
    accessorKey: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
  },
  {
    accessorKey: 'type_label',
    header: ({ column }) => createSortableHeader(column, 'Type'),
  },
  {
    accessorKey: 'attendance_date',
    header: ({ column }) => createSortableHeader(column, 'Date'),
    cell: ({ row }) => formatAttendanceShortDate(row.original.attendance_date),
  },
  {
    accessorKey: 'check_in_label',
    header: ({ column }) => createSortableHeader(column, 'Clock in'),
  },
  {
    accessorKey: 'check_out_label',
    header: ({ column }) => createSortableHeader(column, 'Clock out'),
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
    accessorKey: 'reason',
    header: ({ column }) => createSortableHeader(column, 'Reason'),
  },
  {
    id: 'actions',
    header: 'Actions',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => h(AdminRowActions, {
      actions: actionsFor(row.original.status),
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original.id),
    }),
  },
])

watch(search, () => {
  columnFilters.value = search.value.trim()
    ? [{ id: 'employee_name', value: search.value.trim() }]
    : []
})

watch(statusFilter, () => {
  if (!ready.value) return
  meta.current_page = 1
  void load()
})

watch(
  () => [filters.from, filters.to, filters.employee_id, filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    void load()
  },
)

const ready = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await fetchAttendanceCorrections({
      from: filters.from,
      to: filters.to,
      employee_id: filters.employee_id || undefined,
      per_page: filters.per_page,
      page: meta.current_page,
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
    })
    data.value = res.data.map(mapRow)
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load attendance correction requests. Please try again.')
  } finally {
    loading.value = false
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  if (action === 'reject') {
    rejectTargetId.value = id
    rejectReason.value = ''
    rejectError.value = ''
    showRejectModal.value = true
    return
  }

  actionBusyId.value = id
  error.value = ''
  try {
    if (action === 'approve') {
      await approveAttendanceCorrection(id)
      toast.success('Correction approved')
    }
    if (action === 'cancel') {
      await cancelAttendanceCorrection(id)
      toast.success('Correction cancelled')
    }
    await load()
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Unable to update this correction request.'
    toast.fromError(e, 'Unable to update this correction request.')
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

  rejectBusy.value = true
  rejectError.value = ''
  try {
    await rejectAttendanceCorrection(rejectTargetId.value, rejectReason.value.trim())
    showRejectModal.value = false
    rejectTargetId.value = null
    toast.success('Correction rejected')
    await load()
  } catch (e: unknown) {
    rejectError.value = e instanceof Error ? e.message : 'Unable to reject this request.'
  } finally {
    rejectBusy.value = false
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string') filters.to = route.query.to
  if (typeof route.query.status === 'string') statusFilter.value = route.query.status
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="attendance-corrections">
    <template #header>
      <UDashboardNavbar title="Attendance corrections">
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
          <ReportDataToolbar :total="meta.total" :rows="data" filename="attendance-corrections" :loading="loading" />
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
            empty-icon="i-lucide-file-text"
            empty-message="No attendance correction requests found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="showRejectModal" title="Reject correction request">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">Provide a reason for rejecting this attendance correction.</p>
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
