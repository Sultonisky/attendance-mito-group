<script setup lang="ts">
import type { ReportSortOption } from '../types/reports'

defineProps<{
  filters: {
    from: string
    to: string
    employee_id: string
    per_page: number
    sort: string
    direction: 'asc' | 'desc'
  }
  loading?: boolean
  sortOptions: ReportSortOption[]
}>()

const emit = defineEmits<{
  'update:from': [value: string]
  'update:to': [value: string]
  'update:employee_id': [value: string]
  'update:per_page': [value: number]
  'update:sort': [value: string]
  'update:direction': [value: 'asc' | 'desc']
  search: []
}>()
</script>

<template>
  <form class="report-filters" @submit.prevent="emit('search')">
    <div class="filter-grid">
      <label class="filter-field">
        <span>From</span>
        <input
          type="date"
          :value="filters.from"
          @input="emit('update:from', ($event.target as HTMLInputElement).value)"
        />
      </label>

      <label class="filter-field">
        <span>To</span>
        <input
          type="date"
          :value="filters.to"
          @input="emit('update:to', ($event.target as HTMLInputElement).value)"
        />
      </label>

      <label class="filter-field">
        <span>Employee ID</span>
        <input
          type="number"
          min="1"
          placeholder="Employee ID"
          :value="filters.employee_id"
          @input="emit('update:employee_id', ($event.target as HTMLInputElement).value)"
        />
      </label>

      <label class="filter-field">
        <span>Per Page</span>
        <select
          :value="filters.per_page"
          @change="emit('update:per_page', Number(($event.target as HTMLSelectElement).value))"
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
          @change="emit('update:sort', ($event.target as HTMLSelectElement).value)"
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
          @change="emit('update:direction', ($event.target as HTMLSelectElement).value as 'asc' | 'desc')"
        >
          <option value="asc">Ascending</option>
          <option value="desc">Descending</option>
        </select>
      </label>
    </div>

    <div class="filter-actions">
      <button type="submit" :disabled="loading">Apply Filters</button>
    </div>
  </form>
</template>

<style scoped>
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
