<script setup lang="ts">
import { computed, h, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { sub, eachDayOfInterval, eachWeekOfInterval, eachMonthOfInterval, format } from 'date-fns'
import { VisXYContainer, VisLine, VisArea, VisAxis, VisCrosshair, VisTooltip } from '@unovis/vue'
import { useElementSize, useMediaQuery } from '@vueuse/core'
import type { ColumnFiltersState, RowSelectionState, SortingState, VisibilityState } from '@tanstack/vue-table'
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { useDashboard } from '../composables/useDashboard'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../services/apiClient'
import {
  fetchDashboardAttendanceTrend,
  fetchDashboardKpis,
  fetchDashboardStaffToday,
  fetchSystemHealth,
} from '../services/dashboardApi'
import type {
  DashboardKpiData,
  DashboardStaffRow,
  DashboardTrendPoint,
  SystemHealthSnapshot,
} from '../types/dashboard'
import DashboardKpiCard from '../components/DashboardKpiCard.vue'
import DataTableToolbar from '../components/DataTableToolbar.vue'
import { createSortableHeader, createStatusBadge, UCheckbox, DATA_TABLE_UI } from '../utils/dataTable'
import { useDataTableDisplay } from '../composables/useDataTableDisplay'

const router = useRouter()
const auth = useAuthStore()
const { isNotificationsSlideoverOpen } = useDashboard()

type Period = 'daily' | 'weekly' | 'monthly'
type Range = { start: Date; end: Date }

const range = shallowRef<Range>({ start: sub(new Date(), { days: 14 }), end: new Date() })
const period = ref<Period>('daily')

const periodOptions = [
  { label: 'Daily', value: 'daily' as Period },
  { label: 'Weekly', value: 'weekly' as Period },
  { label: 'Monthly', value: 'monthly' as Period },
]

const addItems: DropdownMenuItem[][] = [[
  { label: 'View attendance', icon: 'i-lucide-calendar-check-2', to: '/dashboard/reports/attendance' },
  { label: 'View leave', icon: 'i-lucide-calendar-off', to: '/dashboard/reports/leave' },
  { label: 'View overtime', icon: 'i-lucide-bar-chart-3', to: '/dashboard/reports/overtime' },
]]

const loading = ref(true)
const error = ref('')
const kpis = ref<DashboardKpiData | null>(null)
const trendPoints = ref<DashboardTrendPoint[]>([])
const tableRows = ref<DashboardStaffRow[]>([])
const systemHealth = ref<SystemHealthSnapshot | null>(null)

const greeting = computed(() => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
})

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? 'Admin')

const attendanceRate = computed(() => {
  if (!kpis.value) return 0
  const total = kpis.value.present + kpis.value.absent + kpis.value.late + kpis.value.on_leave
  return total === 0 ? 0 : Math.round(((kpis.value.present + kpis.value.late) / total) * 100)
})

const periodAverageRate = computed(() => {
  if (!chartData.value.length) return attendanceRate.value
  const sum = chartData.value.reduce((acc, point) => acc + point.value, 0)
  return Math.round(sum / chartData.value.length)
})

function formatDate(dateStr: string): string {
  return new Date(`${dateStr}T00:00:00`).toLocaleDateString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  })
}

function formatRefreshedAt(iso: string | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [kpiResponse, staffResponse, health] = await Promise.all([
      fetchDashboardKpis(),
      fetchDashboardStaffToday(),
      fetchSystemHealth(),
    ])
    kpis.value = kpiResponse.data
    tableRows.value = staffResponse.data
    systemHealth.value = health
    rowSelection.value = {}
    await loadTrend()
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) { await router.push({ name: 'login.admin' }); return }
      if (err.status === 403) { error.value = 'You do not have permission to view the dashboard.'; return }
    }
    error.value = err instanceof TypeError
      ? 'Unable to connect to the API. Check that Laravel is running.'
      : 'Unable to load dashboard data. Please try again.'
  } finally {
    loading.value = false
  }
}

async function loadTrend(): Promise<void> {
  const from = format(range.value.start, 'yyyy-MM-dd')
  const to = format(range.value.end, 'yyyy-MM-dd')
  const response = await fetchDashboardAttendanceTrend(from, to)
  trendPoints.value = response.data
  rebuildChart()
}

