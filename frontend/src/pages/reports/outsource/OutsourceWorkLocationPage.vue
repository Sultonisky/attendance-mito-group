<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../../composables/useReportPage'
import { useDataTableSort } from '../../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../../composables/useDataTableDisplay'
import { usePermission } from '../../../features/auth/composables/usePermission'
import {
  fetchOutsourceWorkLocations,
  fetchWorkLocationCities,
  createWorkLocation,
  updateWorkLocation,
  toggleWorkLocationStatus,
  deleteWorkLocation,
  type OutsourceWorkLocationRow,
  type OutsourceWorkLocationFilters,
} from '../../../services/outsourceWorkLocationApi'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()

// ── Table data ────────────────────────────────────────────────────────────────
const data         = ref<OutsourceWorkLocationRow[]>([])
const cities       = ref<{ id: number; name: string }[]>([])
const columnVisibility = ref<VisibilityState>()
const statusTab    = ref('active')
const searchInput  = ref('')

const filters = reactive<OutsourceWorkLocationFilters>({
  search:    '',
  city_id:   '',
  status:    'active',
  per_page:  25,
  sort:      'name',
  direction: 'asc',
  page:      1,
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'Active',   value: 'active'   },
  { label: 'Inactive', value: 'inactive' },
  { label: 'All',      value: 'all'      },
]

