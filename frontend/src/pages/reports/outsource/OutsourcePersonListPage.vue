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
  fetchOutsourcePersons,
  createOutsourcePerson,
  updateOutsourcePerson,
  toggleOutsourcePersonStatus,
  deleteOutsourcePerson,
  type OutsourcePersonRow,
  type OutsourcePersonFilters,
} from '../../../services/outsourcePersonApi'
import { fetchOutsourceCities, fetchOutsourceStores, formatCityCabangLabel } from '../../../services/outsourceService'
import {
  fetchWorkLocationPins,
  type WorkLocationPinRow,
} from '../../../services/outsourceWorkLocationApi'
import DataTableToolbar from '../../../components/DataTableToolbar.vue'
import DataTable from '../../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../../utils/dataTable'

const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()
const { can } = usePermission()
const toast = useAppToast()

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
  { id: 'outsource_code', label: 'Code'         },
  { id: 'name',           label: 'Name'         },
  { id: 'city',           label: 'City/Cabang'  },
  { id: 'pins',           label: 'Pins'         },
  { id: 'status',         label: 'Status'       },
  { id: 'created_at',     label: 'Joined'       },
  { id: 'actions',        label: 'Actions'      },
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
const formPins = ref<WorkLocationPinRow[]>([])
const showPassword = ref(false)

const form = reactive({
  name:     '',
  password: '',
  city_id:  '' as string,
  store_id: '' as string,
  store_ids: [] as number[],
  pin_ids:  [] as number[],
})

const formCities = ref<{ id: number; name: string }[]>([])
const selectedStores = ref<{ id: number; name: string; city_id: number | null; city_name: string | null }[]>([])

// ── Modal — delete confirm ────────────────────────────────────────────────────
const showDeleteModal  = ref(false)
const deleteTarget     = ref<OutsourcePersonRow | null>(null)
const deleteBusy       = ref(false)

// ── Modal — view pins ─────────────────────────────────────────────────────────
const showPinsModal = ref(false)
const pinsTarget = ref<OutsourcePersonRow | null>(null)
const pinsSections = ref<{
  storeId: number
  title: string
  pins: WorkLocationPinRow[]
}[]>([])
const pinsLoading = ref(false)
const pinsError = ref('')
const pinsScopeLabel = ref('')

const pinsTotalCount = computed(() =>
  pinsSections.value.reduce((sum, section) => sum + section.pins.length, 0),
)

function formatPinRadius(meters: number | null | undefined): string {
  if (meters == null) return '150 m'
  if (meters >= 1000) return `${meters / 1000} km`
  return `${meters} m`
}

function personStores(person: OutsourcePersonRow): { id: number; name: string; city?: { id: number; name: string } | null }[] {
  if (person.stores?.length) return person.stores
  if (person.store) {
    return [{ id: person.store.id, name: person.store.name, city: person.city }]
  }
  return []
}

function formatStoresLabel(person: OutsourcePersonRow): string {
  const stores = personStores(person)
  if (!stores.length) return ''
  return stores.map((s) => formatCityCabangLabel(s.city?.name, s.name) || s.name).join(', ')
}

async function openPins(person: OutsourcePersonRow): Promise<void> {
  const stores = personStores(person)
  if (!stores.length) return

  pinsTarget.value = person
  pinsSections.value = []
  pinsError.value = ''
  pinsScopeLabel.value = ''
  showPinsModal.value = true
  pinsLoading.value = true

  try {
    const allowIds = person.pin_ids ?? []
    const allowed = new Set(allowIds)
    const sections: {
      storeId: number
      title: string
      pins: WorkLocationPinRow[]
    }[] = []

    for (const store of stores) {
      const allPins = await fetchWorkLocationPins(store.id)
      const active = allPins.filter(p => p.status === 'active')
      const storePins = allowIds.length === 0
        ? active
        : active.filter(p => allowed.has(p.id))
      sections.push({
        storeId: store.id,
        title: formatCityCabangLabel(store.city?.name, store.name) || store.name,
        pins: storePins,
      })
    }

    pinsSections.value = sections
    const totalPins = sections.reduce((sum, s) => sum + s.pins.length, 0)
    pinsScopeLabel.value = allowIds.length === 0
      ? `All active pins · ${stores.length} cabang`
      : `${totalPins} allowed pin${totalPins === 1 ? '' : 's'} · ${stores.length} cabang`
  }
  catch (e: unknown) {
    pinsError.value = e instanceof Error ? e.message : 'Failed to load pins.'
  }
  finally {
    pinsLoading.value = false
  }
}

