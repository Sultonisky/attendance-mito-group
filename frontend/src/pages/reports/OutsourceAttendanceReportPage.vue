<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, resolveComponent } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import { useReportPage } from '../../composables/useReportPage'
import { fetchOutsourceAttendanceReport } from '../../services/reports/outsourceAttendanceReportApi'
import { fetchOutsourceCities, fetchOutsourceStores, fetchOutsourceOutsources } from '../../services/outsourceService'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import type { OutsourceAttendanceReportRow, OutsourceAttendanceReportFilters, ReportSortOption } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route  = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<OutsourceAttendanceReportRow[]>([])

// Cascading lookup data
const cities     = ref<{ id: number; name: string }[]>([])
const stores     = ref<{ id: number; name: string; city_id: number }[]>([])
const outsources = ref<{ id: number; name: string; outsource_code: string }[]>([])

const filters = reactive<OutsourceAttendanceReportFilters>({
  ...defaultReportDates(),
  city_id:      '',
  store_id:     '',
  outsource_id: '',
  status:       '',
  search:       '',
  per_page:     25,
  sort:         'attendance_date',
  direction:    'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Attendance Date', value: 'attendance_date' },
  { label: 'Outsource Name',  value: 'outsource_name'  },
  { label: 'Status',          value: 'status'          },
  { label: 'Check In',        value: 'check_in_at'     },
  { label: 'Check Out',       value: 'check_out_at'    },
  { label: 'Duration',        value: 'duration_minutes'},
  { label: 'Created At',      value: 'created_at'      },
]

const statusOptions = [
  { label: 'All statuses', value: ''          },
  { label: 'Present',      value: 'present'   },
  { label: 'Late',         value: 'late'      },
  { label: 'Incomplete',   value: 'incomplete'},
  { label: 'Absent',       value: 'absent'    },
]

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  present:    'success',
  late:       'warning',
  incomplete: 'error',
  absent:     'neutral',
}

const UBadge = resolveComponent('UBadge')

