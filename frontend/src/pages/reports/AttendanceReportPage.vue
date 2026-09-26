<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { usePermission } from '../../features/auth/composables/usePermission'
import { useAppToast } from '../../composables/useAppToast'
import {
  fetchAttendanceReport,
  createEmployeeAttendance,
  updateEmployeeAttendance,
  voidEmployeeAttendance,
} from '../../services/reports/attendanceReportApi'
import { fetchEmployees, type EmployeeRow } from '../../services/employeeApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import { formatAttendanceDateTime, toAttendanceDatetimeLocal } from '../../utils/attendanceDateTime'
import type { AttendanceReportRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const toast = useAppToast()
const { can } = usePermission()

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
  { label: 'Incomplete', value: 'incomplete' },
  { label: 'Late', value: 'late' },
  { label: 'Absent', value: 'absent' },
  { label: 'On leave', value: 'on_leave' },
]

const hideableColumns = [
  { id: 'employee_name', label: 'Employee' },
  { id: 'attendance_date', label: 'Date' },
  { id: 'check_in_at', label: 'Clock In' },
  { id: 'check_out_at', label: 'Clock Out' },
  { id: 'duration_minutes', label: 'Duration' },
  { id: 'status', label: 'Status' },
  { id: 'created_at', label: 'Created At' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present: 'success',
  late: 'warning',
  absent: 'error',
  incomplete: 'error',
  on_leave: 'neutral',
}

function formatDurationMinutes(mins: number | null | undefined): string {
  if (mins == null || mins <= 0) return '—'
  const hours = Math.floor(mins / 60)
  const m = mins % 60
  return hours > 0 ? `${hours}h ${m}m` : `${m}m`
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
    accessorKey: 'check_in_at',
    header: ({ column }) => createSortableHeader(column, 'Clock In'),
    cell: ({ row }) => formatAttendanceDateTime(row.original.check_in_at),
  },
  {
    accessorKey: 'check_out_at',
    header: ({ column }) => createSortableHeader(column, 'Clock Out'),
    cell: ({ row }) => formatAttendanceDateTime(row.original.check_out_at),
  },
  {
    accessorKey: 'duration_minutes',
    header: ({ column }) => createSortableHeader(column, 'Duration'),
    cell: ({ row }) => formatDurationMinutes(row.original.duration_minutes),
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
    cell: ({ row }) => formatAttendanceDateTime(row.getValue<string>('created_at')),
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const record = row.original
      const items = [
        can('attendance.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(record),
        },
        can('attendance.void') && {
          label: 'Void',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmVoid(record),
        },
      ].filter(Boolean)

      if (!items.length) return null

      return h('div', { class: 'flex justify-end' }, [
        h(resolveComponent('UDropdownMenu'), { items: [items] }, {
          default: () => h(resolveComponent('UButton'), {
            size: 'xs',
            color: 'neutral',
            variant: 'ghost',
            icon: 'i-lucide-more-horizontal',
            'aria-label': 'Row actions',
          }),
        }),
      ])
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

// ── Create / Edit ─────────────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formBusy = ref(false)
const formError = ref('')
const editingRow = ref<AttendanceReportRow | null>(null)
const formEmployees = ref<EmployeeRow[]>([])

const form = reactive({
  employee_id: '' as string,
  attendance_date: '',
  check_in_at: '',
  check_out_at: '',
})

const ALL = '__all__'

function toSelectId(raw: string): string {
  return raw === '' ? ALL : raw
}

function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

function resetForm(): void {
  form.employee_id = ''
  form.attendance_date = ''
  form.check_in_at = ''
  form.check_out_at = ''
  formError.value = ''
  editingRow.value = null
}

function toApiDateTime(localValue: string): string {
  return localValue.trim().replace('T', ' ')
}

async function loadFormEmployees(): Promise<void> {
  try {
    const res = await fetchEmployees({ per_page: 200, sort: 'full_name', direction: 'asc' })
    formEmployees.value = res.data
  }
  catch {
    formEmployees.value = []
  }
}

async function openCreate(): Promise<void> {
  formMode.value = 'create'
  resetForm()
  await loadFormEmployees()
  const today = new Date()
  const y = today.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' })
  form.attendance_date = y
  form.check_in_at = `${y}T08:30`
  showFormModal.value = true
}

function openEdit(row: AttendanceReportRow): void {
  formMode.value = 'edit'
  resetForm()
  editingRow.value = row
  form.employee_id = String(row.employee_id)
  form.attendance_date = row.attendance_date
  form.check_in_at = toAttendanceDatetimeLocal(row.check_in_at)
  form.check_out_at = toAttendanceDatetimeLocal(row.check_out_at)
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  formError.value = ''
  if (!form.check_in_at) {
    formError.value = 'Clock in is required.'
    return
  }
  if (formMode.value === 'create' && (!form.employee_id || !form.attendance_date)) {
    formError.value = 'Employee and date are required.'
    return
  }

  formBusy.value = true
  try {
    const checkIn = toApiDateTime(form.check_in_at)
    const checkOut = form.check_out_at.trim() ? toApiDateTime(form.check_out_at) : null

    if (formMode.value === 'create') {
      await createEmployeeAttendance({
        employee_id: Number(form.employee_id),
        attendance_date: form.attendance_date,
        check_in_at: checkIn,
        check_out_at: checkOut,
      })
      toast.success('Attendance created')
    }
    else if (editingRow.value) {
      await updateEmployeeAttendance(editingRow.value.id, {
        check_in_at: checkIn,
        check_out_at: checkOut,
      })
      toast.success('Attendance updated')
    }

    showFormModal.value = false
    resetForm()
    await load()
  }
  catch (e: unknown) {
    toast.fromError(e, formMode.value === 'create' ? 'Unable to create attendance.' : 'Unable to update attendance.')
  }
  finally {
    formBusy.value = false
  }
}

// ── Void ──────────────────────────────────────────────────────────────────────
const showVoidModal = ref(false)
const voidTarget = ref<AttendanceReportRow | null>(null)
const voidBusy = ref(false)

function confirmVoid(row: AttendanceReportRow): void {
  voidTarget.value = row
  showVoidModal.value = true
}

async function executeVoid(): Promise<void> {
  if (!voidTarget.value) return
  voidBusy.value = true
  try {
    await voidEmployeeAttendance(voidTarget.value.id)
    showVoidModal.value = false
    voidTarget.value = null
    toast.success('Attendance voided')
    await load()
  }
  catch (e: unknown) {
    toast.fromError(e, 'Unable to void this attendance record.')
  }
  finally {
    voidBusy.value = false
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
            v-if="can('attendance.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add record
          </UButton>
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

  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add attendance record' : 'Edit attendance record'"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert v-if="formError" color="error" variant="subtle" :title="formError" />

        <template v-if="formMode === 'create'">
          <UFormField label="Employee" required>
            <USelect
              :model-value="toSelectId(form.employee_id)"
              :items="[
                { label: formEmployees.length ? 'Select employee' : 'No employees available', value: ALL },
                ...formEmployees.map(e => ({
                  label: `${e.full_name} (${e.employee_code})`,
                  value: String(e.id),
                })),
              ]"
              value-key="value"
              class="w-full"
              @update:model-value="form.employee_id = fromSelectId($event)"
            />
          </UFormField>
          <UFormField label="Date" required>
            <UInput v-model="form.attendance_date" type="date" class="w-full" />
          </UFormField>
        </template>

        <template v-else>
          <UFormField label="Employee">
            <UInput :model-value="editingRow?.employee_name ?? '—'" disabled class="w-full" />
          </UFormField>
          <UFormField label="Date">
            <UInput :model-value="form.attendance_date" disabled class="w-full" />
          </UFormField>
        </template>

        <UFormField label="Clock In" required hint="Asia/Jakarta">
          <UInput v-model="form.check_in_at" type="datetime-local" class="w-full" />
        </UFormField>
        <UFormField label="Clock Out" hint="Optional — leave empty for incomplete">
          <UInput v-model="form.check_out_at" type="datetime-local" class="w-full" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add record' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="showVoidModal" title="Void attendance record">
    <template #body>
      <p class="text-sm text-muted">
        Are you sure you want to void the attendance record for
        <strong class="text-highlighted">{{ voidTarget?.employee_name }}</strong>
        on <strong class="text-highlighted">{{ voidTarget?.attendance_date }}</strong>?
      </p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="voidBusy" @click="showVoidModal = false">
          Cancel
        </UButton>
        <UButton color="error" :loading="voidBusy" @click="executeVoid">
          Void record
        </UButton>
      </div>
    </template>
  </UModal>
</template>
