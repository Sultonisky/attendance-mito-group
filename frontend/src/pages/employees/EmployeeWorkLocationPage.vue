<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { usePermission } from '../../features/auth/composables/usePermission'
import { useAppToast } from '../../composables/useAppToast'
import {
  WORK_AREA_OPTIONS,
  fetchEmployeeWorkLocations,
  fetchEmployeeWorkLocationCities,
  createEmployeeWorkLocation,
  updateEmployeeWorkLocation,
  deleteEmployeeWorkLocation,
  type EmployeeWorkLocationRow,
  type WorkAreaType,
} from '../../services/employeeWorkLocationApi'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import DashboardNavbarTitle from '../../components/DashboardNavbarTitle.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../utils/dataTable'

const { loading, error, filterError, meta, clearErrors, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

const data = ref<EmployeeWorkLocationRow[]>([])
const cities = ref<string[]>([])
const columnVisibility = ref<VisibilityState>()
const searchInput = ref('')
const statusTab = ref('all')

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

const areaColor: Record<WorkAreaType, 'primary' | 'info' | 'warning'> = {
  head_office: 'primary',
  factory: 'warning',
  branch: 'info',
}

const hideableColumns = [
  { id: 'name', label: 'Name' },
  { id: 'city', label: 'City / work location' },
  { id: 'area_type', label: 'Work area' },
  { id: 'address', label: 'Address' },
  { id: 'coordinates', label: 'Coordinates' },
  { id: 'employee_count', label: 'Employees' },
  { id: 'status', label: 'Status' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const ALL = '__all__'

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
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => createTruncatedText(row.original.name, 'text-sm font-medium'),
  },
  {
    accessorKey: 'city',
    header: ({ column }) => createSortableHeader(column, 'City / work location'),
    cell: ({ row }) => createTruncatedText(row.original.city, 'text-sm'),
  },
  {
    accessorKey: 'area_type',
    header: ({ column }) => createSortableHeader(column, 'Work area'),
    cell: ({ row }) => h(resolveComponent('UBadge'), {
      color: areaColor[row.original.area_type] ?? 'neutral',
      variant: 'subtle',
      size: 'sm',
    }, () => row.original.area_type_label),
  },
  {
    accessorKey: 'address',
    header: 'Address',
    cell: ({ row }) => createTruncatedText(row.original.address, 'text-sm text-[var(--ui-text-muted)]'),
  },
  {
    id: 'coordinates',
    header: 'Coordinates',
    cell: ({ row }) => {
      const loc = row.original
      if (loc.latitude == null || loc.longitude == null) {
        return h('span', { class: 'text-xs text-warning' }, 'Not set')
      }
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'truncate text-sm font-mono' }, `${loc.latitude}, ${loc.longitude}`),
        h('p', { class: 'truncate text-xs text-muted' }, [
          formatRadius(loc.radius_meters),
          h('a', {
            href: `https://maps.google.com/?q=${loc.latitude},${loc.longitude}`,
            target: '_blank',
            rel: 'noopener noreferrer',
            class: 'ml-1 text-primary hover:underline cursor-pointer',
            onClick: (e: Event) => e.stopPropagation(),
          }, '· map'),
        ]),
      ])
    },
  },
  {
    id: 'employee_count',
    header: 'Employees',
    accessorFn: (row) => row.employee_count ?? 0,
    cell: ({ row }) => {
      const count = row.original.employee_count ?? 0
      if (!count || !can('employees.view')) {
        return h('span', { class: 'text-sm tabular-nums text-muted' }, String(count))
      }
      return h(RouterLink, {
        to: { name: 'employees', query: { work_location_id: String(row.original.id) } },
        class: 'text-sm tabular-nums text-primary hover:underline',
      }, () => `${count} employee${count === 1 ? '' : 's'}`)
    },
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    cell: ({ row }) => createStatusBadge(row.original.status, row.original.status === 'active' ? 'success' : 'neutral'),
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
  city: '',
  area_type: 'branch' as WorkAreaType,
  address: '',
  latitude: '',
  longitude: '',
  radius_meters: '150',
  status: 'active' as 'active' | 'inactive',
})

function openCreate(): void {
  formMode.value = 'create'
  editingId.value = null
  form.name = ''
  form.city = ''
  form.area_type = 'branch'
  form.address = ''
  form.latitude = ''
  form.longitude = ''
  form.radius_meters = '150'
  form.status = 'active'
  formError.value = ''
  showFormModal.value = true
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
  if (!form.city.trim()) { formError.value = 'City / work location is required.'; return }
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
    await Promise.all([load(), loadCities()])
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
  await Promise.all([load(), loadCities()])
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
                :items="[{ label: 'All cities', value: ALL }, ...cities.map(c => ({ label: c, value: c }))]"
                value-key="value"
                class="w-44"
                @update:model-value="(v: unknown) => { filters.city = fromSelectId(v) }"
              />
              <USelect
                :model-value="toSelectId(filters.area_type)"
                :items="[{ label: 'All work areas', value: ALL }, ...WORK_AREA_OPTIONS]"
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
            <UInput v-model="form.city" placeholder="Jakarta" list="employee-work-location-cities" class="w-full" />
            <datalist id="employee-work-location-cities">
              <option v-for="c in cities" :key="c" :value="c" />
            </datalist>
          </UFormField>

          <UFormField label="Work area" required>
            <USelect v-model="form.area_type" :items="WORK_AREA_OPTIONS" value-key="value" label-key="label" class="w-full" />
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
</template>
