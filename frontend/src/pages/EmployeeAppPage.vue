<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../components/AppButton.vue'
import AppIcon from '../components/AppIcon.vue'
import { useAuthStore } from '../stores/auth'
import { fetchAttendanceToday } from '../services/attendanceService'
import { ApiError } from '../services/apiClient'
import type { AttendanceRecord } from '../types/attendance'

const auth = useAuthStore()
const router = useRouter()
const record = ref<AttendanceRecord | null>(null)
const isLoading = ref(true)
const error = ref('')
const today = new Date()

const hasOpenSession = computed(() =>
  record.value?.sessions.some((session) => session.status === 'open') ?? false,
)

const statusLabel = computed(() => {
  if (!record.value) return 'Not checked in'
  return record.value.status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
})

const actionLabel = computed(() => hasOpenSession.value ? 'Check out' : 'Check in')

function formatDate(): string {
  return today.toLocaleDateString([], {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })
}

function formatTime(value: string | null): string {
  if (!value) return '--:--'
  return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function load(): Promise<void> {
  isLoading.value = true
  error.value = ''

  try {
    record.value = await fetchAttendanceToday()
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) {
      await router.push({ name: 'login.employee' })
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

onMounted(load)
</script>

<template>
  <main class="employee-app">
    <header class="employee-header">
      <div class="employee-brand">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p>MITO GROUP</p>
          <strong>My Attendance</strong>
        </div>
      </div>
      <button class="profile-button" type="button" aria-label="Sign out" @click="logout">
        {{ auth.user?.name?.charAt(0).toUpperCase() }}
      </button>
    </header>

    <section class="welcome-block">
      <p class="welcome-kicker">{{ formatDate() }}</p>
      <h1>Hi, {{ auth.user?.name?.split(' ')[0] || 'there' }}</h1>
      <p>Keep your attendance up to date.</p>
    </section>

    <section class="today-card" aria-live="polite">
      <div class="card-heading">
        <div>
          <p class="card-kicker">TODAY'S STATUS</p>
          <h2>{{ isLoading ? 'Checking...' : statusLabel }}</h2>
        </div>
        <span class="status-dot" :class="{ active: hasOpenSession }" />
      </div>

      <div v-if="error" class="app-error" role="alert">{{ error }}</div>
      <div v-else class="time-grid">
        <div>
          <span>Check in</span>
          <strong>{{ record?.sessions[0] ? formatTime(record.sessions[0].check_in_at) : '--:--' }}</strong>
        </div>
        <div>
          <span>Check out</span>
          <strong>{{ record?.sessions[0] ? formatTime(record.sessions[0].check_out_at) : '--:--' }}</strong>
        </div>
      </div>

      <AppButton type="button" variant="primary" icon="ArrowRight" full-width @click="router.push({ name: 'attendance' })">
        {{ actionLabel }}
      </AppButton>
    </section>

    <section class="quick-section">
      <div class="section-heading">
        <h2>Quick access</h2>
        <span>Today</span>
      </div>
      <button class="quick-card" type="button" @click="router.push({ name: 'attendance' })">
        <AppIcon name="CalendarCheck2" class="quick-icon" :size="20" :stroke-width="2" />
        <span>
          <strong>Attendance</strong>
          <small>Face ID and location verification</small>
        </span>
        <AppIcon name="ArrowRight" class="quick-arrow" :size="18" :stroke-width="2.2" aria-hidden="true" />
      </button>
    </section>

    <nav class="bottom-nav" aria-label="Employee navigation">
      <button class="bottom-link active" type="button">
        <AppIcon name="House" :size="18" :stroke-width="2" />
        <small>Home</small>
      </button>
      <button class="bottom-link" type="button" @click="router.push({ name: 'attendance' })">
        <AppIcon name="CalendarCheck2" :size="18" :stroke-width="2" />
        <small>Attendance</small>
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
  box-sizing: border-box;
  width: min(100%, 32rem);
  min-height: 100svh;
  margin: 0 auto;
  padding: 1rem 1rem 6rem;
  background: #f8f8f8;
  color: var(--text-h);
}

.employee-header,
.employee-brand,
.card-heading,
.section-heading {
  display: flex;
  align-items: center;
}

.employee-header {
  justify-content: space-between;
  padding: 0.35rem 0 1.5rem;
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
  font-size: 0.95rem;
}

.profile-button {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  margin: 0;
  padding: 0;
  place-items: center;
  border: 0;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
}

.welcome-block {
  padding: 1.25rem 0 1.5rem;
}

.welcome-block h1 {
  margin: 0.4rem 0 0.25rem;
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -0.05em;
}

.welcome-block p:last-child {
  color: var(--text);
}

.today-card {
  padding: 1.2rem;
  border-radius: 12px;
  background: var(--accent);
  color: #fff;
  box-shadow: 0 16px 30px rgba(235, 28, 36, 0.2);
}

.card-heading {
  justify-content: space-between;
}

.card-kicker {
  color: rgba(255, 255, 255, 0.7);
}

.card-heading h2 {
  margin: 0.35rem 0 0;
  color: #fff;
  font-size: 1.3rem;
  font-weight: 700;
}

.status-dot {
  width: 0.7rem;
  height: 0.7rem;
  border: 3px solid rgba(255, 255, 255, 0.35);
  border-radius: 50%;
}

.status-dot.active {
  background: #fff;
}

.time-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin: 1.3rem 0;
  padding-top: 1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.25);
}

.time-grid div {
  display: grid;
  gap: 0.25rem;
}

.time-grid span {
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.75rem;
}

.time-grid strong {
  font-size: 1.2rem;
}

.attendance-button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  margin: 0;
  padding: 0.85rem 1rem;
  border: 0;
  border-radius: 7px;
  background: #fff;
  color: var(--accent);
  font-weight: 700;
}

.quick-section {
  padding-top: 2rem;
}

.section-heading {
  justify-content: space-between;
  margin-bottom: 0.75rem;
}

.section-heading h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
}

.section-heading span {
  color: var(--text);
  font-size: 0.75rem;
}

.quick-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #fff;
  color: var(--text-h);
  text-align: left;
}

.quick-icon {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 7px;
  background: var(--accent-bg);
  color: var(--accent);
  font-size: 1.25rem;
}

.quick-card span:nth-child(2) {
  display: grid;
  gap: 0.2rem;
  flex: 1;
}

.quick-card small {
  color: var(--text);
}

.quick-arrow {
  color: var(--accent);
  font-size: 1.5rem;
}

.bottom-nav {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 2;
  display: flex;
  justify-content: center;
  gap: clamp(2rem, 15vw, 5rem);
  padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom));
  border-top: 1px solid var(--border);
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

.bottom-link span {
  font-size: 1.25rem;
}

.bottom-link small {
  font-size: 0.68rem;
  font-weight: 600;
}

.bottom-link.active {
  color: var(--accent);
}

.app-error {
  margin-top: 1rem;
  padding: 0.7rem;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.16);
  font-size: 0.85rem;
}

@media (min-width: 700px) {
  .employee-app {
    margin-top: 2rem;
    border: 1px solid var(--border);
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
</style>
