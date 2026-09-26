<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../../../components/AppButton.vue'
import AppIcon from '../../../components/AppIcon.vue'
import { useAuthStore } from '../../../stores/auth'
import { ApiError, firstValidationMessage } from '../../../services/apiClient'
import {
  cancelAttendanceCorrection,
  createAttendanceCorrection,
  fetchAttendanceCorrections,
} from '../../../services/attendanceCorrectionApi'
import type {
  AttendanceCorrectionRequest,
  AttendanceCorrectionType,
} from '../../../types/attendanceCorrection'
import {
  formatAttendanceShortDate,
  formatAttendanceTime,
} from '../../../utils/attendanceDateTime'

const auth = useAuthStore()
const router = useRouter()

const requests = ref<AttendanceCorrectionRequest[]>([])
const isLoading = ref(true)
const isSubmitting = ref(false)
const isCancellingId = ref<number | null>(null)
const listError = ref('')
const formError = ref('')
const formSuccess = ref('')
const formOpen = ref(true)

const form = reactive({
  request_type: 'clock_out' as AttendanceCorrectionType,
  attendance_date: yesterdayDate(),
  requested_check_in_at: '',
  requested_check_out_at: '',
  reason: '',
})

const typeOptions: { label: string; value: AttendanceCorrectionType; help: string }[] = [
  { label: 'Clock in', value: 'clock_in', help: 'Forgot to clock in' },
  { label: 'Clock out', value: 'clock_out', help: 'Forgot to clock out' },
  { label: 'Both', value: 'both', help: 'Forgot clock in and out' },
]

const showCheckIn = computed(() => form.request_type === 'clock_in' || form.request_type === 'both')
const showCheckOut = computed(() => form.request_type === 'clock_out' || form.request_type === 'both')

function yesterdayDate(): string {
  const date = new Date()
  date.setDate(date.getDate() - 1)
  return toDateInput(date)
}

function toDateInput(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function localInputToApi(value: string): string | null {
  if (!value) return null
  return `${value}:00+07:00`
}

function formatStatus(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatType(type: string): string {
  if (type === 'clock_in') return 'Clock in'
  if (type === 'clock_out') return 'Clock out'
  if (type === 'both') return 'Clock in & out'
  return formatStatus(type)
}

function formatTime(value: string | null): string {
  return formatAttendanceTime(value, '--:--')
}

async function loadRequests(): Promise<void> {
  isLoading.value = true
  listError.value = ''

  try {
    const response = await fetchAttendanceCorrections({ per_page: 20 })
    requests.value = response.data
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'error.unauthorized' })
      return
    }
    listError.value = 'Unable to load your requests.'
  } finally {
    isLoading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  formSuccess.value = ''
  isSubmitting.value = true

  try {
    await createAttendanceCorrection({
      request_type: form.request_type,
      attendance_date: form.attendance_date,
      requested_check_in_at: showCheckIn.value ? localInputToApi(form.requested_check_in_at) : null,
      requested_check_out_at: showCheckOut.value ? localInputToApi(form.requested_check_out_at) : null,
      reason: form.reason.trim(),
    })

    formSuccess.value = 'Request submitted. Waiting for admin review.'
    form.reason = ''
    form.requested_check_in_at = ''
    form.requested_check_out_at = ''
    formOpen.value = false
    await loadRequests()
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'error.unauthorized' })
      return
    }
    formError.value = firstValidationMessage(err)
      || (err instanceof ApiError ? err.message : 'Unable to submit request.')
  } finally {
    isSubmitting.value = false
  }
}

async function cancelRequest(id: number): Promise<void> {
  isCancellingId.value = id
  listError.value = ''

  try {
    await cancelAttendanceCorrection(id)
    await loadRequests()
  } catch (err) {
    listError.value = err instanceof ApiError ? err.message : 'Unable to cancel request.'
  } finally {
    isCancellingId.value = null
  }
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login.employee' })
}

function goHome(): void {
  void router.push({ name: 'employee-app' })
}

function goAttendanceList(): void {
  void router.push({ name: 'employee-attendance' })
}

function goRequest(): void {
  void router.push({ name: 'employee-request' })
}

onMounted(() => {
  void loadRequests()
})
</script>

