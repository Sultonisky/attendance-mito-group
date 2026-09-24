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
  fetchOutsourceAttendanceReport,
  createOutsourceAttendance,
  updateOutsourceAttendance,
  voidOutsourceAttendance,
} from '../../../services/reports/outsourceAttendanceReportApi'
import { fetchOutsourceCities, fetchOutsourceStores, fetchOutsourceOutsources } from '../../../services/outsourceService'
import { fetchWorkLocationPins, type WorkLocationPinRow } from '../../../services/outsourceWorkLocationApi'
import { fetchOutsourcePerson } from '../../../services/outsourcePersonApi'
import ReportDataToolbar from '../../../components/ReportDataToolbar.vue'
import type { ReportCsvColumn } from '../../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../../utils/dataTable'
import { formatAttendanceDateTime, toAttendanceDatetimeLocal } from '../../../utils/attendanceDateTime'
import type { OutsourceAttendanceReportRow, OutsourceAttendanceReportFilters } from '../../../types/reports'
import { defaultReportDates } from '../../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const toast = useAppToast()
const { can } = usePermission()

const data = ref<OutsourceAttendanceReportRow[]>([])
const cities = ref<{ id: number; name: string }[]>([])
const stores = ref<{ id: number; name: string; city_id: number }[]>([])
const outsources = ref<{ id: number; name: string; outsource_code: string }[]>([])
const columnVisibility = ref<VisibilityState>()
const statusTab = ref('all')
const searchInput = ref('')

