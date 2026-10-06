<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { useRoute } from 'vue-router'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../../composables/useReportPage'
import { useDataTableSort } from '../../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../../composables/useDataTableDisplay'
import { usePermission } from '../../../features/auth/composables/usePermission'
import { useAppToast } from '../../../composables/useAppToast'
import {
  fetchAttendanceReport,
  createEmployeeAttendance,
  updateEmployeeAttendance,
  voidEmployeeAttendance,
} from '../../../services/reports/attendanceReportApi'
import { fetchHrisEmployees, type HrisEmployeeRow } from '../../../services/employeeApi'
import { fetchHrisEmployeeWorkLocations, type EmployeeWorkLocationRow } from '../../../services/employeeWorkLocationApi'
import ReportDataToolbar from '../../../components/ReportDataToolbar.vue'
import type { ReportCsvColumn } from '../../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import DashboardNavbarTitle from '../../../components/DashboardNavbarTitle.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../../utils/dataTable'
import { formatAttendanceDateTime, toAttendanceDatetimeLocal } from '../../../utils/attendanceDateTime'
import type { AttendanceReportRow } from '../../../types/reports'
import { defaultReportDates } from '../../../types/reportDates'

const route = useRoute()
const { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage } = useReportPage()
const toast = useAppToast()
const { can } = usePermission()

const data = ref<AttendanceReportRow[]>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('all')
const searchInput = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  search: '',
  status: '',
  per_page: 25,
  sort: 'attendance_date',
  direction: 'desc' as 'asc' | 'desc',
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
  { id: 'attendance_date', label: 'Date' },
  { id: 'employee_name', label: 'Employee' },
  { id: 'work_location', label: 'Work location' },
  { id: 'check_in_at', label: 'Clock In' },
  { id: 'check_in_location', label: 'Clock In Location' },
  { id: 'check_out_at', label: 'Clock Out' },
  { id: 'check_out_location', label: 'Clock Out Location' },
  { id: 'duration_minutes', label: 'Duration' },
  { id: 'status', label: 'Status' },
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

function formatLocationCoordinates(location: AttendanceReportRow['check_in_location']): string {
  const gps = location?.gps
  if (gps?.latitude == null || gps.longitude == null) return ''
  return `${gps.latitude.toFixed(6)}, ${gps.longitude.toFixed(6)}`
}

function locationLines(location: AttendanceReportRow['check_in_location']): string[] {
  if (!location) return []
  const lines: string[] = []
  if (location.work_location?.name) lines.push(location.work_location.name)
  if (location.work_location?.city && location.work_location.city !== location.work_location.name) {
    lines.push(location.work_location.city)
  }
  const coordinates = formatLocationCoordinates(location)
  if (coordinates) lines.push(coordinates)
  return lines
}

function formatLocationPlain(location: AttendanceReportRow['check_in_location']): string {
  return locationLines(location).join(' | ')
}

function createLocationCell(location: AttendanceReportRow['check_in_location']) {
  const lines = locationLines(location)
  if (!lines.length) return h('span', { class: 'text-sm text-muted' }, '—')

  const gps = location?.gps
  const mapLink = gps?.latitude != null && gps.longitude != null
    ? h('a', {
        href: `https://maps.google.com/?q=${gps.latitude},${gps.longitude}`,
        target: '_blank',
        rel: 'noopener noreferrer',
        class: 'text-primary hover:underline cursor-pointer',
        onClick: (event: Event) => event.stopPropagation(),
      }, 'Map')
    : null

  return h('div', { class: 'min-w-0 max-w-64 space-y-0.5 text-sm', title: lines.join('\n') }, [
    ...lines.map((line) => h('div', { class: 'truncate' }, line)),
    ...(mapLink ? [mapLink] : []),
  ])
}

function asAttendanceRow(row: unknown): AttendanceReportRow {
  return row as AttendanceReportRow
}