<template>
  <main class="employee-request">
    <div class="employee-atmosphere" aria-hidden="true" />

    <header class="employee-header">
      <div class="employee-brand">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p>MITO GROUP</p>
          <strong>Request</strong>
        </div>
      </div>
    </header>

    <section class="page-intro">
      <p class="welcome-kicker">ATTENDANCE FIX</p>
      <h1>Request correction</h1>
      <p>Submit a request if you forgot to clock in or out. Admin will review it.</p>
    </section>

    <section class="form-card" :class="{ 'form-card--open': formOpen }" aria-label="Correction request form">
      <button
        type="button"
        class="form-toggle"
        :aria-expanded="formOpen"
        aria-controls="request-form-panel"
        @click="formOpen = !formOpen"
      >
        <span class="form-toggle__main">
          <span class="form-toggle__icon" aria-hidden="true">
            <AppIcon name="FileText" :size="16" :stroke-width="2.2" />
          </span>
          <span class="form-toggle__copy">
            <strong>{{ formOpen ? 'Hide form' : 'New request' }}</strong>
            <small>Forgotten clock in / out</small>
          </span>
        </span>
        <AppIcon
          :name="formOpen ? 'ChevronUp' : 'ChevronDown'"
          :size="18"
          :stroke-width="2.2"
          aria-hidden="true"
        />
      </button>

      <div v-show="formOpen" id="request-form-panel" class="form-panel">
        <form class="form-body" novalidate @submit.prevent="submit">
          <label class="filter-field">
            <span>Request type</span>
            <select v-model="form.request_type">
              <option
                v-for="option in typeOptions"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }} — {{ option.help }}
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Attendance date</span>
            <input v-model="form.attendance_date" type="date" required />
          </label>

          <div class="filter-grid">
            <label v-if="showCheckIn" class="filter-field">
              <span>Clock in time</span>
              <input v-model="form.requested_check_in_at" type="datetime-local" required />
            </label>
            <label v-if="showCheckOut" class="filter-field">
              <span>Clock out time</span>
              <input v-model="form.requested_check_out_at" type="datetime-local" required />
            </label>
          </div>

          <label class="filter-field">
            <span>Reason</span>
            <textarea
              v-model="form.reason"
              rows="3"
              maxlength="1000"
              placeholder="Explain why you need this correction (min. 10 characters)"
              required
            />
          </label>

          <p v-if="formError" class="form-alert form-alert--error" role="alert">{{ formError }}</p>
          <p v-if="formSuccess" class="form-alert form-alert--success" role="status">{{ formSuccess }}</p>
        </form>

        <div class="form-action">
          <AppButton
            type="button"
            class="hero-action-button"
            variant="primary"
            icon="ArrowRight"
            icon-position="right"
            full-width
            :disabled="isSubmitting"
            @click="submit"
          >
            {{ isSubmitting ? 'Submitting…' : 'Submit request' }}
          </AppButton>
          <button type="button" class="form-close" @click="formOpen = false">
            Close form
          </button>
        </div>
      </div>
    </section>

    <section class="results-section" aria-live="polite">
      <div class="section-heading">
        <h2>Your requests</h2>
        <span>{{ isLoading ? 'Loading…' : `${requests.length}` }}</span>
      </div>

      <div v-if="isLoading" class="list-panel list-panel--loading">
        <div class="skeleton skeleton-row" />
        <div class="skeleton skeleton-row" />
      </div>

      <div v-else-if="listError" class="list-panel list-empty" role="alert">
        <p>{{ listError }}</p>
        <AppButton type="button" class="btn-secondary" variant="secondary" @click="loadRequests">
          Retry
        </AppButton>
      </div>

      <div v-else-if="requests.length === 0" class="list-panel list-empty">
        <AppIcon name="FileText" class="empty-icon" :size="20" :stroke-width="2" />
        <p>No correction requests yet.</p>
      </div>

      <ul v-else class="list-panel records-list">
        <li v-for="item in requests" :key="item.id" class="record-card">
          <div class="record-top">
            <div>
              <p class="record-date">{{ formatAttendanceShortDate(item.attendance_date) }}</p>
              <p class="record-meta">{{ formatType(item.request_type) }}</p>
            </div>
            <span class="status-badge" :data-status="item.status">
              {{ formatStatus(item.status) }}
            </span>
          </div>
          <div class="record-times">
            <div>
              <span>Clock in</span>
              <strong>{{ formatTime(item.requested_check_in_at) }}</strong>
            </div>
            <div>
              <span>Clock out</span>
              <strong>{{ formatTime(item.requested_check_out_at) }}</strong>
            </div>
          </div>
          <p class="record-reason">{{ item.reason }}</p>
          <AppButton
            v-if="item.status === 'pending'"
            type="button"
            class="btn-secondary btn-secondary--light"
            variant="secondary"
            size="sm"
            :disabled="isCancellingId === item.id"
            @click="cancelRequest(item.id)"
          >
            {{ isCancellingId === item.id ? 'Cancelling…' : 'Cancel request' }}
          </AppButton>
        </li>
      </ul>
    </section>

    <nav class="bottom-nav" aria-label="Employee navigation">
      <button class="bottom-link" type="button" @click="goHome">
        <AppIcon name="House" :size="18" :stroke-width="2" />
        <small>Home</small>
      </button>
      <button class="bottom-link" type="button" @click="goAttendanceList">
        <AppIcon name="CalendarCheck2" :size="18" :stroke-width="2" />
        <small>Attendance</small>
      </button>
      <button class="bottom-link active" type="button" aria-current="page" @click="goRequest">
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
.employee-request {
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
.record-times,
.form-toggle,
.form-toggle__main {
  display: flex;
  align-items: center;
}

.employee-header,
.page-intro,
.form-card,
.results-section,
.bottom-nav {
  position: relative;
  z-index: 1;
}

.employee-header {
  justify-content: space-between;
  padding: 0.35rem 0 1.1rem;
}

.employee-brand {
  gap: 0.6rem;
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

.form-card {
  border: 1px solid var(--mito-border);
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 8px 22px rgba(24, 24, 28, 0.05);
  overflow: hidden;
}

.form-toggle {
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

.form-card--open .form-toggle {
  border-bottom: 1px solid var(--mito-border);
}

.form-toggle__main {
  gap: 0.7rem;
  min-width: 0;
}

.form-toggle__icon {
  display: grid;
  width: 2.1rem;
  height: 2.1rem;
  place-items: center;
  border-radius: 9px;
  background: var(--accent-bg);
  color: var(--accent);
}

.form-toggle__copy {
  display: grid;
  gap: 0.15rem;
}

.form-toggle__copy strong {
  font-size: 0.92rem;
  color: var(--mito-navy);
}

.form-toggle__copy small {
  color: var(--text);
  font-size: 0.75rem;
}

.form-body {
  display: grid;
  gap: 0.75rem;
  padding: 1.05rem;
}

.filter-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.65rem;
}

@media (min-width: 420px) {
  .filter-grid {
    grid-template-columns: 1fr 1fr;
  }
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
.filter-field select,
.filter-field textarea {
  width: 100%;
  min-height: 2.55rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--mito-border);
  border-radius: 10px;
  background: #fff;
  color: var(--mito-text);
  font: inherit;
}

.filter-field textarea {
  min-height: 5.5rem;
  resize: vertical;
}

.form-alert {
  margin: 0;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  font-size: 0.85rem;
}

.form-alert--error {
  background: rgba(235, 28, 36, 0.1);
  color: var(--mito-active-text);
}

.form-alert--success {
  background: rgba(22, 163, 74, 0.12);
  color: #15803d;
}

.form-action {
  display: grid;
  gap: 0.65rem;
  padding: 1rem 1.05rem 1.1rem;
  background: linear-gradient(135deg, #eb1c24 0%, #c5151d 100%);
}

.form-close {
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

.employee-request :deep(.hero-action-button),
.employee-request :deep(.btn-secondary) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 3rem;
  padding: 0.85rem 1.1rem;
  border-radius: 11px;
  font-size: 0.92rem;
  font-weight: 700;
}

.employee-request :deep(.hero-action-button) {
  border: none;
  background: #fff !important;
  color: #eb1c24 !important;
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.08);
}

.employee-request :deep(.hero-action-button .app-button__label) {
  flex: 1;
  justify-content: flex-start;
}

.employee-request :deep(.hero-action-button .app-button__icon),
.employee-request :deep(.hero-action-button svg) {
  color: #eb1c24 !important;
  stroke: #eb1c24 !important;
}

.employee-request :deep(.btn-secondary) {
  justify-content: center;
  border: 1px solid var(--mito-border);
  background: #fff !important;
  color: var(--mito-text) !important;
}

.employee-request :deep(.btn-secondary--light) {
  margin-top: 0.75rem;
  min-height: 2.4rem;
  padding: 0.5rem 0.85rem;
  font-size: 0.8rem;
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

.status-badge[data-status='pending'] {
  background: rgba(234, 88, 12, 0.12);
  color: #c2410c;
}

.status-badge[data-status='approved'] {
  background: rgba(22, 163, 74, 0.12);
  color: #15803d;
}

.status-badge[data-status='rejected'],
.status-badge[data-status='cancelled'] {
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
}

.record-reason {
  margin: 0.7rem 0 0;
  color: var(--text);
  font-size: 0.82rem;
  line-height: 1.4;
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
  padding: 0.7rem 0.75rem calc(0.7rem + env(safe-area-inset-bottom));
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
  font-size: 0.65rem;
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
  .employee-request {
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
  .skeleton {
    animation: none;
  }
}
</style>
