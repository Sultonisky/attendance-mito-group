<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../../../components/AppButton.vue'
import AppIcon from '../../../components/AppIcon.vue'
import { useAuthStore } from '../../../stores/auth'
import { ApiError } from '../../../services/apiClient'
import { fetchAttendanceIndex } from '../../../services/attendanceService'
import type { AttendanceRecord } from '../../../types/attendance'
import { defaultReportDates } from '../../../types/reportDates'
import { formatAttendanceShortDate, formatAttendanceTime } from '../../../utils/attendanceDateTime'

const auth = useAuthStore()
const router = useRouter()

const records = ref<AttendanceRecord[]>([])
const isLoading = ref(true)
const error = ref('')
const meta = reactive({
  current_page: 1,
  per_page: 15,
  total: 0,
  last_page: 1,
})

const filters = reactive({
  ...defaultReportDates(),
  status: '',
})

const appliedFilters = reactive({
  ...defaultReportDates(),
  status: '',
})

const filtersOpen = ref(false)

const statusOptions = [
  { label: 'All statuses', value: '' },
  { label: 'Present', value: 'present' },
  { label: 'Late', value: 'late' },
  { label: 'Incomplete', value: 'incomplete' },
  { label: 'Absent', value: 'absent' },
  { label: 'Off day', value: 'off_day' },
  { label: 'Holiday', value: 'holiday' },
  { label: 'Leave', value: 'leave' },
  { label: 'Business trip', value: 'business_trip' },
]

const hasPrev = computed(() => meta.current_page > 1)
const hasNext = computed(() => meta.current_page < meta.last_page)
const resultLabel = computed(() => {
  if (isLoading.value) return 'Loading…'
  if (meta.total === 0) return 'No records'
  return `${meta.total} record${meta.total === 1 ? '' : 's'}`
})

const statusLabel = computed(() => {
  if (!appliedFilters.status) return 'All statuses'
  return formatStatus(appliedFilters.status)
})

const filterSummary = computed(() => {
  return `${appliedFilters.from} → ${appliedFilters.to} · ${statusLabel.value}`
})

const hasCustomStatus = computed(() => appliedFilters.status !== '')

function formatStatus(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatTime(value: string | null): string {
  return formatAttendanceTime(value, '--:--')
}

function firstIn(record: AttendanceRecord): string {
  return formatTime(record.sessions[0]?.check_in_at ?? null)
}

function lastOut(record: AttendanceRecord): string {
  const open = record.sessions.find((session) => session.status === 'open')
  if (open) return formatTime(open.check_out_at)
  const last = [...record.sessions].reverse().find((session) => session.status === 'closed')
  return formatTime(last?.check_out_at ?? null)
}

function sessionCountLabel(record: AttendanceRecord): string {
  const count = record.sessions.length
  if (count === 0) return 'No sessions'
  return `${count} session${count === 1 ? '' : 's'}`
}

async function load(page = 1): Promise<void> {
  isLoading.value = true
  error.value = ''

  try {
    const response = await fetchAttendanceIndex({
      from: appliedFilters.from || undefined,
      to: appliedFilters.to || undefined,
      status: appliedFilters.status || undefined,
      per_page: meta.per_page,
      page,
    })

    records.value = response.data.map((item) => item.data)
    meta.current_page = response.meta.current_page
    meta.per_page = response.meta.per_page
    meta.total = response.meta.total
    meta.last_page = response.meta.last_page
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'error.unauthorized' })
      return
    }

    if (err instanceof ApiError && err.status === 403) {
      error.value = 'You do not have permission to view attendance.'
      return
    }

    error.value = 'Unable to load attendance history. Please try again.'
  } finally {
    isLoading.value = false
  }
}

function syncAppliedFilters(): void {
  appliedFilters.from = filters.from
  appliedFilters.to = filters.to
  appliedFilters.status = filters.status
}

function applyFilters(): void {
  syncAppliedFilters()
  filtersOpen.value = false
  void load(1)
}

function resetFilters(): void {
  const defaults = defaultReportDates()
  filters.from = defaults.from
  filters.to = defaults.to
  filters.status = ''
  syncAppliedFilters()
  void load(1)
}

function toggleFilters(): void {
  filtersOpen.value = !filtersOpen.value
  if (filtersOpen.value) {
    filters.from = appliedFilters.from
    filters.to = appliedFilters.to
    filters.status = appliedFilters.status
  }
}

function closeFilters(): void {
  filtersOpen.value = false
  filters.from = appliedFilters.from
  filters.to = appliedFilters.to
  filters.status = appliedFilters.status
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login.employee' })
}

