<script setup lang="ts">
import { onMounted, ref, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '../../components/AppButton.vue'
import { useAuthStore } from '../../stores/auth'
import { fetchMonthlyRecaps } from '../../services/reports/monthlyRecapApi'
import { ApiError } from '../../services/apiClient'
import ReportPagination from '../../components/ReportPagination.vue'
import type { MonthlyRecapRow } from '../../types/reports'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const data = ref<MonthlyRecapRow[]>([])
const meta = reactive({
  current_page: 1,
  per_page: 30,
  total: 0,
  last_page: 1,
})

const employeeId = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const params: Record<string, string | number | boolean | null | undefined> = {}
    if (employeeId.value) params.employee_id = employeeId.value

    const response = await fetchMonthlyRecaps(params)
    data.value = response.data

    if (response.meta) {
      meta.current_page = (response.meta.current_page as number) ?? 1
      meta.per_page = (response.meta.per_page as number) ?? 30
      meta.total = (response.meta.total as number) ?? 0
      meta.last_page = (response.meta.last_page as number) ?? 1
    } else {
      meta.current_page = 1
      meta.per_page = 30
      meta.total = response.data.length
      meta.last_page = 1
    }
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'login.admin' })
        return
      }

      if (err.status === 403) {
        error.value = 'You do not have permission to view this report.'
        return
      }
    }

    error.value = 'Unable to load monthly recaps. Please try again.'
  } finally {
    loading.value = false
  }
}

function goToPage(page: number): void {
  if (page < 1 || page > meta.last_page) return
  load()
}

onMounted(() => {
  const queryEmployeeId = route.query.employee_id
  if (typeof queryEmployeeId === 'string') employeeId.value = queryEmployeeId
  load()
})
</script>

<template>
  <main class="report-page">
    <header class="report-header">
      <div class="header-left">
        <img class="report-brand-logo" src="/images/mito.png" alt="MITO electronic" />
        <RouterLink to="/reports" class="header-link">Reports</RouterLink>
        <span class="header-separator" aria-hidden="true">/</span>
        <span class="header-active">Monthly Recaps</span>
      </div>
      <div v-if="auth.isAuthenticated" class="header-right">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
      </div>
    </header>

    <section class="report-body">
      <form class="report-filters" @submit.prevent="load">
        <div class="filter-grid">
          <label class="filter-field">
            <span>Employee ID</span>
            <input
              type="number"
              min="1"
              placeholder="Employee ID"
              :value="employeeId"
              @input="employeeId = ($event.target as HTMLInputElement).value"
            />
          </label>
        </div>
        <div class="filter-actions">
          <AppButton type="submit" :disabled="loading">Apply Filters</AppButton>
        </div>
      </form>

      <section v-if="error" class="report-error" role="alert">
        <p>{{ error }}</p>
        <AppButton type="button" variant="secondary" @click="load">Retry</AppButton>
      </section>

      <section v-else-if="data.length" class="report-table-wrapper">
        <table class="report-table">
          <thead>
            <tr>
              <th>Period</th>
              <th>Status</th>
              <th>Finalized At</th>
              <th>Exported At</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data" :key="row.id">
              <td>{{ row.period }}</td>
              <td>{{ row.status }}</td>
              <td>{{ row.finalized_at ? new Date(row.finalized_at + 'Z').toLocaleString() : '-' }}</td>
              <td>{{ row.exported_at ? new Date(row.exported_at + 'Z').toLocaleString() : '-' }}</td>
            </tr>
          </tbody>
        </table>

        <ReportPagination :meta="meta" :loading="loading" @update:page="goToPage" />
      </section>

      <section v-else-if="!loading" class="report-empty">
        <p>No monthly recap records found for the selected filters.</p>
      </section>

      <section v-else class="report-loading" aria-label="Loading monthly recap report">
        <p>Loading monthly recap report...</p>
      </section>
    </section>
  </main>
</template>

<style scoped>
@import '../../styles/report-page.css';

.report-filters {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1rem;
  background: var(--bg);
  margin-bottom: 1rem;
}

.filter-grid {
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 0.75rem;
}

@media (min-width: 768px) {
  .filter-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.filter-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.875rem;
  color: var(--text);
}

.filter-field span {
  font-weight: 500;
}

.filter-field input,
.filter-field select {
  padding: 0.5rem;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--bg);
  color: var(--text-h);
}

.filter-actions {
  margin-top: 0.75rem;
}

.filter-actions button {
  padding: 0.5rem 1rem;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--bg);
  color: var(--text-h);
  cursor: pointer;
}

.filter-actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
