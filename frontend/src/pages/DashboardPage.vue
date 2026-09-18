<script setup lang="ts">
import { computed, onMounted, reactive, ref, shallowRef, useTemplateRef, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { sub, eachDayOfInterval, eachWeekOfInterval, eachMonthOfInterval, format } from 'date-fns'
import { VisXYContainer, VisLine, VisArea, VisAxis, VisCrosshair, VisTooltip } from '@unovis/vue'
import { useElementSize } from '@vueuse/core'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useDashboard } from '../composables/useDashboard'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../services/apiClient'
import { fetchDashboardKpis } from '../services/dashboardApi'
import type { DashboardKpiData } from '../types/dashboard'
import DashboardKpiCard from '../components/DashboardKpiCard.vue'

const router  = useRouter()
const auth    = useAuthStore()
const { isNotificationsSlideoverOpen } = useDashboard()

// ── date range + period ──────────────────────────────────────────────
type Period = 'daily' | 'weekly' | 'monthly'
type Range  = { start: Date; end: Date }

const range  = shallowRef<Range>({ start: sub(new Date(), { days: 14 }), end: new Date() })
const period = ref<Period>('daily')

const periodOptions = [
  { label: 'Daily',   value: 'daily'   as Period },
  { label: 'Weekly',  value: 'weekly'  as Period },
  { label: 'Monthly', value: 'monthly' as Period },
]

// ── quick-add dropdown ───────────────────────────────────────────────
const addItems: DropdownMenuItem[][] = [[
  { label: 'View attendance',  icon: 'i-lucide-calendar-check-2', to: '/dashboard/reports/attendance' },
  { label: 'View leave',       icon: 'i-lucide-calendar-off',     to: '/dashboard/reports/leave'      },
  { label: 'View overtime',    icon: 'i-lucide-bar-chart-3',      to: '/dashboard/reports/overtime'   },
]]

// ── KPI data ─────────────────────────────────────────────────────────
const loading = ref(true)
const error   = ref('')
const kpis    = ref<DashboardKpiData | null>(null)

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? 'Admin')

const attendanceRate = computed(() => {
  if (!kpis.value) return 0
  const total = kpis.value.present + kpis.value.absent + kpis.value.late + kpis.value.on_leave
  return total === 0 ? 0 : Math.round(((kpis.value.present + kpis.value.late) / total) * 100)
})

function formatDate(dateStr: string): string {
  return new Date(`${dateStr}T00:00:00`).toLocaleDateString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  })
}

