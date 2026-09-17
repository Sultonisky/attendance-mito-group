<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { RouterLink } from 'vue-router'
import AppButton from '../components/AppButton.vue'
import AppIcon from '../components/AppIcon.vue'
import { useAuthStore } from '../stores/auth'
import { fetchDashboardKpis } from '../services/dashboardApi'
import { ApiError } from '../services/apiClient'
import { usePermission } from '../features/auth/composables/usePermission'
import DashboardKpiCard from '../components/DashboardKpiCard.vue'
import type { DashboardKpiData } from '../types/dashboard'

const auth = useAuthStore()
const router = useRouter()
const { can, canAny } = usePermission()

const loading = ref(true)
const error = ref('')
const kpis = ref<DashboardKpiData | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await fetchDashboardKpis()
    kpis.value = response.data
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'login.admin' })
        return
      }

      if (err.status === 403) {
        error.value = 'You do not have permission to view the dashboard.'
        return
      }
    }

    error.value = err instanceof TypeError
      ? 'Unable to connect to the API. Check that Laravel is running and try again.'
      : 'Unable to load dashboard data. Please try again.'
  } finally {
    loading.value = false
  }
}

function formatDate(dateStr: string): string {
  const date = new Date(dateStr + 'T00:00:00')
  return date.toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login.admin' })
}

onMounted(() => {
  load()
})
</script>

<template>
  <main class="dashboard">
    <aside class="dashboard-sidebar">
      <RouterLink to="/dashboard" class="sidebar-brand">
        <img src="/images/mito.png" alt="MITO electronic" />
        <span>Attendance</span>
      </RouterLink>

      <div class="sidebar-section-label">Workspace</div>
      <nav class="sidebar-nav" aria-label="Main navigation">
        <RouterLink to="/dashboard" class="sidebar-link active">
          <AppIcon name="Grid2x2" class="nav-icon" :size="18" :stroke-width="2" />
          <span>Dashboard</span>
        </RouterLink>
        <RouterLink to="/attendance" class="sidebar-link">
          <AppIcon name="CalendarCheck2" class="nav-icon" :size="18" :stroke-width="2" />
          <span>Attendance</span>
        </RouterLink>
        <RouterLink v-if="canAny(['attendance.view', 'leave.view', 'overtime.view', 'penalty.view', 'monthly_recap.view'])" to="/reports" class="sidebar-link">
          <AppIcon name="BarChart3" class="nav-icon" :size="18" :stroke-width="2" />
          <span>Reports</span>
        </RouterLink>
        <RouterLink v-if="can('outsource_attendance.view')" to="/outsource-attendance" class="sidebar-link">
          <AppIcon name="BriefcaseBusiness" class="nav-icon" :size="18" :stroke-width="2" />
          <span>Outsource Attendance</span>
        </RouterLink>
      </nav>

      <div class="sidebar-footer">
        <div class="system-status"><span /> API connected</div>
        <AppButton type="button" variant="secondary" icon="LogOut" :disabled="auth.isLoading" @click="logout">
          Sign out
        </AppButton>
      </div>
    </aside>

    <section class="dashboard-main">
      <header class="dashboard-header">
        <div>
          <p class="header-kicker">MITO GROUP / PEOPLE OPERATIONS</p>
          <div class="header-title-row">
            <h2>Dashboard</h2>
            <span class="header-active">Live overview</span>
          </div>
        </div>
        <div v-if="auth.isAuthenticated" class="header-right">
          <div class="user-avatar">{{ auth.user?.name?.charAt(0).toUpperCase() }}</div>
          <div class="user-copy">
            <strong>{{ auth.user?.name }}</strong>
            <span>{{ auth.roles.join(', ') || 'User' }}</span>
          </div>
        </div>
      </header>

      <section v-if="error" class="dashboard-error" role="alert">
        <p>{{ error }}</p>
        <AppButton type="button" variant="secondary" @click="load">Retry</AppButton>
      </section>

      <section v-else-if="kpis" class="dashboard-content">
        <div class="dashboard-intro">
          <div>
            <p class="dashboard-eyebrow">OPERATIONS OVERVIEW</p>
            <h1>Good morning, {{ auth.user?.name?.split(' ')[0] || 'there' }}.</h1>
            <p class="dashboard-date">{{ formatDate(kpis.date) }} <span>•</span> Today&rsquo;s attendance pulse</p>
          </div>
          <RouterLink to="/attendance" class="dashboard-action">
            <span>Open attendance</span>
            <AppIcon name="ArrowRight" :size="16" :stroke-width="2.2" aria-hidden="true" />
          </RouterLink>
        </div>

        <div class="kpi-grid">
          <DashboardKpiCard label="Present" :value="kpis.present" />
          <DashboardKpiCard label="Absent" :value="kpis.absent" />
          <DashboardKpiCard label="Late" :value="kpis.late" />
          <DashboardKpiCard label="On Leave" :value="kpis.on_leave" />
        </div>
      </section>

      <section v-else class="dashboard-loading" aria-label="Loading dashboard data">
        <h1>Loading your overview</h1>
        <div class="kpi-grid">
          <DashboardKpiCard label="Present" :value="0" loading />
          <DashboardKpiCard label="Absent" :value="0" loading />
          <DashboardKpiCard label="Late" :value="0" loading />
          <DashboardKpiCard label="On Leave" :value="0" loading />
        </div>
      </section>
    </section>
  </main>
