<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { fetchDashboardKpis } from '../services/dashboardApi'
import { ApiError } from '../services/apiClient'
import DashboardKpiCard from '../components/DashboardKpiCard.vue'
import type { DashboardKpiData } from '../types/dashboard'

const auth = useAuthStore()
const router = useRouter()

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
        await router.push({ name: 'login', query: { redirect: '/dashboard' } })
        return
      }

      if (err.status === 403) {
        error.value = 'You do not have permission to view the dashboard.'
        return
      }
    }

    error.value = 'Unable to load dashboard data. Please try again.'
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
  await router.push({ name: 'login' })
}

onMounted(() => {
  load()
})
</script>

<template>
  <main class="dashboard">
    <header class="dashboard-header">
      <div class="header-left">
        <RouterLink to="/" class="header-link">Home</RouterLink>
        <span class="header-separator" aria-hidden="true">/</span>
        <span class="header-active">Dashboard</span>
      </div>
      <div v-if="auth.isAuthenticated" class="header-right">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
        <button type="button" :disabled="auth.isLoading" @click="logout">
          Logout
        </button>
      </div>
    </header>

    <section v-if="error" class="dashboard-error" role="alert">
      <p>{{ error }}</p>
      <button type="button" @click="load">Retry</button>
    </section>

    <section v-else-if="kpis" class="dashboard-content">
      <h1>Attendance Summary</h1>
      <p class="dashboard-date">{{ formatDate(kpis.date) }}</p>

      <div class="kpi-grid">
        <DashboardKpiCard label="Present" :value="kpis.present" />
        <DashboardKpiCard label="Absent" :value="kpis.absent" />
        <DashboardKpiCard label="Late" :value="kpis.late" />
        <DashboardKpiCard label="On Leave" :value="kpis.on_leave" />
      </div>
    </section>

    <section v-else class="dashboard-loading" aria-label="Loading dashboard data">
      <h1>Attendance Summary</h1>
      <div class="kpi-grid">
        <DashboardKpiCard label="Present" :value="0" loading />
        <DashboardKpiCard label="Absent" :value="0" loading />
        <DashboardKpiCard label="Late" :value="0" loading />
        <DashboardKpiCard label="On Leave" :value="0" loading />
      </div>
    </section>
  </main>
</template>

<style scoped>
.dashboard {
  max-width: 1126px;
  margin: 0 auto;
  padding: 1.25rem clamp(1rem, 3vw, 2rem) 3rem;
  text-align: left;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1.1rem;
  margin-bottom: 2.25rem;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.header-link {
  color: var(--text);
  text-decoration: none;
}

.header-link:hover {
  color: var(--accent);
}

.header-separator {
  color: var(--text);
}

.header-active {
  color: var(--accent);
  font-weight: 700;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
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
</style>
