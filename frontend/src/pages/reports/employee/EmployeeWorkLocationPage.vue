<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../../composables/useReportPage'
import { useDataTableSort } from '../../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../../composables/useDataTableDisplay'
import { usePermission } from '../../../features/auth/composables/usePermission'
import { useAppToast } from '../../../composables/useAppToast'
import {
  fetchEmployeeWorkLocations,
  fetchEmployeeWorkLocationCities,
  fetchEmployeeWorkLocationEmployees,
  createEmployeeWorkLocation,
  updateEmployeeWorkLocation,
  deleteEmployeeWorkLocation,
  type AssignedEmployeeRow,
  type EmployeeWorkLocationRow,
  type WorkAreaType,
} from '../../../services/employeeWorkLocationApi'
import {
  createHrisWorkLocationOption,
  fetchHrisWorkLocationOptions,
  type HrisWorkLocationOptions,
} from '../../../services/employeeApi'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import DashboardNavbarTitle from '../../../components/DashboardNavbarTitle.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../../utils/dataTable'

const { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

const data = ref<EmployeeWorkLocationRow[]>([])
const hrisOptions = ref<HrisWorkLocationOptions>({ work_locations: [], work_areas: [] })
const hrisOptionsLoading = ref(false)
const hrisOptionsError = ref('')
const showOptionModal = ref(false)
const optionType = ref<'work_location' | 'work_area'>('work_location')
const optionName = ref('')
const optionBusy = ref(false)
const optionError = ref('')
const cities = ref<string[]>([])
const columnVisibility = ref<VisibilityState>()
const searchInput = ref('')
const statusTab = ref('all')
const showEmployeesModal = ref(false)
const employeesTarget = ref<EmployeeWorkLocationRow | null>(null)
const assignedEmployees = ref<AssignedEmployeeRow[]>([])
const employeesLoading = ref(false)
const employeesError = ref('')

const filters = reactive({
  search: '',
  city: '',
  area_type: '',
  status: 'all',
  per_page: 25,
  sort: 'city',
  direction: 'asc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
]

const hrisLocationOptions = computed(() => [...new Set(hrisOptions.value.work_locations)].sort((a, b) => a.localeCompare(b)))
const hrisAreaOptions = computed(() => [...new Set(hrisOptions.value.work_areas)].sort((a, b) => a.localeCompare(b)))
const cityFilterOptions = computed(() => [...new Set([...cities.value, ...hrisLocationOptions.value])].sort((a, b) => a.localeCompare(b)))
const areaFilterOptions = computed(() => [...new Set([
  ...hrisAreaOptions.value,
  ...data.value.map((location) => location.area_type),
])].sort((a, b) => a.localeCompare(b)))

async function loadHrisOptions(): Promise<void> {
  hrisOptionsLoading.value = true
  hrisOptionsError.value = ''
  try {
    hrisOptions.value = await fetchHrisWorkLocationOptions()
  } catch (err) {
    hrisOptionsError.value = err instanceof Error ? err.message : 'Unable to load work location options from HRIS.'
  } finally {
    hrisOptionsLoading.value = false
  }
}

const hideableColumns = [
  { id: 'city', label: 'City / location' },
  { id: 'area_type', label: 'Work area' },
  { id: 'address', label: 'Address' },
  { id: 'employee_count', label: 'Employees' },
  { id: 'status', label: 'Status' },
  { id: 'pins', label: 'Pins' },
  { id: 'actions', label: 'Actions' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const ALL = '__all__'
const SELECT_HRIS_OPTION = '__select_hris_option__'

function toSelectId(raw: string): string {
  return raw === '' ? ALL : raw
}

function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

function parseDecimal(raw: string): number | null {
  const trimmed = raw.trim()
  if (!trimmed) return null
  const n = Number(trimmed.replace(',', '.'))
  return Number.isFinite(n) ? n : null
}

function formatRadius(meters: number | null | undefined): string {
  if (meters == null || !Number.isFinite(meters)) return '150 m'
  if (meters >= 1000) {
    const km = meters / 1000
    return Number.isInteger(km) ? `${km} km` : `${km.toFixed(1)} km`
  }
  return `${Math.round(meters)} m`
}

const columns = computed<TableColumn<EmployeeWorkLocationRow>[]>(() => [
  {
    accessorKey: 'city',
    header: ({ column }) => createSortableHeader(column, 'City / location'),
    cell: ({ row }) => createTruncatedText(row.original.city, 'text-sm font-medium'),
  },
  {
    accessorKey: 'area_type',
    header: ({ column }) => createSortableHeader(column, 'Work area'),
    cell: ({ row }) => createTruncatedText(row.original.area_type_label, 'text-sm'),
  },
  {
    id: 'address',
    header: ({ column }) => createSortableHeader(column, 'Address'),
    accessorFn: (row) => row.address ?? '',
    cell: ({ row }) => createTruncatedText(row.original.address, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    id: 'employee_count',
    header: 'Employees',
    accessorFn: (row) => row.employee_count ?? 0,
    cell: ({ row }) => {
      const location = row.original
      const count = location.employee_count ?? 0
      return h(resolveComponent('UButton'), {
        size: 'xs',
        color: count > 0 ? 'primary' : 'neutral',
        variant: count > 0 ? 'soft' : 'ghost',
        icon: 'i-lucide-users',
        label: String(count),
        disabled: count === 0,
        title: 'View employees assigned to this work location',
        onClick: (event: Event) => {
          event.stopPropagation()
          if (count > 0) void openEmployees(location)
        },
      })
    },
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    cell: ({ row }) => createStatusBadge(row.original.status, row.original.status === 'active' ? 'success' : 'neutral'),
  },
  {
    id: 'pins',
    header: ({ column }) => createSortableHeader(column, 'Pins'),
    accessorFn: (row) => row.name,
    cell: ({ row }) => {
      const pin = row.original
      const hasCoordinates = pin.latitude != null && pin.longitude != null
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'truncate text-sm font-medium', title: pin.name }, pin.name || '—'),
        h('p', { class: 'truncate text-xs text-muted' }, [
          formatRadius(pin.radius_meters),
          hasCoordinates
            ? h('a', {
                href: `https://maps.google.com/?q=${pin.latitude},${pin.longitude}`,
                target: '_blank',
                rel: 'noopener noreferrer',
                class: 'ml-1 text-primary hover:underline cursor-pointer',
                onClick: (event: Event) => event.stopPropagation(),
              }, '· map')
            : null,
        ]),
      ])
    },
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const items = [
        can('employee_work_location.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(row.original),
        },
        can('employee_work_location.update') && {
          label: row.original.status === 'active' ? 'Deactivate' : 'Activate',
          icon: row.original.status === 'active' ? 'i-lucide-eye-off' : 'i-lucide-eye',
          onSelect: () => handleToggle(row.original),
        },
        can('employee_work_location.delete') && {
          label: 'Delete',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmDelete(row.original),
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

const ready = ref(false)

watchDebounced(searchInput, (value) => {
  filters.search = value
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(statusTab, (value) => {
  filters.status = value
})

watch(
  () => [filters.city, filters.area_type, filters.status, filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    load()
  },
)

async function load(): Promise<void> {
  loading.value = true
  clearErrors()
  try {
    const res = await fetchEmployeeWorkLocations({
      ...filters,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  }
  catch (err) {
    await handleApiError(err, 'Unable to load work locations. Please try again.')
  }
  finally {
    loading.value = false
  }
}

async function handleToggle(row: EmployeeWorkLocationRow): Promise<void> {
  const next = row.status === 'active' ? 'inactive' : 'active'
  try {
    await updateEmployeeWorkLocation(row.id, { status: next })
    const index = data.value.findIndex((location) => location.id === row.id)
    if (index !== -1) data.value[index] = { ...data.value[index], status: next }
    toast.success(next === 'active' ? 'Work location activated' : 'Work location deactivated')
  } catch (err) {
    toast.fromError(err, 'Unable to update work location status.')
    await load()
  }
}

function refreshPage(): void {
  void Promise.all([load(), loadCities(), loadHrisOptions()])
}

async function loadCities(): Promise<void> {
  try {
    cities.value = await fetchEmployeeWorkLocationCities()
  }
  catch {
    cities.value = []
  }
}

function resetFilters(): void {
  searchInput.value = ''
  statusTab.value = 'all'
  filters.city = ''
  filters.area_type = ''
  meta.current_page = 1
  load()
}

// ── Create / Edit ─────────────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formBusy = ref(false)
const formError = ref('')
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  city: SELECT_HRIS_OPTION,
  area_type: SELECT_HRIS_OPTION as WorkAreaType,
  address: '',
  latitude: '',
  longitude: '',
  radius_meters: '150',
  status: 'active' as 'active' | 'inactive',
})

const formCityOptions = computed(() => {
  const options = hrisLocationOptions.value.map((value) => ({ label: value, value }))
  if (form.city !== SELECT_HRIS_OPTION && !options.some((option) => option.value === form.city)) {
    options.push({ label: form.city, value: form.city })
  }
  return [{ label: 'Select a work location', value: SELECT_HRIS_OPTION }, ...options]
})
const formAreaOptions = computed(() => {
  const options = hrisAreaOptions.value.map((value) => ({ label: value, value }))
  if (form.area_type !== SELECT_HRIS_OPTION && !options.some((option) => option.value === form.area_type)) {
    options.push({ label: form.area_type, value: form.area_type })
  }
  return [{ label: 'Select a work area', value: SELECT_HRIS_OPTION }, ...options]
})

function openCreate(): void {
  formMode.value = 'create'
  editingId.value = null
  form.name = ''
  form.city = SELECT_HRIS_OPTION
  form.area_type = SELECT_HRIS_OPTION
  form.address = ''
  form.latitude = ''
  form.longitude = ''
  form.radius_meters = '150'
  form.status = 'active'
  formError.value = ''
  showFormModal.value = true
}

function openOptionModal(type: 'work_location' | 'work_area'): void {
  optionType.value = type
  optionName.value = ''
  optionError.value = ''
  showOptionModal.value = true
}

async function openEmployees(location: EmployeeWorkLocationRow): Promise<void> {
  employeesTarget.value = location
  assignedEmployees.value = []
  employeesError.value = ''
  showEmployeesModal.value = true
  employeesLoading.value = true
  try {
    assignedEmployees.value = await fetchEmployeeWorkLocationEmployees(location.id)
  } catch (err) {
    employeesError.value = err instanceof Error ? err.message : 'Unable to load assigned employees.'
  } finally {
    employeesLoading.value = false
  }
}

async function submitOption(): Promise<void> {
  const name = optionName.value.trim().replace(/\s+/g, ' ')
  if (!name) {
    optionError.value = optionType.value === 'work_location'
      ? 'Work location name is required.'
      : 'Work area name is required.'
    return
  }

  optionBusy.value = true
  optionError.value = ''
  try {
    const existingOptions = optionType.value === 'work_location'
      ? hrisOptions.value.work_locations
      : hrisOptions.value.work_areas
    const existing = existingOptions.find((option) => option.trim().toLocaleLowerCase() === name.toLocaleLowerCase())
    if (existing) {
      if (showFormModal.value) {
        if (optionType.value === 'work_location') form.city = existing
        else form.area_type = existing
      }
      showOptionModal.value = false
      toast.success(`“${existing}” is already available and has been selected.`)
      return
    }

    const response = await createHrisWorkLocationOption({ type: optionType.value, name })
    const saved = response.data
    hrisOptions.value = saved.type === 'work_location'
      ? { ...hrisOptions.value, work_locations: [...hrisOptions.value.work_locations, saved.name].sort((a, b) => a.localeCompare(b)) }
      : { ...hrisOptions.value, work_areas: [...hrisOptions.value.work_areas, saved.name].sort((a, b) => a.localeCompare(b)) }

    if (showFormModal.value) {
      if (saved.type === 'work_location') form.city = saved.name
      else form.area_type = saved.name
    }

    showOptionModal.value = false
    toast.success(`${saved.type === 'work_location' ? 'Work location' : 'Work area'} “${saved.name}” added.`)
  } catch (err) {
    optionError.value = err instanceof Error ? err.message : 'Unable to add this option.'
  } finally {
    optionBusy.value = false
  }
}

function openEdit(row: EmployeeWorkLocationRow): void {
  formMode.value = 'edit'
  editingId.value = row.id
  form.name = row.name
  form.city = row.city
  form.area_type = row.area_type
  form.address = row.address ?? ''
  form.latitude = row.latitude != null ? String(row.latitude) : ''
  form.longitude = row.longitude != null ? String(row.longitude) : ''
  form.radius_meters = row.radius_meters != null ? String(row.radius_meters) : '150'
  form.status = row.status
  formError.value = ''
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.name.trim()) { formError.value = 'Name is required.'; return }
  if (form.city === SELECT_HRIS_OPTION || !form.city.trim()) { formError.value = 'Select a work location from HRIS.'; return }
  if (form.area_type === SELECT_HRIS_OPTION || !form.area_type.trim()) { formError.value = 'Select a work area from HRIS.'; return }
  const latitude = parseDecimal(form.latitude)
  const longitude = parseDecimal(form.longitude)
  if (latitude === null || longitude === null) {
    formError.value = 'Latitude and longitude are required.'
    return
  }
  const radius = form.radius_meters ? Number(form.radius_meters) : 150
  if (!Number.isFinite(radius) || radius < 1 || radius > 100000) {
    formError.value = 'Radius must be between 1 and 100,000 meters.'
    return
  }

  formBusy.value = true
  formError.value = ''
  try {
    const payload = {
      name: form.name.trim(),
      city: form.city.trim(),
      area_type: form.area_type,
      address: form.address.trim() || null,
      latitude,
      longitude,
      radius_meters: radius,
      status: form.status,
    }

    if (formMode.value === 'create') {
      await createEmployeeWorkLocation(payload)
      toast.success('Work location created')
    }
    else if (editingId.value !== null) {
      await updateEmployeeWorkLocation(editingId.value, payload)
      toast.success('Work location updated')
    }
    showFormModal.value = false
    await Promise.all([load(), loadCities(), loadHrisOptions()])
  }
  catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  }
  finally {
    formBusy.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteTarget = ref<EmployeeWorkLocationRow | null>(null)
const deleteBusy = ref(false)

function confirmDelete(row: EmployeeWorkLocationRow): void {
  deleteTarget.value = row
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  try {
    await deleteEmployeeWorkLocation(deleteTarget.value.id)
    showDeleteModal.value = false
    deleteTarget.value = null
    toast.success('Work location deleted')
    await Promise.all([load(), loadCities()])
  }
  catch (e: unknown) {
    toast.fromError(e, 'Unable to delete this work location.')
  }
  finally {
    deleteBusy.value = false
  }
}

onMounted(async () => {
  await Promise.all([load(), loadCities(), loadHrisOptions()])
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="employee-work-locations">
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
            v-if="can('employee_work_location.create')"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-map-pinned"
            @click="openOptionModal('work_location')"
          >
            Add city
          </UButton>
          <UButton
            v-if="can('employee_work_location.create')"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-map"
            @click="openOptionModal('work_area')"
          >
            Add work area
          </UButton>
          <UButton
            v-if="can('employee_work_location.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add work location
          </UButton>
          <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-filter-x" @click="resetFilters">
            Reset
          </UButton>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading || hrisOptionsLoading" @click="refreshPage">
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
          <UAlert
            v-if="hrisOptionsError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Unable to load HRIS options"
            :description="hrisOptionsError"
          >
            <template #actions>
              <UButton size="xs" color="primary" variant="subtle" :loading="hrisOptionsLoading" @click="loadHrisOptions">
                Retry
              </UButton>
            </template>
          </UAlert>

          <UAlert
            v-if="filterError"
            color="warning"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Filter tidak valid"
            :description="filterError"
          />

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusTab"
            v-model:per-page="filters.per_page"
            search-placeholder="Search name, city, or address…"
            :status-options="statusOptions"
            :display-items="displayItems"
            show-per-page
          >
            <template #filters>
              <USelect
                :model-value="toSelectId(filters.city)"
                :items="[{ label: 'All cities', value: ALL }, ...cityFilterOptions.map(city => ({ label: city, value: city }))]"
                value-key="value"
                class="w-44"
                @update:model-value="(v: unknown) => { filters.city = fromSelectId(v) }"
              />
              <USelect
                :model-value="toSelectId(filters.area_type)"
                :items="[{ label: 'All work areas', value: ALL }, ...areaFilterOptions.map(area => ({ label: area, value: area }))]"
                value-key="value"
                class="w-44"
                @update:model-value="(v: unknown) => { filters.area_type = fromSelectId(v) }"
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
            empty-icon="i-lucide-map-pin-off"
            empty-message="No work locations found."
            @update:page="goToPage($event, load)"
          >
            <template #empty-extra>
              <UButton
                v-if="can('employee_work_location.create')"
                color="primary"
                variant="subtle"
                size="sm"
                icon="i-lucide-plus"
                @click="openCreate"
              >
                Add work location
              </UButton>
            </template>
          </DataTable>
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="showFormModal"
    :title="formMode === 'create' ? 'Add work location' : 'Edit work location'"
  >
    <template #body>
      <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <UFormField label="Name" required>
            <UInput v-model="form.name" placeholder="Head Office Jakarta" class="w-full" />
          </UFormField>

          <UFormField label="City / work location" required>
            <div class="flex gap-2">
              <USelect
                v-model="form.city"
                :items="formCityOptions"
                value-key="value"
                label-key="label"
                :disabled="hrisOptionsLoading || Boolean(hrisOptionsError)"
                class="min-w-0 flex-1"
              />
              <UButton
                v-if="can('employee_work_location.create')"
                color="neutral"
                variant="outline"
                icon="i-lucide-plus"
                aria-label="Add work location option"
                @click="openOptionModal('work_location')"
              />
            </div>
          </UFormField>

          <UFormField label="Work area" required>
            <div class="flex gap-2">
              <USelect
                v-model="form.area_type"
                :items="formAreaOptions"
                value-key="value"
                label-key="label"
                :disabled="hrisOptionsLoading || Boolean(hrisOptionsError)"
                class="min-w-0 flex-1"
              />
              <UButton
                v-if="can('employee_work_location.create')"
                color="neutral"
                variant="outline"
                icon="i-lucide-plus"
                aria-label="Add work area option"
                @click="openOptionModal('work_area')"
              />
            </div>
          </UFormField>

          <UFormField v-if="formMode === 'edit'" label="Status" required>
            <USelect
              v-model="form.status"
              :items="[{ label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' }]"
              value-key="value"
              label-key="label"
              class="w-full"
            />
          </UFormField>
        </div>

        <UFormField label="Address" hint="Max 1000 characters">
          <UTextarea v-model="form.address" :rows="2" placeholder="Jl. ..." maxlength="1000" class="w-full" />
        </UFormField>

        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Latitude" required>
            <UInput v-model="form.latitude" type="text" inputmode="decimal" placeholder="-6.0883183" class="w-full" />
          </UFormField>
          <UFormField label="Longitude" required>
            <UInput v-model="form.longitude" type="text" inputmode="decimal" placeholder="106.7438510" class="w-full" />
          </UFormField>
        </div>

        <UFormField label="Radius (m)" hint="Default 150. Area up to 100,000.">
          <UInput v-model="form.radius_meters" type="number" min="1" max="100000" step="1" class="w-full" />
        </UFormField>

        <UAlert v-if="formError" color="error" variant="subtle" :description="formError" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add work location' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="showOptionModal"
    :title="optionType === 'work_location' ? 'Add work location option' : 'Add work area option'"
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          This option is saved for future Employee Work Locations and does not change HRIS data.
        </p>
        <UFormField :label="optionType === 'work_location' ? 'Work location name' : 'Work area name'" required>
          <UInput
            v-model="optionName"
            :placeholder="optionType === 'work_location' ? 'e.g. Jakarta' : 'e.g. Warehouse'"
            :disabled="optionBusy"
            class="w-full"
            @keyup.enter="submitOption"
          />
        </UFormField>
        <UAlert v-if="optionError" color="error" variant="subtle" :description="optionError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="optionBusy" @click="showOptionModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="optionBusy" @click="submitOption">
          Save option
        </UButton>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="showDeleteModal" title="Delete work location">
    <template #body>
      <p class="text-sm text-muted">
        Are you sure you want to delete
        <strong class="text-highlighted">{{ deleteTarget?.name }}</strong>
        ({{ deleteTarget?.city }})?
      </p>
      <UAlert
        v-if="deleteTarget?.employee_count"
        class="mt-3"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="`${deleteTarget.employee_count} employee masih di-assign ke lokasi ini. Assignment mereka akan dinonaktifkan.`"
      />
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="deleteBusy" @click="showDeleteModal = false">
          Cancel
        </UButton>
        <UButton color="error" :loading="deleteBusy" @click="executeDelete">
          Delete
        </UButton>
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="showEmployeesModal"
    :title="`Employees — ${employeesTarget?.city || 'work location'}`"
  >
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Employees assigned to
          <strong class="text-highlighted">{{ employeesTarget?.name || employeesTarget?.city }}</strong>.
        </p>

        <div v-if="employeesLoading" class="text-sm text-muted">Loading…</div>
        <UAlert v-else-if="employeesError" color="error" variant="subtle" :description="employeesError" />
        <div
          v-else-if="assignedEmployees.length === 0"
          class="rounded-md border border-dashed border-default p-3 text-sm text-muted"
        >
          No employees assigned to this work location.
        </div>
        <ul v-else class="divide-y divide-default rounded-md border border-default">
          <li
            v-for="employee in assignedEmployees"
            :key="employee.id"
            class="flex items-center justify-between gap-3 px-3 py-2.5"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ employee.full_name }}</p>
              <p class="truncate font-mono text-xs text-muted">
                {{ employee.employee_code }} <span v-if="employee.nik">· {{ employee.nik }}</span>
              </p>
            </div>
            <UBadge size="sm" variant="subtle" color="success">Active</UBadge>
          </li>
        </ul>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end">
        <UButton color="neutral" variant="outline" @click="showEmployeesModal = false">Close</UButton>
      </div>
    </template>
  </UModal>
</template>