function rebuildChart(): void {
  const byDate = new Map(trendPoints.value.map(point => [point.date, point.rate]))

  if (period.value === 'daily') {
    chartData.value = eachDayOfInterval(range.value).map(date => ({
      date,
      value: byDate.get(format(date, 'yyyy-MM-dd')) ?? 0,
    }))
    return
  }

  if (period.value === 'weekly') {
    chartData.value = eachWeekOfInterval(range.value).map(weekStart => {
      const weekEnd = new Date(Math.min(
        weekStart.getTime() + 6 * 24 * 60 * 60 * 1000,
        range.value.end.getTime(),
      ))
      const weekDays = eachDayOfInterval({ start: weekStart, end: weekEnd })
        .filter(day => day >= range.value.start && day <= range.value.end)

      const rates = weekDays
        .map(day => byDate.get(format(day, 'yyyy-MM-dd')))
        .filter((rate): rate is number => rate !== undefined)

      return {
        date: weekStart,
        value: rates.length ? Math.round(rates.reduce((a, b) => a + b, 0) / rates.length) : 0,
      }
    })
    return
  }

  chartData.value = eachMonthOfInterval(range.value).map(monthStart => {
    const monthRates = trendPoints.value
      .filter(point => {
        const d = new Date(`${point.date}T00:00:00`)
        return d.getFullYear() === monthStart.getFullYear() && d.getMonth() === monthStart.getMonth()
      })
      .map(point => point.rate)

    return {
      date: monthStart,
      value: monthRates.length
        ? Math.round(monthRates.reduce((a, b) => a + b, 0) / monthRates.length)
        : 0,
    }
  })
}

onMounted(load)

watch(range, () => {
  void loadTrend().catch(() => {
    /* trend errors are non-blocking once KPIs loaded */
  })
})

watch(period, rebuildChart)

type ChartPoint = { date: Date; value: number }

const chartRef = useTemplateRef<HTMLElement>('chartRef')
const chartData = ref<ChartPoint[]>([])
const { width: chartWidth } = useElementSize(chartRef)

const chartX = (_: ChartPoint, i: number) => i
const chartY = (d: ChartPoint) => d.value

const xTicks = (i: number): string => {
  if (!chartData.value[i] || i === 0 || i === chartData.value.length - 1) return ''
  const d = chartData.value[i].date
  return period.value === 'monthly'
    ? format(d, 'MMM yy')
    : format(d, 'd MMM')
}

const crosshairTemplate = (d: ChartPoint): string =>
  `${format(d.date, period.value === 'monthly' ? 'MMM yyyy' : 'd MMM')}: ${d.value}%`

const tableStatusFilter = ref('all')
const tableSearch = ref('')
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>({})
const rowSelection = ref<RowSelectionState>({})
const sorting = ref<SortingState>([{ id: 'name', desc: false }])

/** Compact phones/tablets: hide secondary columns so the staff table stays readable. */
const isCompactViewport = useMediaQuery('(max-width: 767px)')
watch(isCompactViewport, (compact) => {
  columnVisibility.value = {
    ...columnVisibility.value,
    id: !compact,
    email: !compact,
    location: !compact,
  }
}, { immediate: true })

const tableFilterOptions = [
  { label: 'All', value: 'all' },
  { label: 'Present', value: 'Present' },
  { label: 'Late', value: 'Late' },
  { label: 'On leave', value: 'On leave' },
  { label: 'Absent', value: 'Absent' },
]

