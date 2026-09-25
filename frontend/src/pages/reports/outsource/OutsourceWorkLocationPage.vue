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
  fetchOutsourceWorkLocations,
  fetchWorkLocationCities,
  createWorkLocationCity,
  createWorkLocation,
  createWorkLocationPin,
  updateWorkLocationPin,
  deleteWorkLocationPin,
  fetchWorkLocationPinOutsources,
  type OutsourceWorkLocationRow,
  type OutsourceWorkLocationFilters,
  type WorkLocationPinOutsourceRow,
} from '../../../services/outsourceWorkLocationApi'
import { fetchOutsourceStores } from '../../../services/outsourceService'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../../utils/dataTable'
import { ApiError } from '../../../services/apiClient'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

/** Soft filter/validation message — keeps the table visible (unlike hard `error`). */
const filterError = ref('')

const SEARCH_MAX = 255
const data = ref<OutsourceWorkLocationRow[]>([])
const cities = ref<{ id: number; name: string; code?: string | null }[]>([])
const columnVisibility = ref<VisibilityState>()
const statusTab = ref('active')
const searchInput = ref('')

const filters = reactive<OutsourceWorkLocationFilters>({
  search: '',
  city_id: '',
  status: 'active',
  per_page: 25,
  sort: 'cabang',
  direction: 'asc',
  page: 1,
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
  { label: 'All', value: 'all' },
]

const hideableColumns = [
  { id: 'cabang', label: 'Cabang/Kota' },
  { id: 'address', label: 'Alamat' },
  { id: 'outsource_count', label: 'Outsource' },
  { id: 'status', label: 'Status' },
  { id: 'pins', label: 'Pins' },
  { id: 'actions', label: 'Actions' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'error'> = {
  active: 'success',
  inactive: 'error',
}

const showFormModal = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formBusy = ref(false)
const formError = ref('')
const editingRow = ref<OutsourceWorkLocationRow | null>(null)

const form = reactive({
  city_id: '' as string,
  work_location_id: '' as string,
  pin_name: '',
  address: '',
  latitude: '',
  longitude: '',
  radius_meters: '150',
  status: 'active' as 'active' | 'inactive',
})

const formCabangs = ref<{ id: number; name: string }[]>([])

const showCityModal = ref(false)
const cityBusy = ref(false)
const cityError = ref('')
const cityForm = reactive({
  name: '',
})

const showDeleteModal = ref(false)
const deleteTarget = ref<OutsourceWorkLocationRow | null>(null)
const deleteBusy = ref(false)

const showOutsourcesModal = ref(false)
const outsourcesTarget = ref<OutsourceWorkLocationRow | null>(null)
const outsourcesLoading = ref(false)
const outsourcesError = ref('')
const outsourcesList = ref<WorkLocationPinOutsourceRow[]>([])

function formatRadius(meters: number | null | undefined): string {
  if (meters == null || !Number.isFinite(meters)) return '150 m'
  if (meters >= 1000) {
    const km = meters / 1000
    return Number.isInteger(km) ? `${km} km` : `${km.toFixed(1)} km`
  }
  return `${Math.round(meters)} m`
}

const columns = computed<TableColumn<OutsourceWorkLocationRow>[]>(() => [
  {
    id: 'cabang',
    header: ({ column }) => createSortableHeader(column, 'Cabang/Kota'),
    accessorFn: (row) => row.city?.name ?? row.cabang?.name ?? '',
    cell: ({ row }) => {
      const name = row.original.city?.name ?? row.original.cabang?.name ?? '—'
      return createTruncatedText(name, 'font-medium text-sm')
    },
  },
  {
    id: 'address',
    header: ({ column }) => createSortableHeader(column, 'Alamat'),
    accessorFn: (row) => row.address ?? '',
    cell: ({ row }) => createTruncatedText(row.original.address),
  },
  {
    id: 'outsource_count',
    header: ({ column }) => createSortableHeader(column, 'Outsource'),
    accessorFn: (row) => row.outsource_count,
    cell: ({ row }) => {
      const loc = row.original
      const count = loc.outsource_count
      if (count < 1) {
        return h('span', { class: 'text-[var(--ui-text-dimmed)] text-xs' }, '—')
      }
      return h(resolveComponent('UButton'), {
        size: 'xs',
        color: 'primary',
        variant: 'soft',
        icon: 'i-lucide-users',
        label: String(count),
        title: 'View outsources assigned to this pin',
        onClick: (e: Event) => {
          e.stopPropagation()
          openOutsources(loc)
        },
      })
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
    id: 'pins',
    header: ({ column }) => createSortableHeader(column, 'Pins'),
    accessorFn: (row) => row.pin_name,
    cell: ({ row }) => {
      const pin = row.original
      const coords = pin.latitude != null && pin.longitude != null
        ? `${pin.latitude}, ${pin.longitude}`
        : null
      return h('div', { class: 'min-w-0' }, [
        h('p', { class: 'truncate text-sm font-medium', title: pin.pin_name }, pin.pin_name || '—'),
        h('p', { class: 'truncate text-xs text-muted' }, [
          formatRadius(pin.radius_meters),
          coords
            ? h('a', {
                href: `https://maps.google.com/?q=${pin.latitude},${pin.longitude}`,
                target: '_blank',
                rel: 'noopener noreferrer',
                class: 'ml-1 text-primary hover:underline cursor-pointer',
                onClick: (e: Event) => e.stopPropagation(),
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
      const loc = row.original
      const items = [
        can('outsource_work_location.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(loc),
        },
        can('outsource_work_location.update') && {
          label: loc.status === 'active' ? 'Deactivate' : 'Activate',
          icon: loc.status === 'active' ? 'i-lucide-eye-off' : 'i-lucide-eye',
          onSelect: () => handleToggle(loc),
        },
        can('outsource_work_location.delete') && {
          label: 'Delete',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmDelete(loc),
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
function toSelectId(raw: string): string { return raw === '' ? ALL : raw }
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

async function loadCities(): Promise<void> {
  try { cities.value = await fetchWorkLocationCities() } catch { /* non-blocking */ }
}

function upsertCityInList(city: { id: number; name: string; code?: string | null }): void {
  const existingIdx = cities.value.findIndex(c => c.id === city.id)
  if (existingIdx >= 0) {
    cities.value[existingIdx] = { id: city.id, name: city.name, code: city.code ?? null }
  } else {
    cities.value.push({ id: city.id, name: city.name, code: city.code ?? null })
  }
  cities.value.sort((a, b) => a.name.localeCompare(b.name))
}

function selectCityForForm(cityId: number): void {
  form.city_id = String(cityId)
  void loadFormCabangs(form.city_id)
}

function openCityModal(): void {
  cityForm.name = ''
  cityError.value = ''
  showCityModal.value = true
}

async function submitCity(): Promise<void> {
  const name = cityForm.name.trim()
  if (!name) {
    cityError.value = 'City name is required.'
    return
  }

  cityBusy.value = true
  cityError.value = ''
  try {
    const res = await createWorkLocationCity({ name })
    upsertCityInList(res.data)
    showCityModal.value = false
    toast.success(`City “${res.data.name}” added.`)

    if (!showFormModal.value) {
      formMode.value = 'create'
      resetForm()
      showFormModal.value = true
    }
    selectCityForForm(res.data.id)
  }
  catch (err) {
    if (err instanceof ApiError && err.errors?.name?.[0]) {
      cityError.value = err.errors.name[0]
    } else {
      cityError.value = err instanceof Error ? err.message : 'Unable to add city.'
    }
  }
  finally {
    cityBusy.value = false
  }
}

async function loadFormCabangs(cityId: string): Promise<void> {
  formCabangs.value = []
  form.work_location_id = ''
  if (!cityId) return
  try {
    const stores = await fetchOutsourceStores(Number(cityId))
    formCabangs.value = stores.map(s => ({ id: s.id, name: s.name }))
    // OS model: one cabang per city (name matches city). Prefer that match.
    const city = cities.value.find(c => String(c.id) === cityId)
    const match = city
      ? formCabangs.value.find(s => s.name.toUpperCase() === city.name.toUpperCase())
      : null
    if (match) {
      form.work_location_id = String(match.id)
    } else if (formCabangs.value.length === 1) {
      form.work_location_id = String(formCabangs.value[0].id)
    }
  } catch {
    formCabangs.value = []
  }
}

const resolvedCabangHint = computed(() => {
  if (!form.city_id) return '—'
  const city = cities.value.find(c => String(c.id) === form.city_id)
  if (form.work_location_id) {
    const cabang = formCabangs.value.find(s => String(s.id) === form.work_location_id)
    if (cabang) return cabang.name
  }
  return city ? `${city.name} (akan dibuat otomatis)` : 'Auto dari kota'
})

async function resolveCabangId(): Promise<number | null> {
  if (!form.city_id) return null

  const city = cities.value.find(c => String(c.id) === form.city_id)
  if (!city) return null

  // Prefer existing city-named cabang; never invent a second cabang for the same city name.
  const existing = formCabangs.value.find(c => c.name.toUpperCase() === city.name.toUpperCase())
    ?? (formCabangs.value.length === 1 ? formCabangs.value[0] : null)
  if (existing) {
    form.work_location_id = String(existing.id)
    return existing.id
  }

  const created = await createWorkLocation({
    name: city.name,
    city_id: Number(form.city_id),
  })
  form.work_location_id = String(created.data.id)
  formCabangs.value = [{ id: created.data.id, name: city.name }]
  return created.data.id
}

watch(statusTab, (value) => {
  filters.status = value
  if (!ready.value) return
  meta.current_page = 1
  load()
})

watchDebounced(searchInput, (value) => {
  let next = value
  if (next.length > SEARCH_MAX) {
    next = next.slice(0, SEARCH_MAX)
    if (searchInput.value !== next) {
      searchInput.value = next
      toast.error(
        'Search terlalu panjang',
        `Maksimal ${SEARCH_MAX} karakter. Teks dipotong otomatis — sesuaikan lalu cari lagi.`,
      )
    }
  }
  filters.search = next
  if (!ready.value) return
  meta.current_page = 1
  load()
}, { debounce: 400 })

watch(
  () => [filters.city_id, filters.per_page] as const,
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
  filterError.value = ''
  try {
    const res = await fetchOutsourceWorkLocations({
      search: filters.search || undefined,
      city_id: filters.city_id || undefined,
      status: filters.status,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    const kind = await handleApiError(err, 'Unable to load work locations. Please try again.')
    if (kind === 'validation') {
      // Keep previous rows; show toast + soft banner instead of blank "Failed to load".
      filterError.value = error.value
      error.value = ''
      toast.error('Search tidak valid', filterError.value)
    }
  } finally {
    loading.value = false
  }
}

function resetFilters(): void {
  filters.search = ''
  filters.city_id = ''
  filters.status = 'active'
  filters.per_page = 25
  filters.sort = 'cabang'
  filters.direction = 'asc'
  statusTab.value = 'active'
  searchInput.value = ''
  filterError.value = ''
  meta.current_page = 1
  load()
}

function resetForm(): void {
  form.city_id = ''
  form.work_location_id = ''
  form.pin_name = ''
  form.address = ''
  form.latitude = ''
  form.longitude = ''
  form.radius_meters = '150'
  form.status = 'active'
  formCabangs.value = []
  formError.value = ''
  editingRow.value = null
}

function openCreate(): void {
  formMode.value = 'create'
  resetForm()
  showFormModal.value = true
}

async function openEdit(row: OutsourceWorkLocationRow): Promise<void> {
  formMode.value = 'edit'
  editingRow.value = row
  form.city_id = row.city ? String(row.city.id) : ''
  form.work_location_id = String(row.work_location_id)
  form.pin_name = row.pin_name
  form.address = row.address ?? ''
  form.latitude = row.latitude != null ? String(row.latitude) : ''
  form.longitude = row.longitude != null ? String(row.longitude) : ''
  form.radius_meters = row.radius_meters != null ? String(row.radius_meters) : '150'
  form.status = row.status
  formError.value = ''
  formCabangs.value = [{ id: row.work_location_id, name: row.cabang.name }]
  if (form.city_id) {
    try {
      const stores = await fetchOutsourceStores(Number(form.city_id))
      formCabangs.value = stores.map(s => ({ id: s.id, name: s.name }))
    } catch { /* keep current */ }
  }
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.pin_name.trim()) {
    formError.value = 'Pin name is required.'
    return
  }
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
  if (form.address.trim().length > 1000) {
    formError.value = 'Address may not exceed 1000 characters.'
    return
  }

  formBusy.value = true
  formError.value = ''
  try {
    if (formMode.value === 'create') {
      if (!form.city_id) {
        formError.value = 'City is required.'
        return
      }
      const cabangId = await resolveCabangId()
      if (!cabangId) {
        formError.value = 'Unable to resolve cabang for this city.'
        return
      }
      await createWorkLocationPin(cabangId, {
        name: form.pin_name.trim(),
        address: form.address.trim() || null,
        latitude,
        longitude,
        radius_meters: radius,
        status: form.status,
      })
      toast.success('Work location created')
    } else if (editingRow.value) {
      await updateWorkLocationPin(editingRow.value.work_location_id, editingRow.value.id, {
        name: form.pin_name.trim(),
        address: form.address.trim() || null,
        latitude,
        longitude,
        radius_meters: radius,
        status: form.status,
      })
      toast.success('Work location updated')
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

async function handleToggle(row: OutsourceWorkLocationRow): Promise<void> {
  try {
    const next = row.status === 'active' ? 'inactive' : 'active'
    await updateWorkLocationPin(row.work_location_id, row.id, { status: next })
    const idx = data.value.findIndex(r => r.id === row.id)
    if (idx !== -1) data.value[idx] = { ...data.value[idx], status: next }
    toast.success(next === 'active' ? 'Pin activated' : 'Pin deactivated')
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to update pin status.')
    await load()
  }
}

function confirmDelete(row: OutsourceWorkLocationRow): void {
  deleteTarget.value = row
  showDeleteModal.value = true
}

async function openOutsources(row: OutsourceWorkLocationRow): Promise<void> {
  outsourcesTarget.value = row
  outsourcesList.value = []
  outsourcesError.value = ''
  showOutsourcesModal.value = true
  outsourcesLoading.value = true
  try {
    outsourcesList.value = await fetchWorkLocationPinOutsources(row.work_location_id, row.id)
  } catch (e: unknown) {
    outsourcesError.value = e instanceof Error ? e.message : 'Failed to load outsources.'
  } finally {
    outsourcesLoading.value = false
  }
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  try {
    await deleteWorkLocationPin(deleteTarget.value.work_location_id, deleteTarget.value.id)
    showDeleteModal.value = false
    deleteTarget.value = null
    toast.success('Work location deleted')
    await load()
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to delete this work location.')
  } finally {
    deleteBusy.value = false
  }
}

onMounted(async () => {
  await loadCities()
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="outsource-work-locations">
    <template #header>
      <UDashboardNavbar title="Work locations">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can('outsource_work_location.create')"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-map-pinned"
            @click="openCityModal"
          >
            Add city
          </UButton>
          <UButton
            v-if="can('outsource_work_location.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add location
          </UButton>
          <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-filter-x" @click="resetFilters">Reset</UButton>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">Refresh</UButton>
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
            title="Filter / search tidak valid"
            :description="filterError"
            class="mb-2"
          />

          <div class="flex items-center gap-2 text-sm text-muted">
            <UIcon name="i-lucide-map-pin" class="size-4 shrink-0" />
            <span>
              <strong class="text-highlighted font-semibold">{{ meta.total }}</strong>
              work location{{ meta.total !== 1 ? 's' : '' }}
              <span class="text-muted"> (1 row = 1 address/pin)</span>
            </span>
          </div>

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusTab"
            v-model:per-page="filters.per_page"
            search-placeholder="Search cabang, address, or pin…"
            :search-maxlength="SEARCH_MAX"
            :status-options="statusOptions"
            :display-items="displayItems"
            show-per-page
          >
            <template #filters>
              <USelect
                :model-value="toSelectId(filters.city_id)"
                :items="[{ label: 'All cities/cabangs', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value"
                class="w-44"
                @update:model-value="(v: unknown) => { filters.city_id = fromSelectId(v) }"
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
            empty-message="No work locations found for the selected filters."
            @update:page="goToPage($event, load)"
          />
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
        <p class="text-sm text-muted">
          Each row is one address/pin. Cabang is auto-managed as one per city
          (same name as the city) — you only pick the city, then add the pin.
        </p>

        <UFormField v-if="formMode === 'create'" label="City" required>
          <div class="flex gap-2">
            <USelect
              :model-value="toSelectId(form.city_id)"
              :items="[{ label: 'Select city', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
              value-key="value"
              class="min-w-0 flex-1"
              @update:model-value="(v: unknown) => { form.city_id = fromSelectId(v); loadFormCabangs(form.city_id) }"
            />
            <UButton
              v-if="can('outsource_work_location.create')"
              color="neutral"
              variant="outline"
              icon="i-lucide-plus"
              :disabled="formBusy"
              @click="openCityModal"
            >
              Add
            </UButton>
          </div>
          <p v-if="form.city_id" class="mt-1 text-xs text-muted">
            Cabang:
            <span class="text-highlighted">{{ resolvedCabangHint }}</span>
          </p>
        </UFormField>

        <UFormField label="Pin name" required>
          <UInput v-model="form.pin_name" placeholder="e.g. Gate A / Wilayah Bangka" class="w-full" />
        </UFormField>

        <UFormField label="Alamat" hint="Max 1000 characters">
          <UInput v-model="form.address" placeholder="Street address" maxlength="1000" class="w-full" />
        </UFormField>

        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Latitude" required>
            <UInput v-model="form.latitude" type="text" inputmode="decimal" class="w-full" />
          </UFormField>
          <UFormField label="Longitude" required>
            <UInput v-model="form.longitude" type="text" inputmode="decimal" class="w-full" />
          </UFormField>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Radius (m)" hint="Default 150. Area pins up to 100,000.">
            <UInput v-model="form.radius_meters" type="number" min="1" max="100000" step="1" class="w-full" />
          </UFormField>
          <UFormField label="Status">
            <USelect
              v-model="form.status"
              :items="[
                { label: 'Active', value: 'active' },
                { label: 'Inactive', value: 'inactive' },
              ]"
              value-key="value"
              class="w-full"
            />
          </UFormField>
        </div>

        <UAlert v-if="formError" color="error" variant="subtle" :description="formError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">Cancel</UButton>
        <UButton color="primary" :loading="formBusy" @click="submitForm">
          {{ formMode === 'create' ? 'Add location' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="showCityModal" title="Add city">
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Create a city so it can be selected when adding a work location.
        </p>
        <UFormField label="City name" required>
          <UInput
            v-model="cityForm.name"
            placeholder="e.g. Jakarta"
            class="w-full"
            :disabled="cityBusy"
            @keyup.enter="submitCity"
          />
        </UFormField>
        <UAlert v-if="cityError" color="error" variant="subtle" :description="cityError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="cityBusy" @click="showCityModal = false">Cancel</UButton>
        <UButton color="primary" :loading="cityBusy" @click="submitCity">Save city</UButton>
      </div>
    </template>
  </UModal>

  <UModal v-model:open="showDeleteModal" title="Delete work location">
    <template #body>
      <p class="text-sm text-muted">
        Delete pin
        <strong class="text-highlighted">{{ deleteTarget?.pin_name }}</strong>
        at
        <strong class="text-highlighted">{{ deleteTarget?.address || 'no address' }}</strong>?
      </p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="deleteBusy" @click="showDeleteModal = false">Cancel</UButton>
        <UButton color="error" :loading="deleteBusy" @click="executeDelete">Delete</UButton>
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="showOutsourcesModal"
    :title="`Outsources — ${outsourcesTarget?.pin_name || 'pin'}`"
  >
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          People who can check in/out at
          <strong class="text-highlighted">{{ outsourcesTarget?.address || outsourcesTarget?.pin_name }}</strong>
          ({{ outsourcesTarget?.city?.name || outsourcesTarget?.cabang?.name }}).
        </p>

        <div v-if="outsourcesLoading" class="text-sm text-muted">Loading…</div>
        <UAlert v-else-if="outsourcesError" color="error" variant="subtle" :description="outsourcesError" />
        <div
          v-else-if="outsourcesList.length === 0"
          class="rounded-md border border-dashed border-default p-3 text-sm text-muted"
        >
          No outsource assigned to this pin yet.
        </div>
        <ul v-else class="divide-y divide-default rounded-md border border-default">
          <li
            v-for="person in outsourcesList"
            :key="person.id"
            class="flex items-center justify-between gap-3 px-3 py-2.5"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ person.name }}</p>
              <p class="truncate font-mono text-xs text-muted">{{ person.outsource_code }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <UBadge
                size="sm"
                variant="subtle"
                :color="person.assignment_scope === 'pin' ? 'primary' : 'neutral'"
              >
                {{ person.assignment_scope === 'pin' ? 'This pin' : 'All cabang pins' }}
              </UBadge>
              <UBadge
                size="sm"
                variant="subtle"
                :color="person.status === 'active' ? 'success' : 'error'"
              >
                {{ person.status }}
              </UBadge>
            </div>
          </li>
        </ul>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end">
        <UButton color="neutral" variant="outline" @click="showOutsourcesModal = false">Close</UButton>
      </div>
    </template>
  </UModal>
</template>
