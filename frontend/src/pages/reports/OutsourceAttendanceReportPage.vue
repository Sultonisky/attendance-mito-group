<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { fetchOutsourceAttendanceReport } from '../../services/reports/outsourceAttendanceReportApi'
import { fetchOutsourceCities, fetchOutsourceStores, fetchOutsourceOutsources } from '../../services/outsourceService'
import { ApiError } from '../../services/apiClient'
import ReportPagination from '../../components/ReportPagination.vue'
import type { OutsourceAttendanceReportRow, OutsourceAttendanceReportFilters, ReportSortOption } from '../../types/reports'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const loading = ref(false)
const error = ref('')
const data = ref<OutsourceAttendanceReportRow[]>([])
const meta = reactive({
  current_page: 1,
  per_page: 25,
  total: 0,
  last_page: 1,
})

const cities = ref<{ id: number; name: string }[]>([])
const stores = ref<{ id: number; name: string; city_id: number }[]>([])
const outsources = ref<{ id: number; name: string; outsource_code: string }[]>([])

const filters = reactive<OutsourceAttendanceReportFilters>({
  from: '',
  to: '',
  city_id: '',
  store_id: '',
  outsource_id: '',
  status: '',
  search: '',
  per_page: 25,
  sort: 'attendance_date',
  direction: 'desc',
})

const sortOptions: ReportSortOption[] = [
  { label: 'Attendance Date', value: 'attendance_date' },
  { label: 'Outsource Name', value: 'outsource_name' },
  { label: 'Status', value: 'status' },
  { label: 'Check In', value: 'check_in_at' },
  { label: 'Check Out', value: 'check_out_at' },
  { label: 'Duration', value: 'duration_minutes' },
  { label: 'Created At', value: 'created_at' },
]

const statusOptions = [
  { label: 'All Statuses', value: '' },
  { label: 'Present', value: 'present' },
  { label: 'Late', value: 'late' },
  { label: 'Incomplete', value: 'incomplete' },
  { label: 'Absent', value: 'absent' },
]

async function loadLookups(): Promise<void> {
  try {
    cities.value = await fetchOutsourceCities()
  } catch {
    // ignore lookup errors
  }
}

async function loadStores(): Promise<void> {
  if (!filters.city_id) {
    stores.value = []
    filters.store_id = ''
    return
  }

  try {
    stores.value = await fetchOutsourceStores(Number(filters.city_id))
  } catch {
    stores.value = []
  }

  filters.store_id = ''
}

async function loadOutsources(): Promise<void> {
  if (!filters.store_id) {
    outsources.value = []
    filters.outsource_id = ''
    return
  }

  try {
    outsources.value = await fetchOutsourceOutsources(Number(filters.store_id))
  } catch {
    outsources.value = []
  }

  filters.outsource_id = ''
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await fetchOutsourceAttendanceReport({
      from: filters.from,
      to: filters.to,
      city_id: filters.city_id || null,
      store_id: filters.store_id || null,
      outsource_id: filters.outsource_id || null,
      status: filters.status || null,
      search: filters.search || null,
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
        await router.push({ name: 'login.admin' })
        return
      }

      if (err.status === 403) {
        error.value = 'You do not have permission to view this report.'
        return
      }
    }

    error.value = 'Unable to load outsource attendance report. Please try again.'
  } finally {
    loading.value = false
  }
}

function goToPage(page: number): void {
  if (page < 1 || page > meta.last_page) return
  load()
}

function resetFilters(): void {
  filters.from = ''
  filters.to = ''
  filters.city_id = ''
  filters.store_id = ''
  filters.outsource_id = ''
  filters.status = ''
  filters.search = ''
  filters.per_page = 25
  filters.sort = 'attendance_date'
  filters.direction = 'desc'
  stores.value = []
  outsources.value = []
  load()
}

onMounted(() => {
  const queryFrom = route.query.from
  const queryTo = route.query.to
  if (typeof queryFrom === 'string') filters.from = queryFrom
  if (typeof queryTo === 'string') filters.to = queryTo
  loadLookups()
  load()
})
</script>