const hideableColumns = [
  { id: 'id', label: 'ID' },
  { id: 'name', label: 'Name' },
  { id: 'email', label: 'Email' },
  { id: 'location', label: 'Location' },
  { id: 'status', label: 'Status' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const tableUi = computed(() => ({
  ...DATA_TABLE_UI,
  base: isCompactViewport.value
    ? 'table-auto border-separate border-spacing-0 w-full text-sm'
    : DATA_TABLE_UI.base,
  th: isCompactViewport.value
    ? 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-2.5 py-2 text-xs font-semibold tracking-wide text-[var(--ui-text-muted)] whitespace-nowrap'
    : DATA_TABLE_UI.th,
  td: isCompactViewport.value
    ? 'border-b border-[var(--ui-border)] px-2.5 py-2 whitespace-nowrap'
    : DATA_TABLE_UI.td,
}))

function getStatusColor(status: DashboardStaffRow['status']): 'success' | 'warning' | 'neutral' | 'error' {
  if (status === 'Present') return 'success'
  if (status === 'Late') return 'warning'
  if (status === 'Absent') return 'error'
  return 'neutral'
}

const tableColumns: TableColumn<DashboardStaffRow>[] = [
  {
    id: 'select',
    header: ({ table: tableApi }) => h(UCheckbox, {
      'modelValue': tableApi.getIsSomePageRowsSelected()
        ? 'indeterminate'
        : tableApi.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: unknown) => tableApi.toggleAllPageRowsSelected(!!value),
      'aria-label': 'Select all',
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: unknown) => row.toggleSelected(!!value),
      'aria-label': 'Select row',
    }),
    enableSorting: false,
    enableHiding: false,
  },
  {
    accessorKey: 'id',
    header: ({ column }) => createSortableHeader(column, 'ID'),
  },
  {
    accessorKey: 'name',
    header: ({ column }) => createSortableHeader(column, 'Name'),
  },
  {
    accessorKey: 'email',
    header: ({ column }) => createSortableHeader(column, 'Email'),
  },
  {
    accessorKey: 'location',
    header: ({ column }) => createSortableHeader(column, 'Location'),
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    filterFn: 'equals',
    cell: ({ row }) => {
      const status = row.getValue<DashboardStaffRow['status']>('status')
      return createStatusBadge(status, getStatusColor(status))
    },
  },
]

const selectedCount = computed(() => Object.values(rowSelection.value).filter(Boolean).length)

watch([tableSearch, tableStatusFilter], () => {
  const next: ColumnFiltersState = []
  if (tableSearch.value.trim()) next.push({ id: 'email', value: tableSearch.value.trim() })
  if (tableStatusFilter.value !== 'all') next.push({ id: 'status', value: tableStatusFilter.value })
  columnFilters.value = next
})

function deleteSelectedRows(): void {
  const ids = new Set(
    Object.entries(rowSelection.value)
      .filter(([, selected]) => selected)
      .map(([id]) => Number(id)),
  )
  if (!ids.size) return
  tableRows.value = tableRows.value.filter(row => !ids.has(row.id))
  rowSelection.value = {}
}

function getTableRowId(row: DashboardStaffRow): string {
  return String(row.id)
}

const healthServices = computed(() => [
  { label: 'Attendance API', ok: systemHealth.value?.app_ok ?? false },
  { label: 'AI Face Service', ok: systemHealth.value?.ai_ok ?? false },
  { label: 'Biometric storage', ok: systemHealth.value?.app_ok ?? false },
])
</script>