async function load(): Promise<void> {
  loading.value = true
  error.value   = ''
  try {
    const response = await fetchDashboardKpis()
    kpis.value = response.data
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

onMounted(load)

// ── chart ─────────────────────────────────────────────────────────────
type ChartPoint = { date: Date; value: number }

const chartRef  = useTemplateRef<HTMLElement>('chartRef')
const chartData = ref<ChartPoint[]>([])
const { width: chartWidth } = useElementSize(chartRef)

watch([period, range], () => {
  const intervals = {
    daily:   eachDayOfInterval,
    weekly:  eachWeekOfInterval,
    monthly: eachMonthOfInterval,
  } as Record<Period, typeof eachDayOfInterval>

  const dates = intervals[period.value](range.value)
  chartData.value = dates.map(date => ({
    date,
    value: Math.floor(Math.random() * 50) + 50, // mock attendance %
  }))
}, { immediate: true })

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

// ── dashboard table ─────────────────────────────────────────────────
type TableStatus = 'all' | 'present' | 'late' | 'on_leave'
type DashboardTableRow = {
  id: number
  name: string
  email: string
  location: string
  status: 'Present' | 'Late' | 'On leave'
}

const tableRows = ref<DashboardTableRow[]>([
  { id: 1101, name: 'Ayu Dewi', email: 'ayu.dewi@mitogroup.com', location: 'Jakarta', status: 'Present' },
  { id: 1102, name: 'Budi Santoso', email: 'budi.santoso@mitogroup.com', location: 'Bandung', status: 'Late' },
  { id: 1103, name: 'Citra Maharani', email: 'citra.maharani@mitogroup.com', location: 'Surabaya', status: 'On leave' },
  { id: 1104, name: 'Dimas Pratama', email: 'dimas.pratama@mitogroup.com', location: 'Semarang', status: 'Present' },
  { id: 1105, name: 'Eka Putri', email: 'eka.putri@mitogroup.com', location: 'Medan', status: 'Late' },
  { id: 1106, name: 'Fajar Nugroho', email: 'fajar.nugroho@mitogroup.com', location: 'Yogyakarta', status: 'Present' },
])

const tableSearch = ref('')
const tableStatusFilter = ref<TableStatus>('all')
const tableSort = reactive<{ key: keyof DashboardTableRow; direction: 'asc' | 'desc' }>({
  key: 'id',
  direction: 'asc',
})

const tableFilterOptions = [
  { label: 'All', value: 'all' },
  { label: 'Present', value: 'present' },
  { label: 'Late', value: 'late' },
  { label: 'On leave', value: 'on_leave' },
]

const tableColumns = [
  { key: 'id', label: 'ID' },
  { key: 'name', label: 'Name' },
  { key: 'email', label: 'Email' },
  { key: 'location', label: 'Location' },
  { key: 'status', label: 'Status' },
] as const

const visibleTableRows = computed(() => {
  const query = tableSearch.value.trim().toLowerCase()

  const filtered = tableRows.value.filter((row) => {
    const matchesQuery = !query || [row.id, row.name, row.email, row.location, row.status]
      .join(' ')
      .toLowerCase()
      .includes(query)

    const matchesStatus =
      tableStatusFilter.value === 'all' ||
      (tableStatusFilter.value === 'present' && row.status === 'Present') ||
      (tableStatusFilter.value === 'late' && row.status === 'Late') ||
      (tableStatusFilter.value === 'on_leave' && row.status === 'On leave')

    return matchesQuery && matchesStatus
  })

  const sorted = [...filtered].sort((a, b) => {
    const left = a[tableSort.key]
    const right = b[tableSort.key]
    const direction = tableSort.direction === 'asc' ? 1 : -1

    if (typeof left === 'number' && typeof right === 'number') {
      return (left - right) * direction
    }

    return String(left).localeCompare(String(right)) * direction
  })

  return sorted
})

function sortTable(key: keyof DashboardTableRow): void {
  if (tableSort.key === key) {
    tableSort.direction = tableSort.direction === 'asc' ? 'desc' : 'asc'
    return
  }

  tableSort.key = key
  tableSort.direction = 'asc'
}

function getStatusColor(status: DashboardTableRow['status']): 'success' | 'warning' | 'neutral' {
  if (status === 'Present') return 'success'
  if (status === 'Late') return 'warning'
  return 'neutral'
}

function getStatusText(status: DashboardTableRow['status']): string {
  return status === 'On leave' ? 'On leave' : status
}
</script>

<template>
  <UDashboardPanel id="dashboard-home">

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
      <UDashboardToolbar>
        <template #left>
          <!-- Date range: start -->
          <UPopover>
            <UButton
              color="neutral"
              variant="outline"
              :icon="'i-lucide-calendar'"
              class="-ms-1"
            >
              {{ format(range.start, 'd MMM') }} – {{ format(range.end, 'd MMM yyyy') }}
            </UButton>
            <template #content>
              <div class="p-3 space-y-2 text-sm">
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

          <!-- Period select -->
          <USelect
            v-model="period"
            :items="periodOptions"
            value-key="value"
            label-key="label"
            size="sm"
          />
        </template>
      </UDashboardToolbar>
    </template>

    <!-- ── PAGE BODY ──────────────────────────────────────────────── -->
    <template #body>
      <div class="p-4 sm:p-6 space-y-6">

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

          <!-- ── HERO BANNER ──────────────────────────────────────── -->
          <section
            class="relative overflow-hidden rounded-2xl p-6 text-white sm:p-8"
            style="background: linear-gradient(135deg, #eb1c24 0%, #c5151d 42%, #1a2845 100%);"
            aria-label="Dashboard overview"
          >
            <!-- Decorative rings -->
            <span class="pointer-events-none absolute -right-8 -top-8 h-44 w-44 rounded-full bg-white/[0.04]" aria-hidden="true" />
            <span class="pointer-events-none absolute -bottom-10 right-16 h-56 w-56 rounded-full bg-white/[0.03]" aria-hidden="true" />

            <div class="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <div class="mito-badge mb-3 w-fit">
                  <span class="mito-pulse-dot" aria-hidden="true" />
                  Live Operations
                </div>
                <h2 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                  {{ greeting }}, {{ firstName }}.
                </h2>
                <p v-if="kpis" class="mt-1.5 text-sm text-white/75">
                  {{ formatDate(kpis.date) }}
                </p>
                <!-- Attendance rate -->
                <div v-if="kpis && !loading" class="mt-4 inline-flex items-center gap-3 rounded-xl border border-white/20 bg-white/10 px-4 py-2.5 backdrop-blur-sm">
                  <div>
                    <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-white/60">Attendance rate</div>
                    <div class="text-2xl font-extrabold leading-none">{{ attendanceRate }}%</div>
                  </div>
                  <div class="h-9 w-px bg-white/15" aria-hidden="true" />
                  <div class="text-xs text-white/70 leading-relaxed">
                    <div><span class="font-semibold text-white">{{ kpis.present }}</span> present</div>
                    <div><span class="font-semibold text-white">{{ kpis.late }}</span> late</div>
                  </div>
                </div>
              </div>

              <RouterLink
                to="/dashboard/reports/attendance"
                class="inline-flex items-center gap-2 self-start rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold backdrop-blur-sm transition hover:bg-white/20 active:scale-95"
              >
                <UIcon name="i-lucide-calendar-check-2" class="size-4" />
                Review attendance
                <UIcon name="i-lucide-arrow-right" class="size-4" />
              </RouterLink>
            </div>
          </section>

          <!-- ── KPI STATS ────────────────────────────────────────── -->
          <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-5 lg:gap-px">
            <template v-if="loading">
              <DashboardKpiCard
                v-for="lbl in ['Present', 'Absent', 'Late', 'On leave']"
                :key="lbl"
                :label="lbl"
                :value="0"
                loading
                class="lg:rounded-none first:rounded-l-xl last:rounded-r-xl"
              />
            </template>
            <template v-else-if="kpis">
              <DashboardKpiCard
                label="Present"
                :value="kpis.present"
                icon="i-lucide-circle-check-big"
                caption="Checked in today"
                class="lg:rounded-none first:rounded-l-xl last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="Absent"
                :value="kpis.absent"
                icon="i-lucide-triangle-alert"
                caption="Needs follow-up"
                class="lg:rounded-none first:rounded-l-xl last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="Late"
                :value="kpis.late"
                icon="i-lucide-clock"
                caption="After schedule"
                class="lg:rounded-none first:rounded-l-xl last:rounded-r-xl hover:z-1"
              />
              <DashboardKpiCard
                label="On leave"
                :value="kpis.on_leave"
                icon="i-lucide-calendar-off"
                caption="Approved leave"
                class="lg:rounded-none first:rounded-l-xl last:rounded-r-xl hover:z-1"
              />
            </template>
          </UPageGrid>

          <!-- ── CHART ────────────────────────────────────────────── -->
          <UCard
            ref="chartRef"
            :ui="{ root: 'overflow-visible', body: 'px-0! pt-0! pb-3!' }"
          >
            <template #header>
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-xs text-muted uppercase tracking-wide mb-1">
                    Attendance trend
                  </p>
                  <p class="text-2xl font-semibold text-highlighted">
                    {{ attendanceRate }}% <span class="text-base font-normal text-muted">avg this period</span>
                  </p>
                </div>
                <UBadge color="primary" variant="subtle">
                  {{ period.charAt(0).toUpperCase() + period.slice(1) }}
                </UBadge>
              </div>
            </template>

            <VisXYContainer
              :data="chartData"
              :padding="{ top: 40 }"
              :margin="{ left: -5, right: -5 }"
              class="h-64 unovis-xy-container"
              :width="chartWidth"
            >
              <VisLine  :x="chartX" :y="chartY" color="var(--ui-primary)" />
              <VisArea  :x="chartX" :y="chartY" color="var(--ui-primary)" :opacity="0.1" />
              <VisAxis  type="x" :x="chartX" :tick-format="xTicks" />
              <VisCrosshair :x="chartX" :y="chartY" color="var(--ui-primary)" :template="crosshairTemplate" />
              <VisTooltip />
            </VisXYContainer>
          </UCard>

          <!-- ── BOTTOM ROW ─────────────────────────────────────── -->
          <div class="grid gap-4 xl:grid-cols-[1.45fr_0.55fr]">

            <!-- Staff table -->
            <UCard :ui="{ root: 'overflow-hidden', body: 'p-0!' }">
              <div class="border-b border-[var(--ui-border)] px-4 py-3 sm:px-5">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                  <UInput
                    v-model="tableSearch"
                    placeholder="Filter emails..."
                    icon="i-lucide-search"
                    size="lg"
                    class="max-w-md"
                  />

                  <div class="flex items-center gap-2 self-end xl:self-auto">
                    <UButton
                      color="error"
                      variant="outline"
                      :ui="{ base: 'border-red-500/60 text-red-500 hover:bg-red-500/5' }"
                      class="rounded-xl"
                    >
                      <template #leading>
                        <UIcon name="i-lucide-trash-2" class="size-4" />
                      </template>
                      Delete
                      <UBadge color="error" size="xs" class="min-w-5 justify-center rounded-full">1</UBadge>
                    </UButton>

                    <USelect
                      v-model="tableStatusFilter"
                      :items="tableFilterOptions"
                      value-key="value"
                      label-key="label"
                      size="lg"
                      class="min-w-[110px]"
                    />

                    <UButton color="neutral" variant="outline" class="rounded-xl">
                      Display
                      <template #trailing>
                        <UIcon name="i-lucide-sliders-horizontal" class="size-4" />
                      </template>
                    </UButton>
                  </div>
                </div>

                <div class="mt-4">
                  <UTabs
                    v-model="tableStatusFilter"
                    :items="tableFilterOptions"
                    :ui="{ list: 'gap-2', trigger: 'rounded-lg px-3 py-2 text-sm font-medium', indicator: 'rounded-lg shadow-sm' }"
                  />
                </div>
              </div>

              <div class="overflow-x-auto">
                <table class="min-w-full border-separate border-spacing-0 text-sm">
                  <thead class="bg-[var(--ui-bg-elevated)]/90 text-left">
                    <tr>
                      <th class="w-16 border-y border-[var(--ui-border)] px-4 py-3 text-left">
                        <div class="flex items-center gap-2">
                          <span class="h-3.5 w-3.5 rounded-full bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.12)]" />
                        </div>
                      </th>
                      <th
                        v-for="column in tableColumns"
                        :key="column.key"
                        class="border-y border-[var(--ui-border)] px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--ui-text-muted)]"
                      >
                        <button
                          type="button"
                          class="inline-flex items-center gap-1.5 transition hover:text-[var(--ui-text)]"
                          :class="tableSort.key === column.key ? 'text-[var(--ui-text)]' : 'text-[var(--ui-text-muted)]'"
                          @click="sortTable(column.key)"
                        >
                          {{ column.label }}
                          <UIcon
                            v-if="tableSort.key === column.key"
                            :name="tableSort.direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down'"
                            class="size-3.5"
                          />
                          <UIcon
                            v-else
                            name="i-lucide-arrow-up-down"
                            class="size-3.5 opacity-60"
                          />
                        </button>
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr v-for="row in visibleTableRows" :key="row.id" class="group hover:bg-[var(--ui-bg-muted)]/80">
                      <td class="border-b border-[var(--ui-border)] px-4 py-3">
                        <div class="flex items-center justify-center">
                          <input type="checkbox" class="h-4 w-4 rounded border-[var(--ui-border)] bg-transparent text-primary focus:ring-primary" />
                        </div>
                      </td>
                      <td class="border-b border-[var(--ui-border)] px-4 py-3 font-medium text-[var(--ui-text)]">{{ row.id }}</td>
                      <td class="border-b border-[var(--ui-border)] px-4 py-3 text-[var(--ui-text)]">{{ row.name }}</td>
                      <td class="border-b border-[var(--ui-border)] px-4 py-3 text-[var(--ui-text-muted)]">{{ row.email }}</td>
                      <td class="border-b border-[var(--ui-border)] px-4 py-3 text-[var(--ui-text-muted)]">{{ row.location }}</td>
                      <td class="border-b border-[var(--ui-border)] px-4 py-3">
                        <UBadge :color="getStatusColor(row.status)" variant="subtle" class="capitalize">
                          {{ getStatusText(row.status) }}
                        </UBadge>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </UCard>

            <!-- System status -->
            <UCard :ui="{ body: 'p-5 sm:p-6' }">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted uppercase tracking-wide">System status</p>
                <UBadge color="success" variant="subtle" class="text-xs">
                  <template #leading>
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  </template>
                  Operational
                </UBadge>
              </div>

              <h3 class="mt-2 text-base font-semibold text-highlighted">Everything is up</h3>

              <ul class="mt-4 space-y-2.5">
                <li
                  v-for="svc in [
                    { label: 'Attendance API',    ok: true },
                    { label: 'AI Face Service',   ok: true },
                    { label: 'Biometric storage', ok: true },
                  ]"
                  :key="svc.label"
                  class="flex items-center justify-between text-sm"
                >
                  <span class="text-muted">{{ svc.label }}</span>
                  <span class="flex items-center gap-1.5 text-xs font-semibold" :class="svc.ok ? 'text-success' : 'text-error'">
                    <span class="h-1.5 w-1.5 rounded-full" :class="svc.ok ? 'bg-emerald-500' : 'bg-red-500'" />
                    {{ svc.ok ? 'Online' : 'Degraded' }}
                  </span>
                </li>
              </ul>

              <div class="mt-5 flex items-center justify-between border-t border-[var(--ui-border)] pt-4 text-xs">
                <span class="text-muted">Last refreshed</span>
                <strong class="font-semibold text-highlighted">Just now</strong>
              </div>
            </UCard>
          </div>

        </template>
      </div>
    </template>

  </UDashboardPanel>
</template>