const columns = computed<TableColumn<OutsourceAttendanceReportRow>[]>(() => [
  { accessorKey: 'attendance_date', header: 'Date' },
  {
    id: 'outsource', header: 'Outsource',
    cell: ({ row }) => {
      const o = row.original.outsource
      return o ? `${o.name}${o.code ? ` (${o.code})` : ''}` : '—'
    },
  },
  {
    id: 'city', header: 'City',
    cell: ({ row }) => row.original.city?.name ?? '—',
  },
  {
    id: 'store', header: 'Store',
    cell: ({ row }) => row.original.store?.name ?? '—',
  },
  {
    accessorKey: 'check_in_at', header: 'Clock In',
    cell: ({ row }) => {
      const v = row.getValue<string | null>('check_in_at')
      return v ? new Date(v + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
    },
  },
  {
    accessorKey: 'check_out_at', header: 'Clock Out',
    cell: ({ row }) => {
      const v = row.getValue<string | null>('check_out_at')
      return v ? new Date(v + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
    },
  },
  {
    accessorKey: 'duration_minutes', header: 'Duration',
    cell: ({ row }) => {
      const mins = row.getValue<number | null>('duration_minutes')
      if (!mins) return '—'
      const h = Math.floor(mins / 60)
      const m = mins % 60
      return h > 0 ? `${h}h ${m}m` : `${m}m`
    },
  },
  {
    accessorKey: 'status', header: 'Status',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return h(UBadge, { color: statusColor[s] ?? 'neutral', variant: 'subtle', class: 'capitalize' }, () => s)
    },
  },
])

// ── Lookups ───────────────────────────────────────────────────────────
const ALL = '__all__'

function toSelectId(raw: string): string {
  return raw === '' ? ALL : raw
}

function fromSelectId(raw: unknown): string {
  const v = String(raw ?? '')
  return v === ALL ? '' : v
}

async function loadCities() {
  try { cities.value = await fetchOutsourceCities() } catch { /* ignore */ }
}

async function onCityChange() {
  stores.value = []; outsources.value = []
  filters.store_id = ''; filters.outsource_id = ''
  if (!filters.city_id) return
  try { stores.value = await fetchOutsourceStores(Number(filters.city_id)) } catch { stores.value = [] }
}

async function onStoreChange() {
  outsources.value = []; filters.outsource_id = ''
  if (!filters.store_id) return
  try { outsources.value = await fetchOutsourceOutsources(Number(filters.store_id)) } catch { outsources.value = [] }
}

// ── Data fetch ────────────────────────────────────────────────────────
async function load(): Promise<void> {
  loading.value = true; error.value = ''
  try {
    const res = await fetchOutsourceAttendanceReport({
      from:         filters.from,
      to:           filters.to,
      city_id:      filters.city_id      || null,
      store_id:     filters.store_id     || null,
      outsource_id: filters.outsource_id || null,
      status:       filters.status       || null,
      search:       filters.search       || null,
      per_page:     filters.per_page,
      sort:         filters.sort,
      direction:    filters.direction,
      page:         meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load outsource attendance report. Please try again.')
  } finally { loading.value = false }
}

function resetFilters() {
  const def = defaultReportDates()
  filters.from = def.from; filters.to = def.to
  filters.city_id = ''; filters.store_id = ''; filters.outsource_id = ''
  filters.status = ''; filters.search = ''
  filters.per_page = 25; filters.sort = 'attendance_date'; filters.direction = 'desc'
  stores.value = []; outsources.value = []
  meta.current_page = 1
  load()
}

onMounted(() => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to   === 'string') filters.to   = route.query.to
  loadCities()
  load()
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

      <!-- Filters toolbar -->
      <UDashboardToolbar>
        <template #left>
          <form class="flex flex-wrap items-end gap-3" @submit.prevent="() => { meta.current_page = 1; load() }">
            <!-- Date range -->
            <UFormField label="From">
              <UInput type="date" :model-value="filters.from" class="w-36"
                @update:model-value="filters.from = String($event ?? '')" />
            </UFormField>
            <UFormField label="To">
              <UInput type="date" :model-value="filters.to" class="w-36"
                @update:model-value="filters.to = String($event ?? '')" />
            </UFormField>

            <!-- Cascading: City → Store → Outsource -->
            <!-- NOTE: reka-ui SelectItem forbids value="" (reserved for "clear"), -->
            <!-- so the "All" option uses the '__all__' sentinel mapped back to ''. -->
            <UFormField label="City">
              <USelect
                :model-value="toSelectId(filters.city_id)"
                :items="[{ label: 'All cities', value: ALL }, ...cities.map(c => ({ label: c.name, value: String(c.id) }))]"
                value-key="value" class="w-36"
                @update:model-value="(v: unknown) => { filters.city_id = fromSelectId(v); onCityChange() }"
              />
            </UFormField>
            <UFormField label="Store">
              <USelect
                :model-value="toSelectId(filters.store_id)"
                :items="[{ label: 'All stores', value: ALL }, ...stores.map(s => ({ label: s.name, value: String(s.id) }))]"
                value-key="value" class="w-36" :disabled="!filters.city_id"
                @update:model-value="(v: unknown) => { filters.store_id = fromSelectId(v); onStoreChange() }"
              />
            </UFormField>
            <UFormField label="Outsource">
              <USelect
                :model-value="toSelectId(filters.outsource_id)"
                :items="[{ label: 'All outsources', value: ALL }, ...outsources.map(o => ({ label: `${o.name} (${o.outsource_code})`, value: String(o.id) }))]"
                value-key="value" class="w-48" :disabled="!filters.store_id"
                @update:model-value="filters.outsource_id = fromSelectId($event)"
              />
            </UFormField>

            <!-- Status + Search -->
            <UFormField label="Status">
              <USelect
                :model-value="filters.status === '' ? ALL : filters.status"
                :items="statusOptions.map(o => ({ ...o, value: o.value === '' ? ALL : o.value }))"
                value-key="value" class="w-36"
                @update:model-value="filters.status = fromSelectId($event)"
              />
            </UFormField>
            <UFormField label="Search">
              <UInput
                :model-value="filters.search" type="text"
                placeholder="Name or code…" class="w-44"
                @update:model-value="filters.search = String($event ?? '')"
              />
            </UFormField>

            <!-- Sort -->
            <UFormField label="Sort">
              <USelect
                :model-value="filters.sort"
                :items="sortOptions" value-key="value" class="w-40"
                @update:model-value="filters.sort = String($event ?? '')"
              />
            </UFormField>
            <UFormField label="Direction">
              <USelect
                :model-value="filters.direction"
                :items="[{ label: 'Descending', value: 'desc' }, { label: 'Ascending', value: 'asc' }]"
                value-key="value" class="w-32"
                @update:model-value="filters.direction = ($event as 'asc' | 'desc')"
              />
            </UFormField>
            <UFormField label="Per page">
              <USelect
                :model-value="filters.per_page"
                :items="[10, 25, 50, 100]" class="w-20"
                @update:model-value="filters.per_page = Number($event)"
              />
            </UFormField>

            <UButton type="submit" color="primary" icon="i-lucide-search" :loading="loading">
              Apply
            </UButton>
          </form>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert"
          title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar
            :total="meta.total" :rows="data"
            filename="outsource-attendance-report" :loading="loading"
          />

          <UTable
            :data="data" :columns="columns" :loading="loading"
            :ui="{
              base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
              thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
              tbody: '[&>tr]:last:[&>td]:border-b-0',
              th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-muted)]',
              td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
            }"
          />

          <div v-if="!loading && !data.length" class="py-16 text-center">
            <UIcon name="i-lucide-briefcase-business" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
            <p class="text-sm text-[var(--ui-text-muted)]">No outsource attendance records found for the selected filters.</p>
          </div>

          <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-[var(--ui-border)] pt-4">
            <p class="text-xs text-[var(--ui-text-muted)]">Page {{ meta.current_page }} of {{ meta.last_page }}</p>
            <UPagination
              :page="meta.current_page" :total="meta.last_page" :items-per-page="1"
              show-edges :disabled="loading"
              @update:page="goToPage($event, load)"
            />
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