<template>
  <main class="report-page">
    <header class="report-header">
      <div class="header-left">
        <img class="report-brand-logo" src="/images/mito.png" alt="MITO electronic" />
        <RouterLink to="/dashboard" class="header-link">Dashboard</RouterLink>
        <span class="header-separator" aria-hidden="true">/</span>
        <span class="header-active">Outsource Attendance</span>
      </div>
      <div v-if="auth.isAuthenticated" class="header-right">
        <span>{{ auth.user?.name }} ({{ auth.user?.email }})</span>
      </div>
    </header>

    <section class="report-body">
      <form class="report-filters" @submit.prevent="load">
        <div class="filter-grid">
          <label class="filter-field">
            <span>From</span>
            <input
              type="date"
              :value="filters.from"
              @input="filters.from = ($event.target as HTMLInputElement).value"
            />
          </label>

          <label class="filter-field">
            <span>To</span>
            <input
              type="date"
              :value="filters.to"
              @input="filters.to = ($event.target as HTMLInputElement).value"
            />
          </label>

          <label class="filter-field">
            <span>City</span>
            <select
              :value="filters.city_id"
              @change="(filters.city_id = ($event.target as HTMLSelectElement).value), loadStores()"
            >
              <option value="">All Cities</option>
              <option v-for="city in cities" :key="city.id" :value="city.id">
                {{ city.name }}
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Store</span>
            <select
              :value="filters.store_id"
              :disabled="!filters.city_id"
              @change="(filters.store_id = ($event.target as HTMLSelectElement).value), loadOutsources()"
            >
              <option value="">All Stores</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">
                {{ store.name }}
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Outsource</span>
            <select
              :value="filters.outsource_id"
              :disabled="!filters.store_id"
              @input="filters.outsource_id = ($event.target as HTMLSelectElement).value"
            >
              <option value="">All Outsources</option>
              <option v-for="outsource in outsources" :key="outsource.id" :value="outsource.id">
                {{ outsource.name }} ({{ outsource.outsource_code }})
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Status</span>
            <select
              :value="filters.status"
              @change="filters.status = ($event.target as HTMLSelectElement).value"
            >
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Search</span>
            <input
              type="text"
              placeholder="Outsource name or code"
              :value="filters.search"
              @input="filters.search = ($event.target as HTMLInputElement).value"
            />
          </label>

          <label class="filter-field">
            <span>Per Page</span>
            <select
              :value="filters.per_page"
              @change="filters.per_page = Number(($event.target as HTMLSelectElement).value)"
            >
              <option :value="10">10</option>
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
            </select>
          </label>

          <label class="filter-field">
            <span>Sort By</span>
            <select
              :value="filters.sort"
              @change="filters.sort = ($event.target as HTMLSelectElement).value"
            >
              <option value="">Default</option>
              <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="filter-field">
            <span>Direction</span>
            <select
              :value="filters.direction"
              @change="filters.direction = ($event.target as HTMLSelectElement).value as 'asc' | 'desc'"
            >
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </label>
        </div>

        <div class="filter-actions">
          <button type="submit" :disabled="loading">Apply</button>
          <button type="button" :disabled="loading" @click="resetFilters">Reset</button>
        </div>
      </form>

      <section v-if="error" class="report-error" role="alert">
        <p>{{ error }}</p>
        <button type="button" @click="load">Retry</button>
      </section>

      <section v-else-if="data.length" class="report-table-wrapper">
        <table class="report-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Outsource</th>
              <th>City</th>
              <th>Store</th>
              <th>Clock In</th>
              <th>Clock Out</th>
              <th>Duration</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data" :key="row.attendance_id">
              <td>{{ row.attendance_date }}</td>
              <td>{{ row.outsource?.name }} <span v-if="row.outsource?.code" class="text-muted">({{ row.outsource.code }})</span></td>
              <td>{{ row.city?.name || '--' }}</td>
              <td>{{ row.store?.name || '--' }}</td>
              <td>{{ row.check_in_at ? new Date(row.check_in_at + 'Z').toLocaleString() : '--:--' }}</td>
              <td>{{ row.check_out_at ? new Date(row.check_out_at + 'Z').toLocaleString() : '--:--' }}</td>
              <td>{{ row.duration_minutes ? row.duration_minutes + ' min' : '--' }}</td>
              <td>
                <span class="status-badge" :class="'status-' + row.status">
                  {{ row.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <ReportPagination :meta="meta" :loading="loading" @update:page="goToPage" />
      </section>

      <section v-else-if="!loading" class="report-empty">
        <p>No outsource attendance records found for the selected filters.</p>
      </section>

      <section v-else class="report-loading" aria-label="Loading outsource attendance report">
        <p>Loading outsource attendance report...</p>
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

.report-brand-logo {
  width: 2rem;
  height: 2rem;
  border-radius: 5px;
  object-fit: cover;
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
}

.report-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.report-filters {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1rem;
  background: var(--bg);
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

.filter-field input:disabled,
.filter-field select:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.filter-actions {
  margin-top: 0.75rem;
  display: flex;
  gap: 0.5rem;
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
  overflow: hidden;
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
}

.report-table th {
  background: var(--code-bg);
  color: var(--text-h);
  font-weight: 600;
}

.report-table tbody tr:last-child td {
  border-bottom: none;
}

.text-muted {
  color: var(--text);
  font-size: 0.8rem;
}

.status-badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.8rem;
  text-transform: capitalize;
}

.status-present {
  background: #dcfce7;
  color: #166534;
}

.status-late {
  background: #fef9c3;
  color: #854d0e;
}

.status-incomplete {
  background: #fee2e2;
  color: #991b1c;
}

.status-absent {
  background: #f3f4f6;
  color: #374151;
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