async function reloadFormPins(): Promise<void> {
  formPins.value = []
  if (!selectedStores.value.length) return
  try {
    const rows: WorkLocationPinRow[] = []
    for (const store of selectedStores.value) {
      const pins = (await fetchWorkLocationPins(store.id)).filter(p => p.status === 'active')
      rows.push(...pins)
    }
    formPins.value = rows
    const validIds = new Set(rows.map(p => p.id))
    form.pin_ids = form.pin_ids.filter(id => validIds.has(id))
  }
  catch {
    formPins.value = []
  }
}

const formPinSections = computed(() => {
  return selectedStores.value.map((store) => {
    const pins = formPins.value.filter(p => p.work_location_id === store.id)
    const selectedCount = pins.filter(p => form.pin_ids.includes(p.id)).length
    return {
      storeId: store.id,
      title: formatCityCabangLabel(store.city_name, store.name) || store.name,
      pins,
      selectedCount,
      allSelected: pins.length > 0 && selectedCount === pins.length,
      needsSelection: pins.length > 0 && selectedCount === 0,
    }
  })
})

const pinSelectionIncomplete = computed(() =>
  formPinSections.value.some(section => section.needsSelection),
)

const availableFormStores = computed(() =>
  formModalStores.value.filter(s => !selectedStores.value.some(sel => sel.id === s.id)),
)

const selectedStoreAlreadyAssigned = computed(() => {
  if (!form.store_id) return false
  return selectedStores.value.some(s => String(s.id) === form.store_id)
})

function toggleFormPin(pinId: number): void {
  if (form.pin_ids.includes(pinId)) {
    form.pin_ids = form.pin_ids.filter(id => id !== pinId)
  } else {
    form.pin_ids = [...form.pin_ids, pinId]
  }
}

function toggleSectionPins(storeId: number, selectAll: boolean): void {
  const sectionPinIds = formPins.value
    .filter(p => p.work_location_id === storeId)
    .map(p => p.id)
  if (selectAll) {
    form.pin_ids = [...new Set([...form.pin_ids, ...sectionPinIds])]
  } else {
    const drop = new Set(sectionPinIds)
    form.pin_ids = form.pin_ids.filter(id => !drop.has(id))
  }
}

function addSelectedCabang(): void {
  if (!form.store_id) return
  const store = formModalStores.value.find(s => String(s.id) === form.store_id)
    ?? availableFormStores.value.find(s => String(s.id) === form.store_id)
  if (!store) {
    formError.value = 'Cabang tidak ditemukan.'
    return
  }
  if (selectedStores.value.some(s => s.id === store.id)) {
    formError.value = `Cabang “${store.name}” sudah di-assign. Pilih cabang lain.`
    form.store_id = ''
    return
  }
  const city = formCities.value.find(c => String(c.id) === form.city_id)
  selectedStores.value.push({
    id: store.id,
    name: store.name,
    city_id: city?.id ?? null,
    city_name: city?.name ?? null,
  })
  form.store_ids = selectedStores.value.map(s => s.id)
  formError.value = ''
  // Clear picker so it never looks like a prefilled assignment editor.
  form.city_id = ''
  form.store_id = ''
  formModalStores.value = []
  void reloadFormPins()
}

function removeSelectedCabang(storeId: number): void {
  selectedStores.value = selectedStores.value.filter(s => s.id !== storeId)
  form.store_ids = selectedStores.value.map(s => s.id)
  void reloadFormPins()
}

