<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../../../components/AppButton.vue'
import AppIcon from '../../../components/AppIcon.vue'
import { useAuthStore } from '../../../stores/auth'
import { fetchAttendanceToday } from '../../../services/attendanceService'
import { ApiError } from '../../../services/apiClient'
import type { AttendanceRecord, AttendanceSession } from '../../../types/attendance'
import {
  formatAttendanceLongDate,
  formatAttendanceTime,
  formatAttendanceTimeWithSeconds,
} from '../../../utils/attendanceDateTime'

const auth = useAuthStore()
const router = useRouter()
const record = ref<AttendanceRecord | null>(null)
const isLoading = ref(true)
const error = ref('')
const now = ref(new Date())

let clockTimer: number | null = null

const firstName = computed(() => auth.user?.name?.split(' ')[0] || 'there')
const avatarInitial = computed(() => auth.user?.name?.charAt(0).toUpperCase() || '?')

const hasOpenSession = computed(() =>
  record.value?.sessions.some((session) => session.status === 'open') ?? false,
)

const sessions = computed(() => record.value?.sessions ?? [])

const statusLabel = computed(() => {
  if (!record.value) return 'Not clocked in'
  return formatStatus(record.value.status)
})

const actionLabel = computed(() => (hasOpenSession.value ? 'Clock out' : 'Clock in'))

const nextActionCopy = computed(() => {
  if (isLoading.value) return 'Loading your attendance for today…'
  if (error.value) return 'We could not load today’s status. Try again.'
  if (hasOpenSession.value) return 'You’re clocked in. Clock out when you leave.'
  if (sessions.value.length > 0) return 'Ready for another session if you need one.'
  return 'Ready to record your presence with Face ID and location.'
})

const greetingLine = computed(() => {
  if (hasOpenSession.value) return 'You’re on the clock — keep it accurate.'
  if (sessions.value.length > 0) return 'Your day is already on record.'
  return 'Keep your attendance up to date.'
})

const heroCheckIn = computed(() => {
  const first = sessions.value[0]
  return formatTime(first?.check_in_at ?? null)
})

const heroCheckOut = computed(() => {
  if (hasOpenSession.value) {
    const open = sessions.value.find((session) => session.status === 'open')
    return formatTime(open?.check_out_at ?? null)
  }

  const lastClosed = [...sessions.value].reverse().find((session) => session.status === 'closed')
  return formatTime(lastClosed?.check_out_at ?? null)
})

const openSession = computed(() =>
  sessions.value.find((session) => session.status === 'open') ?? null,
)

const heroDurationLabel = computed(() => {
  if (openSession.value?.check_in_at) {
    const checkInTime = new Date(openSession.value.check_in_at).getTime()
    if (Number.isFinite(checkInTime)) {
      const elapsedSeconds = Math.max(0, Math.floor((now.value.getTime() - checkInTime) / 1000))
      return formatDurationSeconds(elapsedSeconds)
    }
  }

  const closedMinutes = sessions.value.reduce((total, session) => {
    if (session.status !== 'closed' || session.duration_minutes == null) return total
    return total + session.duration_minutes
  }, 0)

  if (closedMinutes > 0) {
    return formatDurationSeconds(Math.round(closedMinutes * 60))
  }

  return '00:00:00'
})

function formatStatus(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatCurrentDate(): string {
  return formatAttendanceLongDate(now.value)
}

function formatCurrentTime(): string {
  return formatAttendanceTimeWithSeconds(now.value)
}

function formatTime(value: string | null): string {
  return formatAttendanceTime(value, '--:--')
}

function formatDurationSeconds(totalSeconds: number | null): string {
  if (totalSeconds === null || !Number.isFinite(totalSeconds)) {
    return '00:00:00'
  }

  const safeSeconds = Math.max(0, Math.floor(totalSeconds))
  const hours = Math.floor(safeSeconds / 3600)
  const minutes = Math.floor((safeSeconds % 3600) / 60)
  const seconds = safeSeconds % 60

  return [hours, minutes, seconds]
    .map((value) => value.toString().padStart(2, '0'))
    .join(':')
}

function formatDuration(session: AttendanceSession): string {
  if (session.duration_minutes == null) return '—'
  const hours = Math.floor(session.duration_minutes / 60)
  const minutes = session.duration_minutes % 60
  if (hours === 0) return `${minutes}m`
  return `${hours}h ${minutes}m`
}

async function load(): Promise<void> {
  isLoading.value = true
  error.value = ''

  try {
    record.value = await fetchAttendanceToday()
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'error.unauthorized' })
      return
    }

    error.value = 'Unable to load your attendance today.'
  } finally {
    isLoading.value = false
  }
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login.employee' })
}