function goHome(): void {
  void router.push({ name: 'employee-app' })
}

function goList(): void {
  void router.push({ name: 'employee-attendance' })
}

function goRequest(): void {
  void router.push({ name: 'employee-request' })
}

function goClock(): void {
  void router.push({ name: 'attendance' })
}

onMounted(() => {
  void load(1)
})
</script>

<template>
  <main class="employee-list">
    <div class="employee-atmosphere" aria-hidden="true" />

    <header class="employee-header">
      <div class="employee-brand">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p>MITO GROUP</p>
          <strong>Attendance</strong>
        </div>
      </div>
      <AppButton
        type="button"
        class="btn-primary btn-primary--compact"
        variant="primary"
        size="sm"
        icon="ArrowRight"
        icon-position="right"
        @click="goClock"
      >
        Clock
      </AppButton>
    </header>

    <section class="page-intro">
      <p class="welcome-kicker">YOUR HISTORY</p>
      <h1>Attendance list</h1>
      <p>Review your records. Open filters only when you need them.</p>
    </section>

    <section class="filter-card" :class="{ 'filter-card--open': filtersOpen }" aria-label="Attendance filters">
      <button
        type="button"
        class="filter-toggle"
        :aria-expanded="filtersOpen"
        aria-controls="attendance-filter-panel"
        @click="toggleFilters"
      >
        <span class="filter-toggle__main">
          <span class="filter-toggle__icon" aria-hidden="true">
            <AppIcon name="ChevronsUpDown" :size="16" :stroke-width="2.2" />
          </span>
          <span class="filter-toggle__copy">
            <strong>{{ filtersOpen ? 'Hide filters' : 'Filters' }}</strong>
            <small>{{ filterSummary }}</small>
          </span>
        </span>
        <span class="filter-toggle__meta">
          <span v-if="hasCustomStatus" class="filter-chip">Filtered</span>
          <AppIcon
            :name="filtersOpen ? 'ChevronUp' : 'ChevronDown'"
            :size="18"
            :stroke-width="2.2"
            aria-hidden="true"
          />
        </span>
      </button>

      <div
        v-show="filtersOpen"
        id="attendance-filter-panel"
        class="filter-panel"
      >
        <div class="filter-body">
          <div class="filter-grid">
            <label class="filter-field">
              <span>From</span>
              <input v-model="filters.from" type="date" />
            </label>
            <label class="filter-field">
              <span>To</span>
              <input v-model="filters.to" type="date" />
            </label>
          </div>

          <label class="filter-field">
            <span>Status</span>
            <select v-model="filters.status">
              <option
                v-for="option in statusOptions"
                :key="option.value || 'all'"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </label>
        </div>

        <div class="filter-action">
          <div class="button-group">
            <AppButton
              type="button"
              class="btn-secondary"
              variant="secondary"
              @click="resetFilters"
            >
              Reset
            </AppButton>
            <AppButton
              type="button"
              class="hero-action-button"
              variant="primary"
              icon="ArrowRight"
              icon-position="right"
              full-width
              @click="applyFilters"
            >
              Apply filters
            </AppButton>
          </div>
          <button type="button" class="filter-close" @click="closeFilters">
            Close filters
          </button>
        </div>
      </div>
    </section>

    <section class="results-section" aria-live="polite">
      <div class="section-heading">
        <h2>Results</h2>
        <span>{{ resultLabel }}</span>
      </div>

      <div v-if="isLoading" class="list-panel list-panel--loading">
        <div class="skeleton skeleton-row" />
        <div class="skeleton skeleton-row" />
        <div class="skeleton skeleton-row" />
      </div>

      <div v-else-if="error" class="list-panel list-empty" role="alert">
        <p>{{ error }}</p>
        <AppButton
          type="button"
          class="btn-secondary"
          variant="secondary"
          @click="load(meta.current_page)"
        >
          Retry
        </AppButton>
      </div>

      <div v-else-if="records.length === 0" class="list-panel list-empty">
        <AppIcon name="CalendarCheck2" class="empty-icon" :size="20" :stroke-width="2" />
        <p>No attendance records for this filter.</p>
        <AppButton
          type="button"
          class="btn-primary"
          variant="primary"
          icon="ArrowRight"
          icon-position="right"
          @click="goClock"
        >
          Go to clock
        </AppButton>
      </div>

      <ul v-else class="list-panel records-list">
        <li v-for="record in records" :key="record.id" class="record-card">
          <div class="record-top">
            <div>
              <p class="record-date">{{ formatAttendanceShortDate(record.attendance_date) }}</p>
              <p class="record-meta">{{ sessionCountLabel(record) }}</p>
            </div>
            <span class="status-badge" :data-status="record.status">
              {{ formatStatus(record.status) }}
            </span>
          </div>
          <div class="record-times">
            <div>
              <span>Clock in</span>
              <strong>{{ firstIn(record) }}</strong>
            </div>
            <div>
              <span>Clock out</span>
              <strong>{{ lastOut(record) }}</strong>
            </div>
          </div>
        </li>
      </ul>

      <div v-if="!isLoading && !error && meta.last_page > 1" class="pagination">
        <AppButton
          type="button"
          class="btn-secondary"
          variant="secondary"
          :disabled="!hasPrev"
          @click="load(meta.current_page - 1)"
        >
          Previous
        </AppButton>
        <span class="page-indicator">
          Page {{ meta.current_page }} / {{ meta.last_page }}
        </span>
        <AppButton
          type="button"
          class="btn-secondary"
          variant="secondary"
          :disabled="!hasNext"
          @click="load(meta.current_page + 1)"
        >
          Next
        </AppButton>
      </div>
    </section>

    <nav class="bottom-nav" aria-label="Employee navigation">
      <button class="bottom-link" type="button" @click="goHome">
        <AppIcon name="House" :size="18" :stroke-width="2" />
        <small>Home</small>
      </button>
      <button class="bottom-link active" type="button" aria-current="page" @click="goList">
        <AppIcon name="CalendarCheck2" :size="18" :stroke-width="2" />
        <small>Attendance</small>
      </button>
      <button class="bottom-link" type="button" @click="goRequest">
        <AppIcon name="FileText" :size="18" :stroke-width="2" />
        <small>Request</small>
      </button>
      <button class="bottom-link" type="button" @click="logout">
        <AppIcon name="LogOut" :size="18" :stroke-width="2" />
        <small>Sign out</small>
      </button>
    </nav>
  </main>