const csvColumns: ReportCsvColumn[] = [
  { header: 'Date', value: row => asAttendanceRow(row).attendance_date ?? '' },
  { header: 'Employee ID', value: row => asAttendanceRow(row).employee_code ?? '' },
  { header: 'Employee Name', value: row => asAttendanceRow(row).employee_name ?? '' },
  { header: 'Work Location', value: row => asAttendanceRow(row).check_in_location?.work_location?.city ?? '' },
  {
    header: 'Clock In',
    value: row => formatAttendanceDateTime(asAttendanceRow(row).check_in_at, asAttendanceRow(row).attendance_date, ''),
  },
  { header: 'Clock In Location', value: row => formatLocationPlain(asAttendanceRow(row).check_in_location) },
  {
    header: 'Clock Out',
    value: row => formatAttendanceDateTime(asAttendanceRow(row).check_out_at, asAttendanceRow(row).attendance_date, ''),
  },
  { header: 'Clock Out Location', value: row => formatLocationPlain(asAttendanceRow(row).check_out_location) },
  { header: 'Duration', value: row => formatDurationMinutes(asAttendanceRow(row).duration_minutes) },
  {
    header: 'Status',
    value: (row) => {
      const status = asAttendanceRow(row).status
      return status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
    },
  },
]

const csvFilename = computed(() => `attendance_${filters.from}_${filters.to}`)

async function fetchAllRowsForExport(): Promise<AttendanceReportRow[]> {
  if (data.value.length > 0 && data.value.length >= meta.total) return [...data.value]

  try {
    const rows: AttendanceReportRow[] = []
    let page = 1
    let lastPage = 1
    const maxPages = 200
    do {
      const response = await fetchAttendanceReport({
        from: filters.from,
        to: filters.to,
        employee_id: filters.employee_id || null,
        search: filters.search || null,
        status: filters.status || null,
        per_page: 100,
        sort: filters.sort,
        direction: filters.direction,
        page,
      })
      if (!Array.isArray(response.data) || response.data.length === 0) break
      rows.push(...response.data)
      lastPage = Math.max(1, Number(response.meta?.last_page) || page)
      if (Number(response.meta?.current_page) === 1 && page > 1) break
      page += 1
    } while (page <= lastPage && page <= maxPages)

    return rows
  } catch (err) {
    toast.fromError(err, 'Unable to export attendance. Please try again.')
    return []
  }
}