const filters = reactive<OutsourceAttendanceReportFilters>({
  ...defaultReportDates(),
  city_id: '',
  store_id: '',
  outsource_id: '',
  status: '',
  search: '',
  per_page: 25,
  sort: 'attendance_date',
  direction: 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Present', value: 'present' },
  { label: 'Incomplete', value: 'incomplete' },
]

const hideableColumns = [
  { id: 'attendance_date', label: 'Date' },
  { id: 'outsource', label: 'Outsource' },
  { id: 'city', label: 'City' },
  { id: 'address', label: 'Address' },
  { id: 'coordinates', label: 'Coordinates' },
  { id: 'check_in_at', label: 'Clock In' },
  { id: 'check_out_at', label: 'Clock Out' },
  { id: 'duration_minutes', label: 'Duration' },
  { id: 'status', label: 'Status' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present: 'success',
  incomplete: 'error',
}

const statusLabel: Record<string, string> = {
  present: 'Present',
  incomplete: 'Incomplete',
}

function formatDurationMinutes(mins: number | null | undefined): string {
  if (mins == null || mins <= 0) return ''
  const hours = Math.floor(mins / 60)
  const m = mins % 60
  return hours > 0 ? `${hours}h ${m}m` : `${m}m`
}

function formatCoordinate(value: number | null | undefined): string {
  if (value == null || Number.isNaN(value)) return ''
  return value.toFixed(6)
}

function formatPinCoordinates(pin: OutsourceAttendanceReportRow['pin']): string {
  if (!pin || pin.latitude == null || pin.longitude == null) return ''
  return `${formatCoordinate(pin.latitude)}, ${formatCoordinate(pin.longitude)}`
}

function asOutsourceRow(row: unknown): OutsourceAttendanceReportRow {
  return row as OutsourceAttendanceReportRow
}

/** Human-readable CSV columns (flat; Excel-friendly; overnight clock shows date). */
const csvColumns: ReportCsvColumn[] = [
  { header: 'Date', value: row => asOutsourceRow(row).attendance_date ?? '' },
  { header: 'Outsource Name', value: row => asOutsourceRow(row).outsource?.name ?? '' },
  { header: 'Outsource Code', value: row => asOutsourceRow(row).outsource?.code ?? '' },
  { header: 'City', value: row => asOutsourceRow(row).city?.name ?? '' },
  { header: 'Address', value: row => asOutsourceRow(row).pin?.address ?? '' },
  { header: 'Latitude', value: row => formatCoordinate(asOutsourceRow(row).pin?.latitude) },
  { header: 'Longitude', value: row => formatCoordinate(asOutsourceRow(row).pin?.longitude) },
  {
    header: 'Clock In',
    value: (row) => {
      const r = asOutsourceRow(row)
      return formatAttendanceDateTime(r.check_in_at, r.attendance_date, '')
    },
  },
  {
    header: 'Clock Out',
    value: (row) => {
      const r = asOutsourceRow(row)
      return formatAttendanceDateTime(r.check_out_at, r.attendance_date, '')
    },
  },
  {
    header: 'Duration',
    value: row => formatDurationMinutes(asOutsourceRow(row).duration_minutes),
  },
  {
    header: 'Status',
    value: (row) => {
      const s = asOutsourceRow(row).status
      return statusLabel[s] ?? (s ? s.charAt(0).toUpperCase() + s.slice(1) : '')
    },
  },
]

const csvFilename = computed(() =>
  `outsource-attendance_${filters.from}_${filters.to}`,
)

async function fetchAllRowsForExport(): Promise<OutsourceAttendanceReportRow[]> {
  try {
    const all: OutsourceAttendanceReportRow[] = []
    let page = 1
    let lastPage = 1

    do {
      const res = await fetchOutsourceAttendanceReport({
        from: filters.from,
        to: filters.to,
        city_id: filters.city_id || null,
        store_id: filters.store_id || null,
        outsource_id: filters.outsource_id || null,
        status: filters.status || null,
        search: filters.search || null,
        per_page: 100,
        sort: filters.sort,
        direction: filters.direction,
        page,
      })
      all.push(...res.data)
      lastPage = res.meta.last_page
      page += 1
    } while (page <= lastPage)

    return all
  }
  catch (err) {
    toast.fromError(err, 'Unable to export outsource attendance. Please try again.')
    return []
  }
}

const columns = computed<TableColumn<OutsourceAttendanceReportRow>[]>(() => [
  {
    accessorKey: 'attendance_date',
    header: ({ column }) => createSortableHeader(column, 'Date'),
  },
  {
    id: 'outsource',
    header: ({ column }) => createSortableHeader(column, 'Outsource'),
    accessorFn: row => row.outsource?.name ?? '',
    cell: ({ row }) => {
      const o = row.original.outsource
      const label = o ? `${o.name}${o.code ? ` (${o.code})` : ''}` : null
      return createTruncatedText(label)
    },
  },
  {
    id: 'city',
    header: ({ column }) => createSortableHeader(column, 'City'),
    accessorFn: row => row.city?.name ?? '',
    cell: ({ row }) => createTruncatedText(row.original.city?.name),
  },
  {
    id: 'address',
    header: 'Address',
    accessorFn: row => row.pin?.address ?? row.pin?.name ?? '',
    cell: ({ row }) => {
      const pin = row.original.pin
      const label = pin?.address || pin?.name || null
      return createTruncatedText(label)
    },
  },
  {
    id: 'coordinates',
    header: 'Coordinates',
    accessorFn: row => formatPinCoordinates(row.pin),
    cell: ({ row }) => createTruncatedText(formatPinCoordinates(row.original.pin) || null),
  },
  {
    accessorKey: 'check_in_at',
    header: ({ column }) => createSortableHeader(column, 'Clock In'),
    cell: ({ row }) => formatAttendanceDateTime(row.getValue<string | null>('check_in_at'), row.original.attendance_date),
  },
  {
    accessorKey: 'check_out_at',
    header: ({ column }) => createSortableHeader(column, 'Clock Out'),
    cell: ({ row }) => formatAttendanceDateTime(row.getValue<string | null>('check_out_at'), row.original.attendance_date),
  },
  {
    accessorKey: 'duration_minutes',
    header: ({ column }) => createSortableHeader(column, 'Duration'),
    cell: ({ row }) => {
      const mins = row.getValue<number | null>('duration_minutes')
      return formatDurationMinutes(mins) || '—'
    },
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return createStatusBadge(s, statusColor[s] ?? 'neutral')
    },
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const record = row.original
      const items = [
        can('outsource_attendance.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(record),
        },
        can('outsource_attendance.void') && {
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

const ALL = '__all__'

function toSelectId(raw: string): string {
  return raw === '' ? ALL : raw
}

function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

async function loadCities() {
  try {
    cities.value = await fetchOutsourceCities()
  }
  catch { /* ignore */ }
}

async function onCityChange() {
  stores.value = []
  outsources.value = []
  filters.store_id = ''
  filters.outsource_id = ''
  if (!filters.city_id) return
  try {
    stores.value = await fetchOutsourceStores(Number(filters.city_id))
  }
  catch {
    stores.value = []
  }
}

async function onStoreChange() {
  outsources.value = []
  filters.outsource_id = ''
  if (!filters.store_id) return
  try {
    outsources.value = await fetchOutsourceOutsources(Number(filters.store_id))
  }
  catch {
    outsources.value = []
  }
}

watch(statusTab, (value) => {
  filters.status = value === 'all' ? '' : value
  if (!ready.value) return
  meta.current_page = 1
  load()
})

watchDebounced(searchInput, (value) => {
  filters.search = value
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(
  () => [filters.from, filters.to, filters.city_id, filters.store_id, filters.outsource_id, filters.per_page] as const,
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
    const res = await fetchOutsourceAttendanceReport({
      from: filters.from,
      to: filters.to,
      city_id: filters.city_id || null,
      store_id: filters.store_id || null,
      outsource_id: filters.outsource_id || null,
      status: filters.status || null,
      search: filters.search || null,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  }
  catch (err) {
    await handleApiError(err, 'Unable to load outsource attendance report. Please try again.')
  }
  finally {
    loading.value = false
  }
}

function resetFilters() {
  const def = defaultReportDates()
  filters.from = def.from
  filters.to = def.to
  filters.city_id = ''
  filters.store_id = ''
  filters.outsource_id = ''
  filters.status = ''
  filters.search = ''
  filters.per_page = 25
  filters.sort = 'attendance_date'
  filters.direction = 'desc'
  statusTab.value = 'all'
  searchInput.value = ''
  stores.value = []
  outsources.value = []
  meta.current_page = 1
  load()
}

// ── Create / Edit ─────────────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formBusy = ref(false)
const formError = ref('')
const editingRow = ref<OutsourceAttendanceReportRow | null>(null)

const formCities = ref<{ id: number; name: string }[]>([])
const formStores = ref<{ id: number; name: string; city_id: number }[]>([])
const formOutsources = ref<{ id: number; name: string; outsource_code: string }[]>([])
const formPins = ref<WorkLocationPinRow[]>([])

const form = reactive({
  city_id: '' as string,
  store_id: '' as string,
  outsource_id: '' as string,
  pin_id: '' as string,
  attendance_date: '',
  check_in_at: '',
  check_out_at: '',
})

function resetForm(): void {
  form.city_id = ''
  form.store_id = ''
  form.outsource_id = ''
  form.pin_id = ''
  form.attendance_date = ''
  form.check_in_at = ''
  form.check_out_at = ''
  formStores.value = []
  formOutsources.value = []
  formPins.value = []
  formError.value = ''
  editingRow.value = null
}

function toApiDateTime(localValue: string): string {
  return localValue.trim().replace('T', ' ')
}

async function loadFormPins(storeId: string): Promise<void> {
  formPins.value = []
  if (!storeId) return
  try {
    formPins.value = await fetchWorkLocationPins(Number(storeId))
  }
  catch {
    formPins.value = []
  }
}

async function onFormCityChange(): Promise<void> {
  form.store_id = ''
  form.outsource_id = ''
  form.pin_id = ''
  formStores.value = []
  formOutsources.value = []
  formPins.value = []
  if (!form.city_id) return
  try {
    formStores.value = await fetchOutsourceStores(Number(form.city_id))
  }
  catch {
    formStores.value = []
  }
}

async function onFormStoreChange(): Promise<void> {
  form.outsource_id = ''
  form.pin_id = ''
  formOutsources.value = []
  formPins.value = []
  if (!form.store_id) return
  try {
    formOutsources.value = await fetchOutsourceOutsources(Number(form.store_id))
  }
  catch {
    formOutsources.value = []
  }
  await loadFormPins(form.store_id)
}

function openCreate(): void {
  formMode.value = 'create'
  resetForm()
  formCities.value = cities.value
  const today = new Date()
  const y = today.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' })
  form.attendance_date = y
  form.check_in_at = `${y}T08:30`
  showFormModal.value = true
}

async function openEdit(row: OutsourceAttendanceReportRow): Promise<void> {
  formMode.value = 'edit'
  resetForm()
  editingRow.value = row
  formCities.value = cities.value

  form.attendance_date = row.attendance_date
  form.check_in_at = toAttendanceDatetimeLocal(row.check_in_at)
  form.check_out_at = toAttendanceDatetimeLocal(row.check_out_at)
  form.outsource_id = row.outsource ? String(row.outsource.id) : ''
  form.pin_id = row.pin?.id != null ? String(row.pin.id) : ''

  let storeId = row.store?.id ?? null
  if (!storeId && row.outsource?.id) {
    try {
      const person = await fetchOutsourcePerson(row.outsource.id)
      storeId = person.data.store?.id ?? null
      if (person.data.city?.id) {
        form.city_id = String(person.data.city.id)
        formStores.value = await fetchOutsourceStores(person.data.city.id)
      }
    }
    catch { /* ignore */ }
  }
  else if (row.city?.name) {
    const match = cities.value.find(c => c.name === row.city?.name)
    if (match) {
      form.city_id = String(match.id)
      try {
        formStores.value = await fetchOutsourceStores(match.id)
      }
      catch {
        formStores.value = []
      }
    }
  }

  if (storeId) {
    form.store_id = String(storeId)
    await loadFormPins(form.store_id)
  }

  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  formError.value = ''
  if (!form.pin_id) {
    formError.value = 'Pin is required.'
    return
  }
  if (!form.check_in_at) {
    formError.value = 'Clock in is required.'
    return
  }
  if (formMode.value === 'create' && (!form.outsource_id || !form.attendance_date)) {
    formError.value = 'Outsource and date are required.'
    return
  }

  formBusy.value = true
  try {
    const checkIn = toApiDateTime(form.check_in_at)
    const checkOut = form.check_out_at.trim() ? toApiDateTime(form.check_out_at) : null

    if (formMode.value === 'create') {
      await createOutsourceAttendance({
        outsource_id: Number(form.outsource_id),
        attendance_date: form.attendance_date,
        pin_id: Number(form.pin_id),
        check_in_at: checkIn,
        check_out_at: checkOut,
      })
      toast.success('Attendance created')
    }
    else if (editingRow.value) {
      await updateOutsourceAttendance(editingRow.value.attendance_id, {
        pin_id: Number(form.pin_id),
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
const showVoidModal  = ref(false)
const voidTarget     = ref<OutsourceAttendanceReportRow | null>(null)
const voidBusy       = ref(false)

function confirmVoid(row: OutsourceAttendanceReportRow): void {
  voidTarget.value  = row
  showVoidModal.value = true
}

async function executeVoid(): Promise<void> {
  if (!voidTarget.value) return
  voidBusy.value = true
  try {
    await voidOutsourceAttendance(voidTarget.value.attendance_id)
    showVoidModal.value = false
    voidTarget.value = null
    toast.success('Attendance voided')
    await load()
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to void this attendance record.')
  } finally {
    voidBusy.value = false
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string') filters.to = route.query.to
  await loadCities()
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="outsource-attendance-report">
    <template #header>
      <UDashboardNavbar title="Outsource attendance">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton
            v-if="can('outsource_attendance.create')"
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
            v-model:status="statusTab"
            v-model:from="filters.from"
            v-model:to="filters.to"
            v-model:per-page="filters.per_page"
            search-placeholder="Filter name or code..."
            :status-options="statusOptions"
            :display-items="displayItems"
            show-date-range
            show-per-page
          >
            <template #filters>
              <USelect
                :model-value="toSelectId(filters.city_id)"
                :items="[{ label: 'All cities', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value"
                class="w-36"
                @update:model-value="(v: unknown) => { filters.city_id = fromSelectId(v); onCityChange() }"
              />
              <USelect
                :model-value="toSelectId(filters.store_id)"
                :items="[{ label: 'All cabangs', value: ALL }, ...stores.map(s => ({ label: s.name, value: String(s.id) }))]"
                value-key="value"
                class="w-36"
                :disabled="!filters.city_id"
                @update:model-value="(v: unknown) => { filters.store_id = fromSelectId(v); onStoreChange() }"
              />
              <USelect
                :model-value="toSelectId(filters.outsource_id)"
                :items="[{ label: 'All outsources', value: ALL }, ...outsources.map(o => ({ label: `${o.name} (${o.outsource_code})`, value: String(o.id) }))]"
                value-key="value"
                class="w-48"
                :disabled="!filters.store_id"
                @update:model-value="filters.outsource_id = fromSelectId($event)"
              />
            </template>
          </DataTableToolbar>

          <DataTable
            v-model:sorting="sorting"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-briefcase-business"
            empty-message="No outsource attendance records found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Create / Edit modal ─────────────────────────────────────────────────── -->
  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add attendance record' : 'Edit attendance record'"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert v-if="formError" color="error" variant="subtle" :title="formError" />

        <template v-if="formMode === 'create'">
          <UFormField label="City" required>
            <USelect
              :model-value="toSelectId(form.city_id)"
              :items="[{ label: 'Select city', value: ALL }, ...formCities.map(c => ({ label: c.name, value: String(c.id) }))]"
              value-key="value"
              class="w-full"
              @update:model-value="(v: unknown) => { form.city_id = fromSelectId(v); onFormCityChange() }"
            />
          </UFormField>
          <UFormField label="Cabang" required>
            <USelect
              :model-value="toSelectId(form.store_id)"
              :items="[{ label: 'Select cabang', value: ALL }, ...formStores.map(s => ({ label: s.name, value: String(s.id) }))]"
              value-key="value"
              class="w-full"
              :disabled="!form.city_id"
              @update:model-value="(v: unknown) => { form.store_id = fromSelectId(v); onFormStoreChange() }"
            />
          </UFormField>
          <UFormField label="Outsource" required>
            <USelect
              :model-value="toSelectId(form.outsource_id)"
              :items="[{ label: 'Select outsource', value: ALL }, ...formOutsources.map(o => ({ label: `${o.name} (${o.outsource_code})`, value: String(o.id) }))]"
              value-key="value"
              class="w-full"
              :disabled="!form.store_id"
              @update:model-value="form.outsource_id = fromSelectId($event)"
            />
          </UFormField>
          <UFormField label="Date" required>
            <UInput v-model="form.attendance_date" type="date" class="w-full" />
          </UFormField>
        </template>

        <template v-else>
          <UFormField label="Outsource">
            <UInput
              :model-value="editingRow?.outsource ? `${editingRow.outsource.name} (${editingRow.outsource.code})` : '—'"
              disabled
              class="w-full"
            />
          </UFormField>
          <UFormField label="Date">
            <UInput :model-value="form.attendance_date" disabled class="w-full" />
          </UFormField>
        </template>

        <UFormField label="Pin" required hint="Address and coordinates come from this pin">
          <USelect
            :model-value="toSelectId(form.pin_id)"
            :items="[
              { label: formPins.length ? 'Select pin' : 'No pins available', value: ALL },
              ...formPins.map(p => ({
                label: p.address ? `${p.name} — ${p.address}` : p.name,
                value: String(p.id),
              })),
            ]"
            value-key="value"
            class="w-full"
            :disabled="!form.store_id && formMode === 'create'"
            @update:model-value="form.pin_id = fromSelectId($event)"
          />
        </UFormField>

        <UFormField label="Clock In" required hint="Asia/Jakarta">
          <UInput v-model="form.check_in_at" type="datetime-local" class="w-full" />
        </UFormField>

        <UFormField label="Clock Out" hint="Optional. Leave empty for incomplete. Overnight: next-day time is allowed.">
          <UInput v-model="form.check_out_at" type="datetime-local" class="w-full" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">Cancel</UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add record' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Void confirm modal ────────────────────────────────────────────────── -->
  <UModal v-model:open="showVoidModal" title="Void attendance record">
    <template #body>
      <p class="text-sm text-muted">
        Are you sure you want to void the attendance record for
        <strong class="text-highlighted">{{ voidTarget?.outsource?.name }}</strong>
        on <strong class="text-highlighted">{{ voidTarget?.attendance_date }}</strong>?
        This will permanently delete the record and cannot be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="voidBusy" @click="showVoidModal = false">Cancel</UButton>
        <UButton color="error" :loading="voidBusy" @click="executeVoid">Void record</UButton>
      </div>
    </template>
  </UModal>
</template>