function goAttendance(): void {
  void router.push({ name: 'attendance' })
}

function goAttendanceList(): void {
  void router.push({ name: 'employee-attendance' })
}

function goRequest(): void {
  void router.push({ name: 'employee-request' })
}

function goHome(): void {
  void router.push({ name: 'employee-app' })
}

onMounted(() => {
  clockTimer = window.setInterval(() => {
    now.value = new Date()
  }, 1000)
  void load()
})

onUnmounted(() => {
  if (clockTimer !== null) {
    clearInterval(clockTimer)
  }
})
</script>

<template>
  <main class="employee-app">
    <div class="employee-atmosphere" aria-hidden="true" />

    <header class="employee-header">
      <div class="employee-brand">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p>MITO GROUP</p>
          <strong>My Attendance</strong>
        </div>
      </div>
      <div class="identity-chip" :title="auth.user?.name || 'Employee'" aria-hidden="true">
        {{ avatarInitial }}
      </div>
    </header>

    <section class="welcome-block">
      <div class="date-row">
        <p class="welcome-kicker">{{ formatCurrentDate() }}</p>
        <span class="clock-live">{{ formatCurrentTime() }}</span>
      </div>
      <h1>Hi, {{ firstName }}</h1>
      <p class="welcome-copy">{{ greetingLine }}</p>
    </section>

    <section v-if="isLoading" class="hero-card hero-card--loading" aria-label="Loading attendance" aria-busy="true">
      <div class="skeleton skeleton-kicker" />
      <div class="skeleton skeleton-title" />
      <div class="skeleton skeleton-copy" />
      <div class="time-grid time-grid--skeleton">
        <div class="skeleton skeleton-time" />
        <div class="skeleton skeleton-time" />
        <div class="skeleton skeleton-time" />
      </div>
      <div class="skeleton skeleton-button" />
    </section>

    <section v-else-if="error" class="hero-card hero-card--error" role="alert">
      <p class="card-kicker">TODAY’S STATUS</p>
      <h2>Couldn’t load</h2>
      <p class="hero-copy">{{ error }}</p>
      <AppButton
        type="button"
        class="hero-action-button"
        variant="primary"
        full-width
        @click="load"
      >
        Retry
      </AppButton>
    </section>

    <section v-else class="hero-card" aria-live="polite">
      <div class="card-heading">
        <div>
          <p class="card-kicker">NEXT ACTION</p>
          <h2>{{ statusLabel }}</h2>
        </div>
        <span class="status-dot" :class="{ active: hasOpenSession }" aria-hidden="true" />
      </div>

      <p class="hero-copy">{{ nextActionCopy }}</p>

      <div class="time-grid time-grid--three">
        <div class="time-item">
          <span>Clock in</span>
          <strong>{{ heroCheckIn }}</strong>
        </div>
        <div class="time-item">
          <span>Duration</span>
          <strong>{{ heroDurationLabel }}</strong>
        </div>
        <div class="time-item">
          <span>Clock out</span>
          <strong>{{ heroCheckOut }}</strong>
        </div>
      </div>

      <AppButton
        type="button"
        class="hero-action-button"
        variant="primary"
        icon="ArrowRight"
        icon-position="right"
        full-width
        @click="goAttendance"
      >
        {{ actionLabel }}
      </AppButton>
    </section>

    <section class="sessions-section" aria-label="Today’s sessions">
      <div class="section-heading">
        <h2>Today’s sessions</h2>
        <span v-if="!isLoading && !error">{{ sessions.length }}</span>
      </div>

      <div v-if="isLoading" class="sessions-panel sessions-panel--loading">
        <div class="skeleton skeleton-session" />
        <div class="skeleton skeleton-session" />
      </div>

      <div v-else-if="error" class="sessions-panel sessions-empty">
        <p>Sessions will appear here once status loads.</p>
      </div>

      <div v-else-if="sessions.length === 0" class="sessions-panel sessions-empty">
        <AppIcon name="CalendarCheck2" class="empty-icon" :size="20" :stroke-width="2" aria-hidden="true" />
        <p>No sessions yet today.</p>
      </div>

      <ul v-else class="sessions-panel sessions-list">
        <li
          v-for="(session, index) in sessions"
          :key="session.id"
          class="session-row"
        >
          <span class="session-index">{{ index + 1 }}</span>
          <div class="session-times">
            <span>IN {{ formatTime(session.check_in_at) }}</span>
            <span>OUT {{ formatTime(session.check_out_at) }}</span>
          </div>
          <span class="session-duration">{{ formatDuration(session) }}</span>
          <span
            class="session-badge"
            :class="session.status === 'open' ? 'badge-open' : 'badge-closed'"
          >
            {{ session.status }}
          </span>
        </li>
      </ul>
    </section>

    <section class="prep-tip" aria-label="Before you clock in">
      <AppIcon name="ShieldCheck" class="prep-icon" :size="18" :stroke-width="2.2" aria-hidden="true" />
      <div>
        <strong>Before you go</strong>
        <p>Allow camera and location so Face ID and geofence can verify your attendance.</p>
      </div>
    </section>

    <nav class="bottom-nav" aria-label="Employee navigation">
      <button class="bottom-link active" type="button" aria-current="page" @click="goHome">
        <AppIcon name="House" :size="18" :stroke-width="2" />
        <small>Home</small>
      </button>
      <button class="bottom-link" type="button" @click="goAttendanceList">
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
.employee-app {
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
  height: 22rem;
  pointer-events: none;
  background:
    radial-gradient(ellipse 70% 55% at 18% 20%, rgba(235, 28, 36, 0.12), transparent 70%),
    radial-gradient(ellipse 65% 50% at 88% 0%, rgba(26, 40, 69, 0.14), transparent 72%);
  z-index: 0;
}

