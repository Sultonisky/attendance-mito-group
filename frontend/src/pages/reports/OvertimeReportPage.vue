<script setup lang="ts">
import { onMounted, ref, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { fetchOvertimeReport } from '../../services/reports/overtimeReportApi'
import { ApiError } from '../../services/apiClient'
import ReportFilterBar from '../../components/ReportFilterBar.vue'
import ReportPagination from '../../components/ReportPagination.vue'
import type { OvertimeReportRow, ReportSortOption } from '../../types/reports'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const data = ref<OvertimeReportRow[]>([])
const meta = reactive({
  current_page: 1,
  per_page: 25,
  total: 0,
  last_page: 1,
})

const filters = reactive({
  from: '',
  to: '',
  employee_id: '',
  per_page: 25,
  sort: 'date',
  direction: 'desc' as 'asc' | 'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Date', value: 'date' },
  { label: 'Status', value: 'status' },
  { label: 'Employee Name', value: 'employee_name' },
  { label: 'Potential Minutes', value: 'potential_minutes' },
  { label: 'Approved Minutes', value: 'approved_minutes' },
  { label: 'Created At', value: 'created_at' },
]

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await fetchOvertimeReport({
      from: filters.from,
      to: filters.to,
      employee_id: filters.employee_id || null,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
    })
    data.value = response.data
    meta.current_page = response.meta.current_page
    meta.per_page = response.meta.per_page
    meta.total = response.meta.total
    meta.last_page = response.meta.last_page
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'login', query: { redirect: '/reports/overtime' } })
        return
      }

      if (err.status === 403) {
        error.value = 'You do not have permission to view this report.'
        return
      }
    }

    error.value = 'Unable to load overtime report. Please try again.'
  } finally {
    loading.value = false
  }
}

function goToPage(page: number): void {
  if (page < 1 || page > meta.last_page) return
  load()
}

onMounted(() => {
  const queryFrom = route.query.from
  const queryTo = route.query.to
  if (typeof queryFrom === 'string') filters.from = queryFrom
  if (typeof queryTo === 'string') filters.to = queryTo
  load()
})
</script>

<template>
  <main class="report-page">
    <header class="report-header">
      <div class="header-left">
        <RouterLink to="/reports" class="header-link">Reports</RouterLink>
        <span class="header-separator" aria-hidden="true">/</span>
        <span class="header-active">Overtime</span>
      </div>
      <div v-if="auth.isAuthenticated" class="header-right">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
      </div>
    </header>

    <section class="report-body">
      <ReportFilterBar
        :filters="filters"
        :loading="loading"
        :sort-options="sortOptions"
        @update:from="filters.from = $event"
        @update:to="filters.to = $event"
        @update:employee_id="filters.employee_id = $event"
        @update:per_page="filters.per_page = $event"
        @update:sort="filters.sort = $event"
        @update:direction="filters.direction = $event"
        @search="load"
      />

      <section v-if="error" class="report-error" role="alert">
        <p>{{ error }}</p>
        <button type="button" @click="load">Retry</button>
      </section>

      <section v-else-if="data.length" class="report-table-wrapper">
        <table class="report-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Date</th>
              <th>Potential</th>
              <th>Requested</th>
              <th>Approved</th>
              <th>Actual</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data" :key="row.id">
              <td>{{ row.employee_name }}</td>
              <td>{{ row.date }}</td>
              <td>{{ row.potential_minutes }}</td>
              <td>{{ row.requested_minutes }}</td>
              <td>{{ row.approved_minutes }}</td>
              <td>{{ row.actual_minutes }}</td>
              <td>{{ row.status }}</td>
            </tr>
          </tbody>
        </table>

        <ReportPagination :meta="meta" :loading="loading" @update:page="goToPage" />
      </section>

      <section v-else-if="!loading" class="report-empty">
        <p>No overtime records found for the selected filters.</p>
      </section>

      <section v-else class="report-loading" aria-label="Loading overtime report">
        <p>Loading overtime report...</p>
      </section>
    </section>
  </main>
</template>

<style scoped>
.report-page {
  max-width: 1126px;
  margin: 0 auto;
  padding: 1.5rem;
  text-align: left;
}

.report-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1rem;
  margin-bottom: 1.5rem;
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
  color: var(--text-h);
}

.header-separator {
  color: var(--text);
}

.header-active {
  color: var(--text-h);
  font-weight: 500;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.report-error {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
}

.report-error p {
  margin: 0 0 0.75rem;
  color: #b91c1c;
}

.report-table-wrapper {
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: auto;
  background: var(--bg);
}

.report-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.report-table th,
.report-table td {
  padding: 0.75rem 1rem;
  text-align: left;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}

.report-table th {
  background: var(--code-bg);
  color: var(--text-h);
  font-weight: 600;
}

.report-table tbody tr:last-child td {
  border-bottom: none;
}

.report-empty {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
  color: var(--text);
}

.report-loading {
  color: var(--text);
}
</style>
