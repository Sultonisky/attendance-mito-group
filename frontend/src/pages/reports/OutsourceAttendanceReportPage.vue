<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { watchDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { fetchOutsourceAttendanceReport } from '../../services/reports/outsourceAttendanceReportApi'
import { fetchOutsourceCities, fetchOutsourceStores, fetchOutsourceOutsources } from '../../services/outsourceService'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { OutsourceAttendanceReportRow, OutsourceAttendanceReportFilters } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

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
  { label: 'Late', value: 'late' },
  { label: 'Incomplete', value: 'incomplete' },
  { label: 'Absent', value: 'absent' },
]

const hideableColumns = [
  { id: 'attendance_date', label: 'Date' },
  { id: 'outsource', label: 'Outsource' },
  { id: 'city', label: 'City' },
  { id: 'store', label: 'Store' },
  { id: 'check_in_at', label: 'Clock In' },
  { id: 'check_out_at', label: 'Clock Out' },
  { id: 'duration_minutes', label: 'Duration' },
  { id: 'status', label: 'Status' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present: 'success',
  late: 'warning',
  incomplete: 'error',
  absent: 'neutral',
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
      return o ? `${o.name}${o.code ? ` (${o.code})` : ''}` : '—'
    },
  },
  {
    id: 'city',
    header: ({ column }) => createSortableHeader(column, 'City'),
    accessorFn: row => row.city?.name ?? '',
    cell: ({ row }) => row.original.city?.name ?? '—',
  },
  {
    id: 'store',
    header: ({ column }) => createSortableHeader(column, 'Store'),
    accessorFn: row => row.store?.name ?? '',
    cell: ({ row }) => row.original.store?.name ?? '—',
  },
  {
    accessorKey: 'check_in_at',
    header: ({ column }) => createSortableHeader(column, 'Clock In'),
    cell: ({ row }) => {
      const v = row.getValue<string | null>('check_in_at')
      return v ? new Date(v + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
    },
  },
  {
    accessorKey: 'check_out_at',
    header: ({ column }) => createSortableHeader(column, 'Clock Out'),
    cell: ({ row }) => {
      const v = row.getValue<string | null>('check_out_at')
      return v ? new Date(v + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
    },
  },
  {
    accessorKey: 'duration_minutes',
    header: ({ column }) => createSortableHeader(column, 'Duration'),
    cell: ({ row }) => {
      const mins = row.getValue<number | null>('duration_minutes')
      if (!mins) return '—'
      const hours = Math.floor(mins / 60)
      const m = mins % 60
      return hours > 0 ? `${hours}h ${m}m` : `${m}m`
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
            filename="outsource-attendance-report"
            :loading="loading"
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
                :items="[{ label: 'All stores', value: ALL }, ...stores.map(s => ({ label: s.name, value: String(s.id) }))]"
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
</template>