// ── Columns ───────────────────────────────────────────────────────────────────
const columns = computed<TableColumn<OutsourcePersonRow>[]>(() => [
  {
    accessorKey: 'outsource_code',
    header: ({ column }) => createSortableHeader(column, 'Code'),
    cell: ({ row }) => createTruncatedText(
      row.original.outsource_code,
      'font-mono text-xs text-[var(--ui-text-muted)] tracking-tight',
    ),
  },
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
    cell: ({ row }) => createTruncatedText(row.original.name, 'text-sm font-medium'),
  },
  {
    id: 'city',
    header: 'City/Cabang',
    accessorFn: (row) => formatStoresLabel(row),
    cell: ({ row }) => createTruncatedText(formatStoresLabel(row.original) || null),
  },
  {
    id: 'pins',
    header: 'Pins',
    cell: ({ row }) => {
      const person = row.original
      const stores = personStores(person)
      if (!stores.length) {
        return h('span', { class: 'text-[var(--ui-text-dimmed)] text-xs' }, '—')
      }
      const ids = person.pin_ids ?? []
      const label = ids.length === 0
        ? `All (${stores.length})`
        : String(ids.length)
      const title = ids.length === 0
        ? `View all pins across ${stores.length} cabang`
        : `View ${ids.length} allowed pin${ids.length === 1 ? '' : 's'}`

      return h(resolveComponent('UButton'), {
        size: 'xs',
        color: 'primary',
        variant: 'soft',
        icon: 'i-lucide-map-pin',
        label,
        title,
        onClick: (e: Event) => {
          e.stopPropagation()
          openPins(person)
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
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Joined'),
    cell: ({ row }) => createTruncatedText(row.original.created_at, 'text-sm tabular-nums'),
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
  try {
    stores.value = await fetchOutsourceStores(Number(filters.city_id))
    // OS: usually 1 cabang per city — no need to send store_id; city filter is enough.
    // Keep stores only so multi-cabang cities can still refine.
  } catch {
    stores.value = []
  }
}

const showCabangFilter = computed(() =>
  Boolean(filters.city_id) && stores.value.length > 1,
)

async function onFormCityChange(): Promise<void> {
  formModalStores.value = []
  form.store_id = ''
  formError.value = ''
  if (!form.city_id) return
  try {
    const raw = await fetchOutsourceStores(Number(form.city_id))
    formModalStores.value = raw.map(s => ({ id: s.id, name: s.name }))
    // OS model: one cabang per city (name ≈ city). Auto-pick when unambiguous.
    const city = formCities.value.find(c => String(c.id) === form.city_id)
    const available = formModalStores.value.filter(
      s => !selectedStores.value.some(sel => sel.id === s.id),
    )
    const match = city
      ? available.find(s => s.name.toUpperCase() === city.name.toUpperCase())
      : null
    if (match) {
      form.store_id = String(match.id)
    } else if (available.length === 1) {
      form.store_id = String(available[0].id)
    }
    // OS: 1 cabang/kota — auto-add so Save never creates an unassigned person by accident.
    if (form.store_id) {
      addSelectedCabang()
    }
  } catch {
    formModalStores.value = []
  }
}

const formAddCabangHint = computed(() => {
  if (!form.city_id) return ''
  if (availableFormStores.value.length === 0) {
    return formModalStores.value.length === 0
      ? 'Belum ada cabang untuk kota ini. Buat dulu di Work locations.'
      : 'Semua cabang kota ini sudah di-assign.'
  }
  if (form.store_id) {
    const store = availableFormStores.value.find(s => String(s.id) === form.store_id)
    if (store) {
      const city = formCities.value.find(c => String(c.id) === form.city_id)
      return formatCityCabangLabel(city?.name, store.name) || store.name
    }
  }
  if (availableFormStores.value.length > 1) {
    return 'Beberapa cabang tersedia — pilih salah satu.'
  }
  return ''
})

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
  form.password   = '123456'
  form.city_id    = ''
  form.store_id   = ''
  form.store_ids  = []
  form.pin_ids    = []
  formPins.value  = []
  formModalStores.value = []
  selectedStores.value = []
  formError.value = ''
  showPassword.value = false
  formCities.value = cities.value
  showFormModal.value = true
}

async function openEdit(person: OutsourcePersonRow): Promise<void> {
  formMode.value  = 'edit'
  editingId.value = person.id
  form.name       = person.name
  form.password   = ''
  form.city_id    = ''
  form.store_id   = ''
  const hadExplicitPins = (person.pin_ids ?? []).length > 0
  form.pin_ids    = [...(person.pin_ids ?? [])]
  formError.value = ''
  showPassword.value = false
  formCities.value = cities.value
  showFormModal.value = true

  const stores = personStores(person)
  selectedStores.value = stores.map(s => ({
    id: s.id,
    name: s.name,
    city_id: s.city?.id ?? null,
    city_name: s.city?.name ?? null,
  }))
  form.store_ids = selectedStores.value.map(s => s.id)
  formModalStores.value = []
  await reloadFormPins()
  // Legacy "all pins" (empty allowlist) → preselect so Save remains usable for name-only edits.
  if (!hadExplicitPins && formPins.value.length > 0) {
    form.pin_ids = formPins.value.map(p => p.id)
  }
}

async function submitForm(): Promise<void> {
  if (!form.name.trim()) { formError.value = 'Name is required.'; return }
  if (selectedStores.value.length === 0) {
    formError.value = 'Tambah minimal 1 kota/cabang ke daftar sebelum Save.'
    return
  }
  const pin = form.password.trim()
  if (formMode.value === 'create' && pin === '') {
    form.password = '123456'
  }
  const passwordToSend = form.password.trim()
  if (passwordToSend !== '' && !/^\d{4,8}$/.test(passwordToSend)) {
    formError.value = 'PIN must be 4–8 digits.'
    return
  }
  if (pinSelectionIncomplete.value) {
    const missing = formPinSections.value
      .filter(s => s.needsSelection)
      .map(s => s.title)
    formError.value = missing.length
      ? `Checklist minimal 1 pin untuk: ${missing.join(', ')}.`
      : 'Checklist minimal 1 pin untuk setiap cabang yang punya pin.'
    return
  }
  formBusy.value = true
  formError.value = ''
  try {
    const payload = {
      name: form.name.trim(),
      store_ids: form.store_ids,
      pin_ids: form.pin_ids,
      password: passwordToSend || null,
    }
    if (formMode.value === 'create') {
      const created = await createOutsourcePerson({
        ...payload,
        password: passwordToSend || '123456',
      })
      toast.success('Person created', 'Outsource person saved with login PIN.')
      // Clear location filters so the new person is visible even if another city was filtered.
      filters.city_id = ''
      filters.store_id = ''
      stores.value = []
      searchInput.value = created.data.name
      filters.search = created.data.name
    } else if (editingId.value !== null) {
      await updateOutsourcePerson(editingId.value, payload)
      toast.success('Person updated')
    }
    showFormModal.value = false
    meta.current_page = 1
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
    toast.success(res.data.status === 'active' ? 'Person activated' : 'Person deactivated')
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to update person status.')
    await load()
  }
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
    toast.success('Person deleted')
    await load()
  } catch (e: unknown) {
    toast.fromError(e, 'Unable to delete this person.')
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
                :items="[{ label: 'All cities/cabangs', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value"
                class="w-44"
                @update:model-value="(v: unknown) => { filters.city_id = fromSelectId(v); onCityChange() }"
              />
              <USelect
                v-if="showCabangFilter"
                :model-value="toSelectId(filters.store_id)"
                :items="[{ label: 'All cabangs', value: ALL }, ...stores.map(s => ({ label: s.name, value: String(s.id) }))]"
                value-key="value"
                class="w-36"
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

        <!-- Current assignment (source of truth) -->
        <div class="space-y-2 rounded-md border border-default p-3">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="text-sm font-medium text-highlighted">Assigned cabangs</p>
              <p class="text-xs text-muted">
                Cabang yang sudah terpasang ke person ini. Hapus dengan tombol ×.
              </p>
            </div>
            <UBadge
              v-if="selectedStores.length"
              size="sm"
              color="neutral"
              variant="subtle"
            >
              {{ selectedStores.length }}
            </UBadge>
          </div>

          <div
            v-if="!selectedStores.length"
            class="rounded-md border border-dashed border-default px-3 py-2.5 text-sm text-muted"
          >
            Belum ada cabang. Tambahkan lewat form di bawah.
          </div>
          <div v-else class="flex flex-wrap gap-2">
            <UBadge
              v-for="store in selectedStores"
              :key="store.id"
              color="primary"
              variant="subtle"
              class="gap-1"
            >
              {{ formatCityCabangLabel(store.city_name, store.name) || store.name }}
              <button
                type="button"
                class="ml-1 opacity-70 hover:opacity-100"
                aria-label="Remove cabang"
                @click="removeSelectedCabang(store.id)"
              >
                ×
              </button>
            </UBadge>
          </div>
        </div>

        <!-- Add action (not a prefill of existing assignment) -->
        <div class="space-y-3 rounded-md border border-dashed border-default p-3">
          <div>
            <p class="text-sm font-medium text-highlighted">Tambah kota/cabang</p>
            <p class="text-xs text-muted">
              Pilih kota lalu klik <strong>Tambah ke daftar</strong>.
              Cabang di-auto (1 cabang per kota). Field ini kosong — bukan autofill yang sudah assigned.
            </p>
          </div>

          <UFormField label="Kota (untuk menambah)">
            <div class="flex gap-2">
              <USelect
                :model-value="toSelectId(form.city_id)"
                :items="[{ label: 'Pilih kota…', value: ALL }, ...formCities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value"
                class="min-w-0 flex-1"
                @update:model-value="(v: unknown) => { form.city_id = fromSelectId(v); onFormCityChange() }"
              />
              <UButton
                color="primary"
                variant="soft"
                icon="i-lucide-plus"
                :disabled="!form.store_id || selectedStoreAlreadyAssigned"
                @click="addSelectedCabang"
              >
                Tambah ke daftar
              </UButton>
            </div>
            <p v-if="form.city_id && formAddCabangHint" class="mt-1 text-xs text-muted">
              <template v-if="form.store_id && availableFormStores.length > 0">
                Cabang: <span class="text-highlighted">{{ formAddCabangHint }}</span>
              </template>
              <template v-else>{{ formAddCabangHint }}</template>
            </p>
            <p v-if="selectedStoreAlreadyAssigned" class="mt-1 text-xs text-error">
              Cabang ini sudah di-assign.
            </p>
          </UFormField>

          <UFormField v-if="form.city_id && availableFormStores.length > 1" label="Cabang">
            <USelect
              :model-value="toSelectId(form.store_id)"
              :items="[
                { label: 'Pilih cabang…', value: ALL },
                ...availableFormStores.map(s => ({ label: s.name, value: String(s.id) })),
              ]"
              value-key="value"
              class="w-full"
              @update:model-value="(v: unknown) => { form.store_id = fromSelectId(v); formError = '' }"
            />
          </UFormField>
        </div>

        <UFormField
          :label="formMode === 'create' ? 'PIN' : 'New PIN'"
          :hint="formMode === 'edit' ? 'Leave blank to keep current PIN' : 'Numeric PIN for outsource login (default 123456)'"
          :required="formMode === 'create'"
        >
          <UInput
            :model-value="form.password"
            :type="showPassword ? 'text' : 'password'"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="8"
            autocomplete="new-password"
            placeholder="123456"
            class="w-full"
            :ui="{ trailing: 'pe-1' }"
            @update:model-value="(v: string | number) => { form.password = String(v ?? '').replace(/\D/g, '').slice(0, 8) }"
          >
            <template #trailing>
              <UButton
                color="neutral"
                variant="link"
                size="sm"
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPassword ? 'Hide PIN' : 'Show PIN'"
                @click="showPassword = !showPassword"
              />
            </template>
          </UInput>
        </UFormField>

        <UFormField
          v-if="selectedStores.length"
          label="Allowed pins"
          required
          hint="Wajib checklist minimal 1 pin per cabang sebelum Save"
        >
          <div v-if="formPins.length === 0" class="text-sm text-muted">
            No active pins on assigned cabangs yet. Add pins from Work locations.
          </div>
          <div v-else class="max-h-72 space-y-3 overflow-y-auto rounded-md border border-default p-2">
            <section
              v-for="section in formPinSections"
              :key="section.storeId"
              class="overflow-hidden rounded-md border"
              :class="section.needsSelection ? 'border-error' : 'border-default'"
            >
              <div class="flex items-center justify-between gap-2 border-b border-default bg-elevated/50 px-3 py-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium text-highlighted">{{ section.title }}</p>
                  <p class="text-[11px]" :class="section.needsSelection ? 'text-error' : 'text-muted'">
                    <template v-if="section.needsSelection">Pilih minimal 1 pin</template>
                    <template v-else>
                      {{ section.pins.length }} pin{{ section.pins.length === 1 ? '' : 's' }}
                      <span v-if="section.selectedCount"> · {{ section.selectedCount }} selected</span>
                    </template>
                  </p>
                </div>
                <UButton
                  v-if="section.pins.length"
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  :disabled="formBusy"
                  @click="toggleSectionPins(section.storeId, !section.allSelected)"
                >
                  {{ section.allSelected ? 'Clear' : 'Select all' }}
                </UButton>
              </div>

              <div v-if="section.pins.length === 0" class="px-3 py-2.5 text-xs text-muted">
                No active pins in this cabang.
              </div>
              <div v-else class="space-y-2 px-3 py-2.5">
                <label
                  v-for="pin in section.pins"
                  :key="pin.id"
                  class="flex cursor-pointer items-start gap-2 text-sm"
                >
                  <UCheckbox
                    :model-value="form.pin_ids.includes(pin.id)"
                    @update:model-value="() => toggleFormPin(pin.id)"
                  />
                  <span>
                    <span class="font-medium">{{ pin.name }}</span>
                    <span class="text-muted">
                      · {{ pin.radius_meters != null && pin.radius_meters >= 1000
                        ? `${pin.radius_meters / 1000} km`
                        : `${pin.radius_meters ?? 150} m` }}
                    </span>
                    <span v-if="pin.address" class="text-muted"> — {{ pin.address }}</span>
                  </span>
                </label>
              </div>
            </section>
          </div>
        </UFormField>

        <UAlert v-if="formError" color="error" variant="subtle" :description="formError" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="formBusy" @click="showFormModal = false">
          Cancel
        </UButton>
        <UButton
          color="primary"
          :loading="formBusy"
          :disabled="formBusy || pinSelectionIncomplete"
          @click="submitForm"
        >
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

  <!-- ── View pins modal ────────────────────────────────────────────────────── -->
  <UModal
    v-model:open="showPinsModal"
    :title="`Pins — ${pinsTarget?.name || 'outsource'}`"
  >
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          Allowed check-in locations for
          <strong class="text-highlighted">{{ pinsTarget?.name }}</strong>
          <template v-if="pinsTarget">
            at <strong class="text-highlighted">{{ formatStoresLabel(pinsTarget) }}</strong>
          </template>.
          <span v-if="pinsScopeLabel" class="mt-1 block text-xs">{{ pinsScopeLabel }}</span>
        </p>

        <div v-if="pinsLoading" class="text-sm text-muted">Loading…</div>
        <UAlert v-else-if="pinsError" color="error" variant="subtle" :description="pinsError" />
        <div
          v-else-if="pinsSections.length === 0 || pinsTotalCount === 0"
          class="rounded-md border border-dashed border-default p-3 text-sm text-muted"
        >
          No pins available for this person.
        </div>
        <div v-else class="max-h-80 space-y-3 overflow-y-auto rounded-md border border-default p-2">
          <section
            v-for="section in pinsSections"
            :key="section.storeId"
            class="overflow-hidden rounded-md border border-default"
          >
            <div class="flex items-center justify-between gap-2 border-b border-default bg-elevated/50 px-3 py-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-highlighted">{{ section.title }}</p>
                <p class="text-[11px] text-muted">
                  {{ section.pins.length }} pin{{ section.pins.length === 1 ? '' : 's' }}
                </p>
              </div>
            </div>

            <div v-if="section.pins.length === 0" class="px-3 py-2.5 text-xs text-muted">
              No allowed pins in this cabang.
            </div>
            <ul v-else class="divide-y divide-default">
              <li
                v-for="pin in section.pins"
                :key="pin.id"
                class="flex items-start justify-between gap-3 px-3 py-2.5"
              >
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium">{{ pin.name }}</p>
                  <p v-if="pin.address" class="truncate text-xs text-muted">{{ pin.address }}</p>
                  <p
                    v-if="pin.latitude != null && pin.longitude != null"
                    class="truncate font-mono text-[11px] text-muted"
                  >
                    {{ pin.latitude }}, {{ pin.longitude }}
                  </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                  <UBadge size="sm" variant="subtle" color="neutral">
                    {{ formatPinRadius(pin.radius_meters) }}
                  </UBadge>
                  <UBadge
                    size="sm"
                    variant="subtle"
                    :color="pin.status === 'active' ? 'success' : 'error'"
                  >
                    {{ pin.status }}
                  </UBadge>
                </div>
              </li>
            </ul>
          </section>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end">
        <UButton color="neutral" variant="outline" @click="showPinsModal = false">Close</UButton>
      </div>
    </template>
  </UModal>
</template>