.employee-header,
.employee-brand,
.card-heading,
.section-heading,
.session-row,
.prep-tip {
  display: flex;
  align-items: center;
}

.employee-header,
.welcome-block,
.hero-card,
.sessions-section,
.prep-tip,
.bottom-nav {
  position: relative;
  z-index: 1;
}

.employee-header {
  justify-content: space-between;
  padding: 0.35rem 0 1.25rem;
  animation: rise-in 420ms ease-out both;
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
.card-kicker,
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

.identity-chip {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
  user-select: none;
}

.welcome-block {
  padding: 0.35rem 0 1.35rem;
  animation: rise-in 480ms ease-out 60ms both;
}

.date-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.25rem;
}

.clock-live {
  flex-shrink: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
  background: var(--code-bg);
  padding: 0.15rem 0.5rem;
  border-radius: 6px;
  font-variant-numeric: tabular-nums;
}

.welcome-block h1 {
  margin: 0.4rem 0 0.3rem;
  font-size: clamp(1.75rem, 6vw, 2rem);
  font-weight: 700;
  letter-spacing: -0.05em;
  color: var(--mito-navy);
}

.welcome-copy {
  margin: 0;
  color: var(--text);
  max-width: 22rem;
}

.hero-card {
  padding: 1.35rem 1.2rem 1.15rem;
  border-radius: 16px;
  background: linear-gradient(135deg, #eb1c24 0%, #c5151d 100%);
  color: #fff;
  box-shadow: 0 18px 34px rgba(235, 28, 36, 0.24);
  animation: rise-in 520ms ease-out 110ms both;
}

.hero-card--loading,
.hero-card--error {
  min-height: 14rem;
}

.card-heading {
  justify-content: space-between;
  gap: 1rem;
}

.card-kicker {
  color: rgba(255, 255, 255, 0.75);
}

.card-heading h2 {
  margin: 0.25rem 0 0;
  color: #fff;
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: -0.03em;
}

.status-dot {
  flex-shrink: 0;
  width: 0.75rem;
  height: 0.75rem;
  border: 3px solid rgba(255, 255, 255, 0.35);
  border-radius: 50%;
}

.status-dot.active {
  background: #fff;
  box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.22);
}

.hero-copy {
  margin: 0.85rem 0 0;
  color: rgba(255, 255, 255, 0.88);
  font-size: 0.92rem;
  line-height: 1.45;
}

.time-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin: 1.15rem 0 1.2rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.25);
}

