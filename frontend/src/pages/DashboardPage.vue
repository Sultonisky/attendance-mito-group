<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue'
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
          <div class="grid gap-4 xl:grid-cols-[1.3fr_0.7fr]">

            <!-- People ops quick links -->
            <UCard :ui="{ body: 'p-5 sm:p-6' }">
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                  <p class="text-xs text-muted uppercase tracking-wide">People Operations</p>
                  <h3 class="mt-1.5 text-lg font-semibold text-highlighted">Keep the day moving</h3>
                  <p class="mt-2 text-sm text-muted leading-relaxed">
                    Open a workspace to manage requests, approvals, and attendance records.
                  </p>
                  <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <RouterLink
                      v-for="link in [
                        { label: 'Attendance',    to: '/dashboard/reports/attendance',     icon: 'i-lucide-calendar-check-2'  },
                        { label: 'Leave',         to: '/dashboard/reports/leave',          icon: 'i-lucide-calendar-off'      },
                        { label: 'Overtime',      to: '/dashboard/reports/overtime',       icon: 'i-lucide-bar-chart-3'       },
                        { label: 'Monthly recap', to: '/dashboard/reports/monthly-recaps', icon: 'i-lucide-file-text'         },
                      ]"
                      :key="link.to"
                      :to="link.to"
                      class="flex items-center gap-2.5 rounded-xl border border-[var(--ui-border)] px-3.5 py-2.5 text-sm font-medium text-muted transition hover:border-primary/30 hover:bg-primary/5 hover:text-primary"
                    >
                      <UIcon :name="link.icon" class="size-4 shrink-0" />
                      {{ link.label }}
                    </RouterLink>
                  </div>
                </div>
                <div class="hidden sm:flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10">
                  <UIcon name="i-lucide-users" class="size-5 text-primary" />
                </div>
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