<template>
  <UDashboardPanel id="dashboard-home" class="min-w-0">

    <!-- ── NAVBAR ─────────────────────────────────────────────────── -->
    <template #header>
      <UDashboardNavbar title="Dashboard" :ui="{ right: 'gap-3' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <!-- Notifications button -->
          <UTooltip text="Notifications" :shortcuts="['N']">
            <UButton
              color="neutral"
              variant="ghost"
              square
              aria-label="Open notifications"
              @click="isNotificationsSlideoverOpen = true"
            >
              <UChip color="error" inset>
                <UIcon name="i-lucide-bell" class="size-5 shrink-0" />
              </UChip>
            </UButton>
          </UTooltip>

          <!-- Quick actions dropdown -->
          <UDropdownMenu :items="addItems">
            <UButton
              icon="i-lucide-plus"
              size="md"
              color="primary"
              class="rounded-full"
              aria-label="Quick actions"
            />
          </UDropdownMenu>
        </template>
      </UDashboardNavbar>

      <!-- ── TOOLBAR ────────────────────────────────────────────── -->
      <UDashboardToolbar :ui="{ root: 'flex-wrap gap-2 py-2 sm:py-0' }">
        <template #left>
          <div class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto">
            <UPopover>
              <UButton
                color="neutral"
                variant="outline"
                icon="i-lucide-calendar"
                class="min-w-0 max-w-full"
                size="sm"
              >
                <span class="truncate sm:hidden">
                  {{ format(range.start, 'd/M') }}–{{ format(range.end, 'd/M/yy') }}
                </span>
                <span class="hidden sm:inline">
                  {{ format(range.start, 'd MMM') }} – {{ format(range.end, 'd MMM yyyy') }}
                </span>
              </UButton>
              <template #content>
                <div class="p-3 space-y-2 text-sm w-[min(100vw-2rem,16rem)]">
                  <p class="text-muted font-medium">Quick ranges</p>
                  <div class="space-y-1">
                    <button
                      v-for="opt in [
                        { label: 'Last 7 days',  days: 7  },
                        { label: 'Last 14 days', days: 14 },
                        { label: 'Last 30 days', days: 30 },
                        { label: 'Last 90 days', days: 90 },
                      ]"
                      :key="opt.days"
                      class="block w-full rounded-lg px-3 py-1.5 text-left text-sm hover:bg-elevated transition-colors"
                      @click="range = { start: sub(new Date(), { days: opt.days }), end: new Date() }"
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                </div>
              </template>
            </UPopover>

            <USelect
              v-model="period"
              :items="periodOptions"
              value-key="value"
              label-key="label"
              size="sm"
              class="w-[7.5rem] shrink-0"
            />
          </div>
        </template>
      </UDashboardToolbar>
    </template>

    <!-- ── PAGE BODY ──────────────────────────────────────────────── -->
    <template #body>
      <div class="p-3 sm:p-4 md:p-6 space-y-4 sm:space-y-5 md:space-y-6 min-w-0">

        <!-- Error alert -->
        <UAlert
          v-if="error"
          title="Dashboard unavailable"
          :description="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          role="alert"
        >
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">
              Retry
            </UButton>
          </template>
        </UAlert>

        <template v-else>

          <!-- ── KPI STATS ────────────────────────────────────────── -->
          <UPageGrid class="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-px">
            <template v-if="loading">
              <DashboardKpiCard
                v-for="lbl in ['Present', 'Absent', 'Late', 'On leave']"
                :key="lbl"
                :label="lbl"
                :value="0"
                loading
                class="rounded-xl lg:rounded-none lg:first:rounded-l-xl lg:last:rounded-r-xl"
              />
            </template>
            <template v-else-if="kpis">
              <DashboardKpiCard
                label="Present"
                :value="kpis.present"
                icon="i-lucide-circle-check-big"
                caption="Checked in today"
                class="rounded-xl lg:rounded-none lg:first:rounded-l-xl lg:last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="Absent"
                :value="kpis.absent"
                icon="i-lucide-triangle-alert"
                caption="Needs follow-up"
                class="rounded-xl lg:rounded-none lg:first:rounded-l-xl lg:last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="Late"
                :value="kpis.late"
                icon="i-lucide-clock"
                caption="After schedule"
                class="rounded-xl lg:rounded-none lg:first:rounded-l-xl lg:last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="On leave"
                :value="kpis.on_leave"
                icon="i-lucide-calendar-off"
                caption="Approved leave"
                class="rounded-xl lg:rounded-none lg:first:rounded-l-xl lg:last:rounded-r-xl hover:z-1"
              />
            </template>
          </UPageGrid>

          <!-- ── CHART ────────────────────────────────────────────── -->
          <UCard
            ref="chartRef"
            class="min-w-0"
            :ui="{ root: 'overflow-hidden sm:overflow-visible', body: 'px-0! pt-0! pb-3!' }"
          >
            <template #header>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div class="min-w-0">
                  <p class="text-xs text-muted uppercase tracking-wide mb-1">
                    Attendance trend
                  </p>
                  <p class="text-xl sm:text-2xl font-semibold text-highlighted">
                    {{ periodAverageRate }}%
                    <span class="text-sm sm:text-base font-normal text-muted">avg this period</span>
                  </p>
                </div>
                <UBadge color="primary" variant="subtle" class="self-start shrink-0">
                  {{ period.charAt(0).toUpperCase() + period.slice(1) }}
                </UBadge>
              </div>
            </template>

            <div class="w-full min-w-0 overflow-x-auto">
              <VisXYContainer
                :data="chartData"
                :padding="{ top: 40 }"
                :margin="{ left: -5, right: -5 }"
                class="h-48 sm:h-56 md:h-64 unovis-xy-container min-w-[280px]"
                :width="Math.max(chartWidth || 0, 280)"
              >
                <VisLine  :x="chartX" :y="chartY" color="var(--ui-primary)" />
                <VisArea  :x="chartX" :y="chartY" color="var(--ui-primary)" :opacity="0.1" />
                <VisAxis  type="x" :x="chartX" :tick-format="xTicks" />
                <VisCrosshair :x="chartX" :y="chartY" color="var(--ui-primary)" :template="crosshairTemplate" />
                <VisTooltip />
              </VisXYContainer>
            </div>
          </UCard>

          <!-- ── BOTTOM ROW ─────────────────────────────────────── -->
          <div class="grid gap-3 sm:gap-4 lg:grid-cols-[minmax(0,1.45fr)_minmax(16rem,0.55fr)]">

            <!-- Staff table -->
            <UCard class="min-w-0" :ui="{ root: 'overflow-hidden', body: 'p-0!' }">
              <div class="border-b border-[var(--ui-border)] px-3 py-3 sm:px-5">
                <DataTableToolbar
                  v-model:search="tableSearch"
                  v-model:status="tableStatusFilter"
                  search-placeholder="Filter emails..."
                  :status-options="tableFilterOptions"
                  :display-items="displayItems"
                  :selected-count="selectedCount"
                  show-delete
                  @delete="deleteSelectedRows"
                />
              </div>

              <div class="overflow-x-auto">
                <UTable
                  v-model:column-filters="columnFilters"
                  v-model:column-visibility="columnVisibility"
                  v-model:row-selection="rowSelection"
                  v-model:sorting="sorting"
                  :data="tableRows"
                  :columns="tableColumns"
                  :get-row-id="getTableRowId"
                  class="shrink-0 min-w-[20rem] sm:min-w-0"
                  :ui="tableUi"
                />
              </div>
            </UCard>

            <!-- System status -->
            <UCard class="min-w-0" :ui="{ body: 'p-4 sm:p-5 md:p-6' }">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted uppercase tracking-wide">System status</p>
                <UBadge
                  :color="systemHealth?.overall_ok ? 'success' : 'error'"
                  variant="subtle"
                  class="text-xs shrink-0"
                >
                  <template #leading>
                    <span
                      class="h-1.5 w-1.5 rounded-full"
                      :class="systemHealth?.overall_ok ? 'bg-emerald-500' : 'bg-red-500'"
                    />
                  </template>
                  {{ systemHealth?.overall_ok ? 'Operational' : 'Degraded' }}
                </UBadge>
              </div>

              <h3 class="mt-2 text-base font-semibold text-highlighted">
                {{ systemHealth?.overall_ok ? 'Everything is up' : 'Service issues detected' }}
              </h3>

              <ul class="mt-4 space-y-2.5">
                <li
                  v-for="svc in healthServices"
                  :key="svc.label"
                  class="flex items-center justify-between gap-3 text-sm"
                >
                  <span class="text-muted truncate">{{ svc.label }}</span>
                  <span class="flex items-center gap-1.5 text-xs font-semibold shrink-0" :class="svc.ok ? 'text-success' : 'text-error'">
                    <span class="h-1.5 w-1.5 rounded-full" :class="svc.ok ? 'bg-emerald-500' : 'bg-red-500'" />
                    {{ svc.ok ? 'Online' : 'Degraded' }}
                  </span>
                </li>
              </ul>

              <div class="mt-5 flex items-center justify-between gap-3 border-t border-[var(--ui-border)] pt-4 text-xs">
                <span class="text-muted">Last refreshed</span>
                <strong class="font-semibold text-highlighted tabular-nums">
                  {{ formatRefreshedAt(systemHealth?.refreshed_at) }}
                </strong>
              </div>
            </UCard>
          </div>

        </template>
      </div>
    </template>

  </UDashboardPanel>
</template>