</template>

<style scoped>
.employee-list {
  position: relative;
  box-sizing: border-box;
  width: min(100%, 32rem);
  min-height: 100svh;
  margin: 0 auto;
  padding: 1rem 1rem 6rem;
  overflow: hidden;
  background: var(--mito-bg);
  color: var(--text-h);
}

.employee-atmosphere {
  position: absolute;
  inset: -20% -30% auto;
  height: 18rem;
  pointer-events: none;
  background:
    radial-gradient(ellipse 70% 55% at 18% 20%, rgba(235, 28, 36, 0.1), transparent 70%),
    radial-gradient(ellipse 65% 50% at 88% 0%, rgba(26, 40, 69, 0.12), transparent 72%);
  z-index: 0;
}

.employee-header,
.employee-brand,
.section-heading,
.record-top,
.pagination,
.record-times {
  display: flex;
  align-items: center;
}

.employee-header,
.page-intro,
.filter-card,
.results-section,
.bottom-nav {
  position: relative;
  z-index: 1;
}

.employee-header {
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.35rem 0 1.1rem;
}

.employee-brand {
  gap: 0.6rem;
  min-width: 0;
}

.employee-brand img {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 7px;
  object-fit: cover;
}

.employee-brand p,
.welcome-kicker {
  margin: 0;
  color: var(--accent);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.employee-brand strong {
  display: block;
  font-size: 0.95rem;
  color: var(--mito-navy);
}

.page-intro {
  padding: 0.15rem 0 1.15rem;
}

.page-intro h1 {
  margin: 0.35rem 0 0.3rem;
  font-size: clamp(1.55rem, 5vw, 1.85rem);
  font-weight: 700;
  letter-spacing: -0.04em;
  color: var(--mito-navy);
}

.page-intro p:last-child {
  margin: 0;
  color: var(--text);
  font-size: 0.92rem;
}

.filter-card {
  border: 1px solid var(--mito-border);
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 8px 22px rgba(24, 24, 28, 0.05);
  overflow: hidden;
}

.filter-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  margin: 0;
  padding: 0.9rem 1rem;
  border: 0;
  background: #fff;
  color: var(--mito-text);
  text-align: left;
  cursor: pointer;
}

.filter-toggle:focus-visible {
  outline: 3px solid rgba(235, 28, 36, 0.18);
  outline-offset: -3px;
}

.filter-card--open .filter-toggle {
  border-bottom: 1px solid var(--mito-border);
}

.filter-toggle__main {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-width: 0;
}

.filter-toggle__icon {
  display: grid;
  width: 2.1rem;
  height: 2.1rem;
  flex-shrink: 0;
  place-items: center;
  border-radius: 9px;
  background: var(--accent-bg);
  color: var(--accent);
}