const hideableColumns = [
  { id: 'code',            label: 'Code'       },
  { id: 'name',            label: 'Store'      },
  { id: 'city',            label: 'City'       },
  { id: 'address',         label: 'Address'    },
  { id: 'outsource_count', label: 'Outsources' },
  { id: 'coordinates',     label: 'Coords'     },
  { id: 'radius_meters',   label: 'Radius'     },
  { id: 'status',          label: 'Status'     },
  { id: 'actions',         label: 'Actions'    },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'error'> = {
  active:   'success',
  inactive: 'error',
}

// ── Modal — create/edit ───────────────────────────────────────────────────────
const showFormModal = ref(false)
const formMode      = ref<'create' | 'edit'>('create')
const formBusy      = ref(false)
const formError     = ref('')
const editingId     = ref<number | null>(null)

const form = reactive({
  name:          '',
  city_id:       '' as string,
  latitude:      '' as string,
  longitude:     '' as string,
  radius_meters: '' as string,
})

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteTarget    = ref<OutsourceWorkLocationRow | null>(null)
const deleteBusy      = ref(false)

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<OutsourceWorkLocationRow>[]>(() => [
  {
    accessorKey: 'code',
    header: ({ column }) => createSortableHeader(column, 'Code'),
    cell: ({ row }) => h('span', {
      class: 'block w-[8rem] truncate font-mono text-xs text-[var(--ui-text-muted)] tracking-tight cursor-default',
      title: row.original.code,
    }, row.original.code || '—'),
  },
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Store'),
    cell: ({ row }) => h('span', { class: 'font-medium' }, row.original.name),
  },
  {
    id: 'city',
    header: ({ column }) => createSortableHeader(column, 'City'),
    accessorFn: (row) => row.city?.name ?? '',
    cell: ({ row }) => row.original.city?.name ?? '—',
  },
  {
    id: 'address',
    header: 'Address',
    accessorFn: (row) => row.address,
    cell: ({ row }) => h('span', {
      class: 'block max-w-[14rem] truncate text-[var(--ui-text-muted)] text-xs cursor-default',
      title: row.original.address,
    }, row.original.address || '—'),
  },
  {
    id: 'outsource_count',
    header: ({ column }) => createSortableHeader(column, 'Outsources'),
    accessorFn: (row) => row.outsource_count,
    cell: ({ row }) => {
      const count = row.original.outsource_count
      return h('span', {
        class: count > 0
          ? 'inline-flex items-center rounded-full bg-primary/10 text-primary px-2 py-0.5 text-xs font-semibold'
          : 'text-[var(--ui-text-dimmed)] text-xs',
      }, count > 0 ? String(count) : '—')
    },
  },
  {
    id: 'coordinates',
    header: 'Coords',
    cell: ({ row }) => {
      const { latitude: lat, longitude: lng } = row.original
      if (lat === null || lng === null) return h('span', { class: 'text-[var(--ui-text-dimmed)] text-xs' }, '—')
      return h('a', {
        href: `https://maps.google.com/?q=${lat},${lng}`,
        target: '_blank',
        rel: 'noopener noreferrer',
        class: 'font-mono text-xs text-primary hover:underline',
        title: 'Open in Google Maps',
      }, `${lat.toFixed(5)}, ${lng.toFixed(5)}`)
    },
  },
  {
    accessorKey: 'radius_meters',
    header: 'Radius',
    cell: ({ row }) => {
      const r = row.original.radius_meters
      return r !== null
        ? h('span', { class: 'text-xs tabular-nums' }, `${r} m`)
        : h('span', { class: 'text-[var(--ui-text-dimmed)] text-xs' }, '—')
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

// ── Helpers ───────────────────────────────────────────────────────────────────
const ALL = '__all__'
function toSelectId(raw: string): string { return raw === '' ? ALL : raw }
function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

async function loadCities(): Promise<void> {
  try { cities.value = await fetchWorkLocationCities() } catch { /* non-blocking */ }
}

// ── Watches ───────────────────────────────────────────────────────────────────
watch(statusTab, (value) => {
  filters.status = value
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
  try {
    const res = await fetchOutsourceWorkLocations({
      search:    filters.search  || undefined,
      city_id:   filters.city_id || undefined,
      status:    filters.status,
      per_page:  filters.per_page,
      sort:      filters.sort,
      direction: filters.direction,
      page:      meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load work locations. Please try again.')
  } finally {
    loading.value = false
  }
}

function resetFilters(): void {
  filters.search    = ''
  filters.city_id   = ''
  filters.status    = 'active'
  filters.per_page  = 25
  filters.sort      = 'name'
  filters.direction = 'asc'
  statusTab.value   = 'active'
  searchInput.value = ''
  meta.current_page = 1
  load()
}

// ── CRUD actions ──────────────────────────────────────────────────────────────
function resetForm(): void {
  form.name          = ''
  form.city_id       = ''
  form.latitude      = ''
  form.longitude     = ''
  form.radius_meters = ''
  formError.value    = ''
}

function openCreate(): void {
  formMode.value  = 'create'
  editingId.value = null
  resetForm()
  showFormModal.value = true
}

function openEdit(loc: OutsourceWorkLocationRow): void {
  formMode.value     = 'edit'
  editingId.value    = loc.id
  form.name          = loc.name
  form.city_id       = loc.city ? String(loc.city.id) : ''
  form.latitude      = loc.latitude  !== null ? String(loc.latitude)  : ''
  form.longitude     = loc.longitude !== null ? String(loc.longitude) : ''
  form.radius_meters = loc.radius_meters !== null ? String(loc.radius_meters) : ''
  formError.value    = ''
  showFormModal.value = true
}

/** Parse decimal string; accepts both "." and "," as decimal separator. */
function parseDecimal(raw: string): number | null {
  const trimmed = raw.trim()
  if (!trimmed) return null
  // Indonesian/EU often type " -6,1754 "; JS Number() only accepts "."
  const normalized = trimmed.replace(',', '.')
  const n = Number(normalized)
  return Number.isFinite(n) ? n : null
}

async function submitForm(): Promise<void> {
  if (!form.name.trim()) { formError.value = 'Store name is required.'; return }

  const latitude = parseDecimal(form.latitude)
  const longitude = parseDecimal(form.longitude)
  if (form.latitude.trim() && latitude === null) {
    formError.value = 'Latitude must be a valid number (use . or , as decimal).'
    return
  }
  if (form.longitude.trim() && longitude === null) {
    formError.value = 'Longitude must be a valid number (use . or , as decimal).'
    return
  }

  formBusy.value = true
  formError.value = ''
  try {
    const payload = {
      name:          form.name.trim(),
      city_id:       form.city_id ? Number(form.city_id) : null,
      latitude,
      longitude,
      radius_meters: form.radius_meters ? Number(form.radius_meters) : null,
    }
    if (formMode.value === 'create') {
      await createWorkLocation(payload)
    } else if (editingId.value !== null) {
      await updateWorkLocation(editingId.value, payload)
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

async function handleToggle(loc: OutsourceWorkLocationRow): Promise<void> {
  try {
    const res = await toggleWorkLocationStatus(loc.id)
    const idx = data.value.findIndex(l => l.id === loc.id)
    if (idx !== -1) data.value[idx] = { ...data.value[idx], status: res.data.status as 'active' | 'inactive' }
  } catch { await load() }
}

function confirmDelete(loc: OutsourceWorkLocationRow): void {
  deleteTarget.value = loc
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  try {
    await deleteWorkLocation(deleteTarget.value.id)
    showDeleteModal.value = false
    deleteTarget.value = null
    await load()
  } catch { /* keep modal open */ } finally {
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
          <div class="flex items-center gap-2 text-sm text-muted">
            <UIcon name="i-lucide-map-pin" class="size-4 shrink-0" />
            <span><strong class="text-highlighted font-semibold">{{ meta.total }}</strong> work location{{ meta.total !== 1 ? 's' : '' }}</span>
          </div>

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusTab"
            v-model:per-page="filters.per_page"
            search-placeholder="Search store, code, or city…"
            :status-options="statusOptions"
            :display-items="displayItems"
            show-per-page
          >
            <template #filters>
              <USelect
                :model-value="toSelectId(filters.city_id)"
                :items="[{ label: 'All cities', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value"
                class="w-36"
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

  <!-- ── Create / Edit modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showFormModal" :title="formMode === 'create' ? 'Add work location' : 'Edit work location'">
    <template #body>
      <div class="space-y-4">
        <UFormField label="Store name" required>
          <UInput v-model="form.name" placeholder="e.g. MALL KELAPA GADING" class="w-full" />
        </UFormField>

        <UFormField label="City">
          <USelect
            :model-value="toSelectId(form.city_id)"
            :items="[{ label: 'No city', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
            value-key="value"
            class="w-full"
            @update:model-value="(v: unknown) => { form.city_id = fromSelectId(v) }"
          />
        </UFormField>

        <div class="grid grid-cols-2 gap-3">
          <UFormField label="Latitude">
            <UInput
              v-model="form.latitude"
              type="text"
              inputmode="decimal"
              placeholder="-6.1754"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Longitude">
            <UInput
              v-model="form.longitude"
              type="text"
              inputmode="decimal"
              placeholder="106.8272"
              class="w-full"
            />
          </UFormField>
        </div>

        <UFormField label="Radius (meters)">
          <UInput v-model="form.radius_meters" type="number" step="1" placeholder="150" class="w-full" />
        </UFormField>

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

  <!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showDeleteModal" title="Delete work location">
    <template #body>
      <p class="text-sm text-muted">
        Are you sure you want to delete
        <strong class="text-highlighted">{{ deleteTarget?.name }}</strong>?
        All outsource assignments for this location will also be deactivated.
      </p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="deleteBusy" @click="showDeleteModal = false">Cancel</UButton>
        <UButton color="error" :loading="deleteBusy" @click="executeDelete">Delete</UButton>
      </div>
    </template>
  </UModal>
</template>
