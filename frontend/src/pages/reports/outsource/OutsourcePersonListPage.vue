<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../../composables/useReportPage'
import { useDataTableSort } from '../../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../../composables/useDataTableDisplay'
import { usePermission } from '../../../features/auth/composables/usePermission'
import {
  fetchOutsourcePersons,
  createOutsourcePerson,
  updateOutsourcePerson,
  toggleOutsourcePersonStatus,
  deleteOutsourcePerson,
  type OutsourcePersonRow,
  type OutsourcePersonFilters,
} from '../../../services/outsourcePersonApi'
import { fetchOutsourceCities, fetchOutsourceStores } from '../../../services/outsourceService'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()

// ── Table data ────────────────────────────────────────────────────────────────
const data = ref<OutsourcePersonRow[]>([])
const cities = ref<{ id: number; name: string }[]>([])
const stores = ref<{ id: number; name: string; city_id: number }[]>([])
const columnVisibility = ref<VisibilityState>()
const statusTab = ref('all')
const searchInput = ref('')

// ── Filters ───────────────────────────────────────────────────────────────────
const filters = reactive<OutsourcePersonFilters>({
  search:    '',
  city_id:   '',
  store_id:  '',
  status:    'all',
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
  { label: 'All',      value: 'all'      },
  { label: 'Active',   value: 'active'   },
  { label: 'Inactive', value: 'inactive' },
]

const hideableColumns = [
  { id: 'outsource_code', label: 'Code'    },
  { id: 'name',           label: 'Name'    },
  { id: 'city',           label: 'City'    },
  { id: 'store',          label: 'Store'   },
  { id: 'status',         label: 'Status'  },
  { id: 'created_at',     label: 'Joined'  },
  { id: 'actions',        label: 'Actions' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'error'> = {
  active:   'success',
  inactive: 'error',
}

// ── Modal — create/edit ───────────────────────────────────────────────────────
const showFormModal   = ref(false)
const formMode        = ref<'create' | 'edit'>('create')
const formBusy        = ref(false)
const formError       = ref('')
const editingId       = ref<number | null>(null)
const formModalStores = ref<{ id: number; name: string }[]>([])

const form = reactive({
  name:     '',
  city_id:  '' as string,
  store_id: '' as string,
})

const formCities = ref<{ id: number; name: string }[]>([])

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal  = ref(false)
const deleteTarget     = ref<OutsourcePersonRow | null>(null)
const deleteBusy       = ref(false)

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<OutsourcePersonRow>[]>(() => [
  {
    accessorKey: 'outsource_code',
    header: ({ column }) => createSortableHeader(column, 'Code'),
    cell: ({ row }) => {
      const code = row.original.outsource_code || '—'
      return h('span', {
        class: 'block w-[9rem] truncate font-mono text-xs text-[var(--ui-text-muted)] tracking-tight cursor-default',
        title: code,
      }, code)
    },
  },
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
  },
  {
    id: 'city',
    header: ({ column }) => createSortableHeader(column, 'City'),
    accessorFn: (row) => row.city?.name ?? '',
    cell: ({ row }) => row.original.city?.name ?? '—',
  },
  {
    id: 'store',
    header: ({ column }) => createSortableHeader(column, 'Store'),
    accessorFn: (row) => row.store?.name ?? '',
    cell: ({ row }) => row.original.store?.name ?? '—',
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
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Joined'),
    cell: ({ row }) => row.original.created_at ?? '—',
  },
  {
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => {
      const person = row.original
      const items = [
        can('outsource_person.update') && {
          label: 'Edit',
          icon: 'i-lucide-pencil',
          onSelect: () => openEdit(person),
        },
        can('outsource_person.update') && {
          label: person.status === 'active' ? 'Deactivate' : 'Activate',
          icon: person.status === 'active' ? 'i-lucide-user-x' : 'i-lucide-user-check',
          onSelect: () => handleToggle(person),
        },
        can('outsource_person.delete') && {
          label: 'Delete',
          icon: 'i-lucide-trash-2',
          color: 'error' as const,
          onSelect: () => confirmDelete(person),
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
  try { cities.value = await fetchOutsourceCities() } catch { /* non-blocking */ }
}

async function onCityChange(): Promise<void> {
  stores.value = []
  filters.store_id = ''
  if (!filters.city_id) return
  try { stores.value = await fetchOutsourceStores(Number(filters.city_id)) } catch { stores.value = [] }
}

async function onFormCityChange(): Promise<void> {
  formModalStores.value = []
  form.store_id = ''
  if (!form.city_id) return
  try {
    const raw = await fetchOutsourceStores(Number(form.city_id))
    formModalStores.value = raw.map(s => ({ id: s.id, name: s.name }))
  } catch { formModalStores.value = [] }
}

// ── Load ──────────────────────────────────────────────────────────────────────
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
  () => [filters.city_id, filters.store_id, filters.per_page] as const,
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
    const res = await fetchOutsourcePersons({
      search:    filters.search    || undefined,
      city_id:   filters.city_id   || undefined,
      store_id:  filters.store_id  || undefined,
      status:    filters.status !== 'all' ? filters.status : undefined,
      per_page:  filters.per_page,
      sort:      filters.sort,
      direction: filters.direction,
      page:      meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load outsource persons. Please try again.')
  } finally {
    loading.value = false
  }
}

function resetFilters(): void {
  filters.search    = ''
  filters.city_id   = ''
  filters.store_id  = ''
  filters.status    = 'all'
  filters.per_page  = 25
  filters.sort      = 'name'
  filters.direction = 'asc'
  statusTab.value   = 'all'
  searchInput.value = ''
  stores.value      = []
  meta.current_page = 1
  load()
}

// ── CRUD actions ──────────────────────────────────────────────────────────────
function openCreate(): void {
  formMode.value  = 'create'
  editingId.value = null
  form.name       = ''
  form.city_id    = ''
  form.store_id   = ''
  formModalStores.value = []
  formError.value = ''
  formCities.value = cities.value
  showFormModal.value = true
}

function openEdit(person: OutsourcePersonRow): void {
  formMode.value  = 'edit'
  editingId.value = person.id
  form.name       = person.name
  form.city_id    = person.city ? String(person.city.id) : ''
  form.store_id   = person.store ? String(person.store.id) : ''
  formModalStores.value = person.store
    ? [{ id: person.store.id, name: person.store.name }]
    : []
  formError.value = ''
  formCities.value = cities.value
  showFormModal.value = true
}

async function submitForm(): Promise<void> {
  if (!form.name.trim()) { formError.value = 'Name is required.'; return }
  formBusy.value = true
  formError.value = ''
  try {
    const payload = {
      name:     form.name.trim(),
      store_id: form.store_id ? Number(form.store_id) : null,
    }
    if (formMode.value === 'create') {
      await createOutsourcePerson(payload)
    } else if (editingId.value !== null) {
      await updateOutsourcePerson(editingId.value, payload)
    }
    showFormModal.value = false
    await load()
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Failed to save. Please try again.'
  } finally {
    formBusy.value = false
  }
}

async function handleToggle(person: OutsourcePersonRow): Promise<void> {
  try {
    const res = await toggleOutsourcePersonStatus(person.id)
    const idx = data.value.findIndex(p => p.id === person.id)
    if (idx !== -1) data.value[idx] = { ...data.value[idx], status: res.data.status as 'active' | 'inactive' }
  } catch { await load() }
}

function confirmDelete(person: OutsourcePersonRow): void {
  deleteTarget.value = person
  showDeleteModal.value = true
}

async function executeDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleteBusy.value = true
  try {
    await deleteOutsourcePerson(deleteTarget.value.id)
    showDeleteModal.value = false
    deleteTarget.value = null
    await load()
  } catch (e: unknown) {
    // keep modal open with generic error
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
  <UDashboardPanel id="outsource-person-list">
    <template #header>
      <UDashboardNavbar title="Outsource persons">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="can('outsource_person.create')"
            color="primary"
            size="sm"
            icon="i-lucide-plus"
            @click="openCreate"
          >
            Add person
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
          <div class="flex items-center gap-2 text-sm text-muted">
            <UIcon name="i-lucide-users" class="size-4 shrink-0" />
            <span><strong class="text-highlighted font-semibold">{{ meta.total }}</strong> outsource person{{ meta.total !== 1 ? 's' : '' }}</span>
          </div>

          <DataTableToolbar
            v-model:search="searchInput"
            v-model:status="statusTab"
            v-model:per-page="filters.per_page"
            search-placeholder="Search name or code…"
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
                @update:model-value="(v: unknown) => { filters.city_id = fromSelectId(v); onCityChange() }"
              />
              <USelect
                :model-value="toSelectId(filters.store_id)"
                :items="[{ label: 'All stores', value: ALL }, ...stores.map(s => ({ label: s.name, value: String(s.id) }))]"
                value-key="value"
                class="w-36"
                :disabled="!filters.city_id"
                @update:model-value="(v: unknown) => { filters.store_id = fromSelectId(v) }"
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
            empty-icon="i-lucide-user-x"
            empty-message="No outsource persons found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Create / Edit modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showFormModal" :title="formMode === 'create' ? 'Add outsource person' : 'Edit outsource person'">
    <template #body>
      <div class="space-y-4">
        <UFormField label="Name" required>
          <UInput v-model="form.name" placeholder="Full name" class="w-full" />
        </UFormField>

        <UFormField label="City">
          <USelect
            :model-value="toSelectId(form.city_id)"
            :items="[{ label: 'No city', value: ALL }, ...formCities.map(c => ({ label: c.name, value: String(c.id) }))]"
            value-key="value"
            class="w-full"
            @update:model-value="(v: unknown) => { form.city_id = fromSelectId(v); onFormCityChange() }"
          />
        </UFormField>

        <UFormField label="Store">
          <USelect
            :model-value="toSelectId(form.store_id)"
            :items="[{ label: 'No store', value: ALL }, ...formModalStores.map(s => ({ label: s.name, value: String(s.id) }))]"
            value-key="value"
            class="w-full"
            :disabled="!form.city_id"
            @update:model-value="(v: unknown) => { form.store_id = fromSelectId(v) }"
          />
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
          {{ formMode === 'create' ? 'Add person' : 'Save changes' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
  <UModal v-model:open="showDeleteModal" title="Delete outsource person">
    <template #body>
      <p class="text-sm text-muted">
        Are you sure you want to delete
        <strong class="text-highlighted">{{ deleteTarget?.name }}</strong>?
        This action cannot be undone.
      </p>
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