.filter-toggle__copy {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.filter-toggle__copy strong {
  font-size: 0.92rem;
  color: var(--mito-navy);
}

.filter-toggle__copy small {
  overflow: hidden;
  color: var(--text);
  font-size: 0.75rem;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.filter-toggle__meta {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
  color: var(--text);
}

.filter-chip {
  padding: 0.18rem 0.45rem;
  border-radius: 999px;
  background: rgba(235, 28, 36, 0.1);
  color: var(--mito-active-text);
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.filter-panel {
  animation: filter-open 180ms ease-out;
}

.filter-body {
  display: grid;
  gap: 0.75rem;
  padding: 1.05rem 1.05rem 0.95rem;
}

.filter-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}

.filter-field {
  display: grid;
  gap: 0.35rem;
}

.filter-field span {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text);
}

.filter-field input,
.filter-field select {
  width: 100%;
  min-height: 2.55rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--mito-border);
  border-radius: 10px;
  background: #fff;
  color: var(--mito-text);
}

.filter-action {
  display: grid;
  gap: 0.65rem;
  padding: 1rem 1.05rem 1.1rem;
  background: linear-gradient(135deg, #eb1c24 0%, #c5151d 100%);
}

.filter-close {
  width: 100%;
  margin: 0;
  padding: 0.55rem 0.75rem;
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 10px;
  background: transparent;
  color: #fff;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}

.filter-close:hover {
  background: rgba(255, 255, 255, 0.1);
}

.filter-close:focus-visible {
  outline: 3px solid rgba(255, 255, 255, 0.35);
  outline-offset: 2px;
}

.button-group {
  display: grid;
  grid-template-columns: minmax(96px, 0.8fr) minmax(0, 1.6fr);
  gap: 0.65rem;
  align-items: stretch;
}

.button-group > * {
  width: 100%;
}

@keyframes filter-open {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.employee-list :deep(.btn-primary),
.employee-list :deep(.btn-secondary),
.employee-list :deep(.hero-action-button) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 3rem;
  padding: 0.85rem 1.1rem;
  border-radius: 11px;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  transition:
    transform 0.15s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
}

.employee-list :deep(.btn-primary) {
  border: none;
  background: linear-gradient(180deg, #eb1c24 0%, #c5151d 100%) !important;
  color: #fff !important;
  box-shadow: 0 6px 18px rgba(235, 28, 36, 0.24);
}

.employee-list :deep(.btn-primary--compact) {
  width: auto;
  min-height: 2.35rem;
  padding: 0.45rem 0.85rem;
  gap: 0.45rem;
  justify-content: center;
  font-size: 0.8rem;
}

.employee-list :deep(.btn-primary .app-button__label),
.employee-list :deep(.hero-action-button .app-button__label) {
  flex: 1;
  justify-content: flex-start;
  text-align: left;
}

.employee-list :deep(.btn-primary--compact .app-button__label) {
  flex: 0 1 auto;
}

.employee-list :deep(.btn-primary .app-button__icon),
.employee-list :deep(.btn-primary svg) {
  color: #fff !important;
  stroke: #fff !important;
}

.employee-list :deep(.btn-secondary) {
  justify-content: center;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.14) !important;
  color: #fff !important;
  box-shadow: none;
}

.list-empty :deep(.btn-secondary),
.pagination :deep(.btn-secondary) {
  border: 1px solid var(--mito-border) !important;
  background: #fff !important;
  color: var(--mito-text) !important;
}

.employee-list :deep(.hero-action-button) {
  border: none;
  background: #fff !important;
  color: #eb1c24 !important;
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.08);
}

.employee-list :deep(.hero-action-button .app-button__icon),
.employee-list :deep(.hero-action-button svg) {
  color: #eb1c24 !important;
  stroke: #eb1c24 !important;
}

.employee-list :deep(.btn-primary:hover:not(:disabled)),
.employee-list :deep(.hero-action-button:hover:not(:disabled)) {
  transform: translateY(-1px);
}

.employee-list :deep(.btn-primary:hover:not(:disabled)) {
  box-shadow: 0 10px 22px rgba(235, 28, 36, 0.3);
}

.employee-list :deep(.hero-action-button:hover:not(:disabled)) {
  box-shadow: 0 12px 22px rgba(17, 17, 17, 0.12);
}

.employee-list :deep(.btn-primary:active:not(:disabled)),
.employee-list :deep(.btn-secondary:active:not(:disabled)),
.employee-list :deep(.hero-action-button:active:not(:disabled)) {
  transform: scale(0.985);
}

.employee-list :deep(.btn-primary:disabled),
.employee-list :deep(.btn-secondary:disabled),
.employee-list :deep(.hero-action-button:disabled) {
  opacity: 0.55;
  cursor: not-allowed;
  box-shadow: none;
  transform: none;
}

.employee-list :deep(.btn-primary:focus-visible),
.employee-list :deep(.btn-secondary:focus-visible),
.employee-list :deep(.hero-action-button:focus-visible) {
  outline: 3px solid rgba(235, 28, 36, 0.18);
  outline-offset: 2px;
}

.results-section {
  padding-top: 1.4rem;
}

.section-heading {
  justify-content: space-between;
  margin-bottom: 0.75rem;
}

.section-heading h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--mito-navy);
}