.time-grid--three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.time-item {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.time-item span {
  color: rgba(255, 255, 255, 0.76);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.time-item strong {
  font-size: 1.05rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
}

.hero-card :deep(.hero-action-button) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 3.1rem;
  padding: 0.9rem 1.15rem;
  border-radius: 11px;
  border: none;
  background: #fff !important;
  color: #eb1c24 !important;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.08);
  transition:
    transform 0.15s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
}

.hero-card :deep(.hero-action-button .app-button__label) {
  flex: 1;
  justify-content: flex-start;
  text-align: left;
}

.hero-card :deep(.hero-action-button .app-button__icon),
.hero-card :deep(.hero-action-button svg) {
  color: #eb1c24 !important;
  stroke: #eb1c24 !important;
}

.hero-card :deep(.hero-action-button:hover:not(:disabled)) {
  transform: translateY(-1px);
  box-shadow: 0 12px 22px rgba(17, 17, 17, 0.12);
}

.hero-card :deep(.hero-action-button:active:not(:disabled)) {
  transform: scale(0.985);
}

.hero-card :deep(.hero-action-button:disabled) {
  opacity: 0.7;
  cursor: not-allowed;
}

.hero-card :deep(.hero-action-button:focus-visible) {
  outline: 3px solid rgba(255, 255, 255, 0.35);
  outline-offset: 2px;
}

.sessions-section {
  padding-top: 1.75rem;
  animation: rise-in 560ms ease-out 160ms both;
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

.sessions-panel {
  margin: 0;
  padding: 0.35rem;
  list-style: none;
  border: 1px solid var(--mito-border);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
}

.sessions-empty {
  display: grid;
  justify-items: center;
  gap: 0.45rem;
  padding: 1.35rem 1rem;
  color: var(--text);
  text-align: center;
  font-size: 0.9rem;
}

.sessions-empty p {
  margin: 0;
}

.empty-icon {
  color: var(--accent);
}

.session-row {
  gap: 0.65rem;
  padding: 0.75rem 0.65rem;
  border-radius: 10px;
}

.session-row + .session-row {
  border-top: 1px solid var(--mito-border);
}

.session-index {
  display: grid;
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
  place-items: center;
  border-radius: 999px;
  background: var(--accent-bg);
  color: var(--mito-active-text);
  font-size: 0.72rem;
  font-weight: 700;
}

.session-times {
  display: grid;
  gap: 0.15rem;
  flex: 1;
  min-width: 0;
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--mito-text);
}

.session-duration {
  color: var(--text);
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
}

.session-badge {
  flex-shrink: 0;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.badge-open {
  background: rgba(235, 28, 36, 0.1);
  color: var(--mito-active-text);
}

.badge-closed {
  background: rgba(26, 40, 69, 0.08);
  color: var(--mito-navy);
}

.prep-tip {
  gap: 0.75rem;
  margin-top: 1.1rem;
  padding: 0.95rem 1rem;
  border: 1px dashed var(--mito-accent-border);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.72);
  color: var(--text);
  animation: rise-in 600ms ease-out 200ms both;
}

.prep-icon {
  flex-shrink: 0;
  color: var(--accent);
}

.prep-tip strong {
  display: block;
  margin-bottom: 0.15rem;
  color: var(--mito-navy);
  font-size: 0.88rem;
}

.prep-tip p {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.4;
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

.skeleton {
  border-radius: 8px;
  background: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0.08) 0%,
    rgba(255, 255, 255, 0.18) 50%,
    rgba(255, 255, 255, 0.08) 100%
  );
  background-size: 200% 100%;
  animation: shimmer 1.2s ease-in-out infinite;
}

.skeleton-kicker {
  width: 5.5rem;
  height: 0.55rem;
}

.skeleton-title {
  width: 9rem;
  height: 1.35rem;
  margin-top: 0.7rem;
}

.skeleton-copy {
  width: 85%;
  height: 0.85rem;
  margin-top: 0.9rem;
}

.time-grid--skeleton {
  margin-bottom: 1rem;
}

.skeleton-time {
  height: 2.5rem;
}

.skeleton-button {
  height: 2.6rem;
  border-radius: 10px;
}

.sessions-panel--loading {
  display: grid;
  gap: 0.55rem;
  padding: 0.85rem;
}

.skeleton-session {
  height: 3rem;
  background: linear-gradient(
    90deg,
    rgba(226, 232, 240, 0.55) 0%,
    rgba(241, 245, 249, 0.95) 50%,
    rgba(226, 232, 240, 0.55) 100%
  );
  background-size: 200% 100%;
}

@keyframes rise-in {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

@media (min-width: 700px) {
  .employee-app {
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
  .employee-header,
  .welcome-block,
  .hero-card,
  .sessions-section,
  .prep-tip,
  .skeleton,
  .skeleton-session {
    animation: none;
  }

  .hero-card :deep(.hero-action-button) {
    transition: none;
  }
}
</style>