const columns = computed<TableColumn<AttendanceReportRow>[]>(() => [
  {
    accessorKey: 'attendance_date',
    header: ({ column }) => createSortableHeader(column, 'Date'),
  },
  {
    id: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
    accessorFn: row => row.employee_name,
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      h('p', { class: 'truncate text-sm font-medium' }, row.original.employee_name),
      h('p', { class: 'truncate font-mono text-xs text-muted' }, `ID: ${row.original.employee_code}`),
    ]),
  },
  {
    id: 'work_location',
    header: 'Work location',
    accessorFn: row => row.check_in_location?.work_location?.city ?? row.check_in_location?.work_location?.name ?? '',
    cell: ({ row }) => {
      const location = row.original.check_in_location?.work_location
      if (!location) return createTruncatedText(null)
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'truncate text-sm font-medium' }, location.city || location.name || '—'),
        ...(location.name && location.city && location.name !== location.city
          ? [h('p', { class: 'truncate text-xs text-muted' }, location.name)]
          : []),
      ])
    },
  },
  {
    accessorKey: 'check_in_at',
    header: ({ column }) => createSortableHeader(column, 'Clock In'),
    cell: ({ row }) => formatAttendanceDateTime(row.original.check_in_at, row.original.attendance_date),
  },
  {
    id: 'check_in_location',
    header: 'Clock In Location',
    accessorFn: row => formatLocationPlain(row.check_in_location),
    cell: ({ row }) => createLocationCell(row.original.check_in_location),
  },
  {
    accessorKey: 'check_out_at',
    header: ({ column }) => createSortableHeader(column, 'Clock Out'),
    cell: ({ row }) => formatAttendanceDateTime(row.original.check_out_at, row.original.attendance_date),
  },
  {
    id: 'check_out_location',
    header: 'Clock Out Location',
    accessorFn: row => formatLocationPlain(row.check_out_location),
    cell: ({ row }) => createLocationCell(row.original.check_out_location),
  },
  {
    accessorKey: 'duration_minutes',
    header: ({ column }) => createSortableHeader(column, 'Duration'),
    cell: ({ row }) => formatDurationMinutes(row.original.duration_minutes),
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    cell: ({ row }) => {
      const status = row.getValue<string>('status')
      return createStatusBadge(status, statusColor[status] ?? 'neutral')
    },
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

watchDebounced(searchInput, (value) => {
  filters.search = value.trim()
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(statusFilter, (value) => {
  filters.status = value === 'all' ? '' : value
  if (!ready.value) return
  meta.current_page = 1
  load()
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
  clearErrors()
  try {
    const res = await fetchAttendanceReport({
      from: filters.from,
      to: filters.to,
      employee_id: filters.employee_id || null,
      search: filters.search || null,
      status: filters.status || null,
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

function resetFilters(): void {
  const defaults = defaultReportDates()
  filters.from = defaults.from
  filters.to = defaults.to
  filters.employee_id = ''
  filters.search = ''
  filters.status = ''
  filters.per_page = 25
  filters.sort = 'attendance_date'
  filters.direction = 'desc'
  searchInput.value = ''
  statusFilter.value = 'all'
  meta.current_page = 1
  void load()
}

// ── Create / Edit ─────────────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formBusy = ref(false)
const formError = ref('')
const editingRow = ref<AttendanceReportRow | null>(null)
const formEmployees = ref<HrisEmployeeRow[]>([])
const selectedFormEmployee = ref<HrisEmployeeRow | null>(null)
const formEmployeeSearch = ref('')
const formEmployeesLoading = ref(false)
const formLocations = ref<EmployeeWorkLocationRow[]>([])
const formLocationsLoading = ref(false)
let formEmployeeRequest = 0
let formLocationRequest = 0

const form = reactive({
  hris_employee_id: '' as string,
  work_location_id: '' as string,
  attendance_date: '',
  check_in_at: '',
  check_out_at: '',
})

watchDebounced(formEmployeeSearch, () => {
  if (showFormModal.value && formMode.value === 'create') void loadFormEmployees()
}, { debounce: 300 })

const formEmployee = computed(() =>
  formEmployees.value.find(employee => employee.employee_id === form.hris_employee_id)
  ?? selectedFormEmployee.value,
)

const formEmployeeOptions = computed(() => {
  const options = formEmployees.value.map(employee => ({
    label: `${employee.full_name || 'Unnamed employee'} (ID: ${employee.employee_id})`,
    value: employee.employee_id,
  }))

  if (
    selectedFormEmployee.value
    && !options.some(option => option.value === selectedFormEmployee.value?.employee_id)
  ) {
    options.unshift({
      label: `${selectedFormEmployee.value.full_name || 'Unnamed employee'} (ID: ${selectedFormEmployee.value.employee_id})`,
      value: selectedFormEmployee.value.employee_id,
    })
  }

  return options
})

const formLocationOptions = computed(() => formLocations.value.map(location => ({
  label: `${location.city} · ${location.name} (${location.area_type_label})`,
  value: String(location.id),
})))

const ALL = '__all__'

function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

function resetForm(): void {
  formEmployeeRequest++
  formLocationRequest++
  formEmployeesLoading.value = false
  formLocationsLoading.value = false
  form.hris_employee_id = ''
  form.work_location_id = ''
  form.attendance_date = ''
  form.check_in_at = ''
  form.check_out_at = ''
  formError.value = ''
  editingRow.value = null
  selectedFormEmployee.value = null
  formEmployeeSearch.value = ''
  formEmployees.value = []
  formLocations.value = []
}

function toApiDateTime(localValue: string): string {
  return localValue.trim().replace('T', ' ')
}

async function loadFormEmployees(): Promise<void> {
  const requestId = ++formEmployeeRequest
  formEmployeesLoading.value = true
  formError.value = ''
  try {
    const res = await fetchHrisEmployees({
      search: formEmployeeSearch.value.trim() || undefined,
      per_page: 25,
    })
    if (requestId === formEmployeeRequest) formEmployees.value = res.data
  }
  catch (error) {
    if (requestId === formEmployeeRequest) {
      formEmployees.value = []
      formError.value = error instanceof Error ? error.message : 'Unable to load HRIS employees.'
    }
  }
  finally {
    if (requestId === formEmployeeRequest) formEmployeesLoading.value = false
  }
}

async function selectFormEmployee(raw: unknown): Promise<void> {
  const requestId = ++formLocationRequest
  const employeeId = String(raw ?? '')
  form.hris_employee_id = employeeId
  form.work_location_id = ''
  formLocations.value = []
  formError.value = ''

  const employee = formEmployees.value.find(item => item.employee_id === employeeId)
    ?? (selectedFormEmployee.value?.employee_id === employeeId ? selectedFormEmployee.value : null)
  selectedFormEmployee.value = employee

  if (!employee || !employee.nik) {
    formLocationsLoading.value = false
    formError.value = 'The selected HRIS person has no NIK, so assigned work locations cannot be loaded.'
    return
  }

  formLocationsLoading.value = true
  try {
    const response = await fetchHrisEmployeeWorkLocations(employee.nik)
    if (requestId !== formLocationRequest) return
    formLocations.value = response.data
    if (response.data.length === 0) {
      formError.value = 'This person has no active attendance work location assigned.'
    }
  }
  catch (error) {
    if (requestId === formLocationRequest) {
      formError.value = error instanceof Error ? error.message : 'Unable to load this person assigned work locations.'
    }
  }
  finally {
    if (requestId === formLocationRequest) formLocationsLoading.value = false
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
  if (formMode.value === 'create' && (!form.hris_employee_id || !form.work_location_id || !form.attendance_date)) {
    formError.value = 'Employee, assigned work location, and date are required.'
    return
  }
  if (formMode.value === 'create' && !formEmployee.value?.nik) {
    formError.value = 'Select an HRIS employee with a valid NIK before creating the record.'
    return
  }

  formBusy.value = true
  try {
    const checkIn = toApiDateTime(form.check_in_at)
    const checkOut = form.check_out_at.trim() ? toApiDateTime(form.check_out_at) : null

    if (formMode.value === 'create') {
      const employee = formEmployee.value
      if (!employee?.nik) {
        formError.value = 'Select an HRIS employee with a valid NIK before creating the record.'
        return
      }

      await createEmployeeAttendance({
        hris_employee_id: form.hris_employee_id,
        nik: employee.nik,
        work_location_id: Number(form.work_location_id),
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
  if (filters.from && filters.to && filters.from > filters.to) {
    [filters.from, filters.to] = [filters.to, filters.from]
  }
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="attendance-report">
    <template #header>
      <UDashboardNavbar>
        <template #title>
          <DashboardNavbarTitle />
        </template>
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
          <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-filter-x" @click="resetFilters">
            Reset
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
          <UAlert
            v-if="filterError"
            color="warning"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Filter tidak valid"
            :description="filterError"
          />

          <ReportDataToolbar
            :total="meta.total"
            :rows="data"
            :columns="csvColumns"
            :filename="csvFilename"
            :loading="loading"
            :fetch-rows="fetchAllRowsForExport"
          />

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusFilter"
            v-model:from="filters.from"
            v-model:to="filters.to"
            v-model:employee-id="filters.employee_id"
            v-model:per-page="filters.per_page"
            search-placeholder="Filter employee name or ID..."
            :status-options="statusOptions"
            :display-items="displayItems"
            show-date-range
            show-employee-id
            show-per-page
          />

          <DataTable
            v-model:sorting="sorting"
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
            <USelectMenu
              :model-value="form.hris_employee_id || undefined"
              v-model:search-term="formEmployeeSearch"
              :items="formEmployeeOptions"
              value-key="value"
              ignore-filter
              :loading="formEmployeesLoading"
              placeholder="Search HRIS employees…"
              class="w-full"
              @update:model-value="selectFormEmployee($event)"
            />
          </UFormField>
          <UFormField label="Assigned work location" required>
            <USelect
              v-model="form.work_location_id"
              :items="[
                {
                  label: formLocationsLoading
                    ? 'Loading assigned locations…'
                    : formEmployee?.nik
                      ? formLocations.length ? 'Select assigned location' : 'No active locations assigned'
                      : 'Select an employee first',
                  value: ALL,
                },
                ...formLocationOptions,
              ]"
              value-key="value"
              :disabled="!formEmployee?.nik || formLocationsLoading || formLocationOptions.length === 0"
              class="w-full"
              @update:model-value="form.work_location_id = fromSelectId($event)"
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
