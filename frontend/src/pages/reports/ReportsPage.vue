<script setup lang="ts">
import { usePermission } from '../../features/auth/composables/usePermission'
import { RouterLink } from 'vue-router'

const { can } = usePermission()
</script>

<template>
  <main class="reports-page">
    <header class="reports-header">
      <h1>Reports</h1>
      <p class="reports-subtitle">Attendance and HR reports</p>
    </header>

    <div class="reports-grid">
      <RouterLink to="/reports/attendance" class="report-card">
        <h2>Attendance Report</h2>
        <p>Daily attendance status summaries for a selected date range.</p>
      </RouterLink>

      <RouterLink
        v-if="can('leave.view')"
        to="/reports/leave"
        class="report-card"
      >
        <h2>Leave Report</h2>
        <p>Leave requests, types, durations, and approval status.</p>
      </RouterLink>

      <RouterLink
        v-if="can('overtime.view')"
        to="/reports/overtime"
        class="report-card"
      >
        <h2>Overtime Report</h2>
        <p>Potential, requested, approved, and actual overtime records.</p>
      </RouterLink>

      <RouterLink
        v-if="can('penalty.view')"
        to="/reports/penalties"
        class="report-card"
      >
        <h2>Penalty Report</h2>
        <p>Penalty rules, violations, points, and adjustment history.</p>
      </RouterLink>

      <RouterLink
        v-if="
          can('monthly_recap.view') ||
          can('monthly_recap.generate') ||
          can('monthly_recap.review') ||
          can('monthly_recap.finalize') ||
          can('monthly_recap.export')
        "
        to="/reports/monthly-recaps"
        class="report-card"
      >
        <h2>Monthly Recap</h2>
        <p>Persisted monthly attendance summaries and detail snapshots.</p>
      </RouterLink>
    </div>
  </main>
</template>

<style scoped>
.reports-page {
  max-width: 1126px;
  margin: 0 auto;
  padding: 1.5rem;
  text-align: left;
}

.reports-header {
  margin-bottom: 1.5rem;
}

.reports-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
  color: var(--text-h);
}

.reports-subtitle {
  margin: 0;
  color: var(--text);
  font-size: 0.95rem;
}

.reports-grid {
  display: grid;
  grid-template-columns: repeat(1, 1fr);
  gap: 1rem;
}

@media (min-width: 768px) {
  .reports-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 1024px) {
  .reports-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.report-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
  text-decoration: none;
  color: inherit;
  display: block;
}

.report-card:hover {
  box-shadow: var(--shadow);
}

.report-card h2 {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
  color: var(--text-h);
}

.report-card p {
  margin: 0;
  font-size: 0.9rem;
  color: var(--text);
}
</style>