.section-heading span {
  color: var(--text);
  font-size: 0.75rem;
  font-weight: 600;
}

.list-panel {
  margin: 0;
  padding: 0.35rem;
  list-style: none;
  border: 1px solid var(--mito-border);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
}

.list-panel--loading {
  display: grid;
  gap: 0.55rem;
  padding: 0.85rem;
}

.list-empty {
  display: grid;
  justify-items: center;
  gap: 0.7rem;
  padding: 1.5rem 1rem;
  color: var(--text);
  text-align: center;
}

.list-empty p {
  margin: 0;
}

.list-empty :deep(.btn-primary),
.list-empty :deep(.btn-secondary) {
  width: auto;
  min-width: 10rem;
}

.empty-icon {
  color: var(--accent);
}

.record-card {
  padding: 0.85rem 0.75rem;
  border-radius: 12px;
}

.record-card + .record-card {
  border-top: 1px solid var(--mito-border);
}

.record-top {
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.record-date {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--mito-navy);
}

.record-meta {
  margin: 0.2rem 0 0;
  font-size: 0.78rem;
  color: var(--text);
}

.status-badge {
  flex-shrink: 0;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  background: rgba(26, 40, 69, 0.08);
  color: var(--mito-navy);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.status-badge[data-status='present'] {
  background: rgba(22, 163, 74, 0.12);
  color: #15803d;
}

.status-badge[data-status='late'],
.status-badge[data-status='incomplete'] {
  background: rgba(234, 88, 12, 0.12);
  color: #c2410c;
}

.status-badge[data-status='absent'] {
  background: rgba(235, 28, 36, 0.1);
  color: var(--mito-active-text);
}

.record-times {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem;
}

.record-times div {
  display: grid;
  gap: 0.15rem;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  background: #f7f8fa;
}

.record-times span {
  font-size: 0.68rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text);
}

.record-times strong {
  font-size: 0.95rem;
  font-variant-numeric: tabular-nums;
  color: var(--mito-text);
}

.pagination {
  justify-content: space-between;
  gap: 0.65rem;
  margin-top: 0.9rem;
}

.pagination :deep(.btn-secondary) {
  width: auto;
  min-width: 6.5rem;
  min-height: 2.55rem;
  padding: 0.55rem 0.9rem;
  font-size: 0.82rem;
}

.page-indicator {
  color: var(--text);
  font-size: 0.78rem;
  font-weight: 600;
  white-space: nowrap;
}

.skeleton {
  border-radius: 10px;
  background: linear-gradient(
    90deg,
    rgba(226, 232, 240, 0.55) 0%,
    rgba(241, 245, 249, 0.95) 50%,
    rgba(226, 232, 240, 0.55) 100%
  );
  background-size: 200% 100%;
  animation: shimmer 1.2s ease-in-out infinite;
}

.skeleton-row {
  height: 5.4rem;
}

.bottom-nav {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 2;
  display: flex;
  justify-content: center;
  gap: clamp(1.1rem, 8vw, 2.8rem);
  padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom));
  border-top: 1px solid var(--mito-border);
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 -8px 24px rgba(24, 24, 28, 0.06);
}

.bottom-link {
  display: grid;
  gap: 0.2rem;
  justify-items: center;
  padding: 0;
  border: 0;
  background: transparent;
  color: #98989f;
}

.bottom-link small {
  font-size: 0.68rem;
  font-weight: 600;
}

.bottom-link.active {
  color: var(--accent);
}

@keyframes shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

@media (min-width: 700px) {
  .employee-list {
    margin-top: 2rem;
    border: 1px solid var(--mito-border);
    border-radius: 16px;
    min-height: calc(100svh - 4rem);
    box-shadow: var(--shadow);
  }

  .bottom-nav {
    position: static;
    margin: 2rem -1rem -6rem;
    border-radius: 0 0 16px 16px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton,
  .filter-panel {
    animation: none;
  }
}
</style>