</template>

<style scoped>
.dashboard {
  min-height: 100svh;
  display: grid;
  grid-template-columns: 15rem minmax(0, 1fr);
  background: #fafafa;
  text-align: left;
}

.dashboard-sidebar {
  display: flex;
  flex-direction: column;
  padding: 1.5rem 1rem;
  border-right: 1px solid var(--border);
  background: var(--surface);
}

.sidebar-brand {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0 0.5rem 2.25rem;
  color: var(--text-h);
  font-weight: 700;
  text-decoration: none;
}

.sidebar-brand img {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 6px;
  object-fit: cover;
}

.sidebar-section-label,
.header-kicker {
  color: #9a9aa1;
  font-size: 0.64rem;
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
}

.sidebar-section-label {
  padding: 0 0.75rem 0.65rem;
}

.sidebar-nav {
  display: grid;
  gap: 0.35rem;
}

.sidebar-link {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.75rem;
  border-radius: 6px;
  color: var(--text);
  font-size: 0.88rem;
  font-weight: 600;
  text-decoration: none;
}

.sidebar-link:hover,
.sidebar-link.active {
  background: var(--accent-bg);
  color: var(--accent);
}

.nav-icon {
  width: 1.25rem;
  height: 1.25rem;
  color: currentColor;
  flex-shrink: 0;
}

.logout-inline {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
}

.sidebar-footer {
  display: grid;
  gap: 1rem;
  margin-top: auto;
  padding: 1rem 0.5rem 0;
  border-top: 1px solid var(--border);
}

.system-status {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--text);
  font-size: 0.72rem;
}

.system-status span {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 50%;
  background: #2c9c5b;
}

.sidebar-footer button {
  padding: 0.65rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: #fff;
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 600;
}

.dashboard-main {
  min-width: 0;
  padding: 1.5rem clamp(1rem, 3vw, 3rem) 3rem;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 2.5rem;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.header-active {
  padding: 0.25rem 0.5rem;
  border-radius: 999px;
  background: var(--accent-bg);
  font-size: 0.7rem;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.header-title-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.35rem;
}

.header-title-row h2 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
}

.user-avatar {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
}

.user-copy {
  display: grid;
  gap: 0.1rem;
  color: var(--text-h);
  font-size: 0.8rem;
}

.user-copy span {
  color: var(--text);
  font-size: 0.7rem;
  text-transform: uppercase;
}

.dashboard-error {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
}

.dashboard-error p {
  margin: 0 0 0.75rem;
  color: #b91c1c;
}

.dashboard-content h1 {
  margin: 0 0 0.25rem;
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 700;
  color: var(--text-h);
}

.dashboard-date {
  margin: 0 0 1.5rem;
  color: var(--text);
  font-size: 0.95rem;
}

.dashboard-date span {
  margin: 0 0.4rem;
  color: var(--accent);
}

.dashboard-intro {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 1.5rem;
  margin-bottom: 1.75rem;
}

.dashboard-eyebrow {
  margin: 0 0 0.5rem;
  color: var(--accent);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.14em;
}

.dashboard-action {
  display: inline-flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border-radius: 6px;
  background: var(--accent);
  color: #fff;
  font-size: 0.85rem;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
}

.dashboard-action:hover {
  background: #c9151c;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;
}

.dashboard-error button {
  margin-top: 0;
  padding: 0.65rem 1rem;
  border: 0;
  border-radius: 6px;
  background: var(--accent);
  color: #fff;
}

@media (min-width: 768px) {
  .kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 1024px) {
  .kpi-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

.dashboard-loading h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
  color: var(--text-h);
}

@media (max-width: 640px) {
  .dashboard {
    display: block;
  }

  .dashboard-sidebar {
    min-height: auto;
    padding: 1rem;
    border-right: 0;
    border-bottom: 1px solid var(--border);
  }

  .sidebar-brand {
    padding-bottom: 1rem;
  }

  .sidebar-nav {
    grid-template-columns: repeat(3, 1fr);
  }

  .sidebar-link {
    justify-content: center;
    padding: 0.6rem 0.35rem;
    font-size: 0.72rem;
  }

  .sidebar-section-label,
  .sidebar-footer {
    display: none;
  }

  .dashboard-main {
    padding-top: 1rem;
  }

  .dashboard-intro {
    align-items: flex-start;
    flex-direction: column;
  }

  .dashboard-action {
    width: 100%;
    justify-content: space-between;
    box-sizing: border-box;
  }
}
</style>
