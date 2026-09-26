<script setup lang="ts">
import { computed } from 'vue'
import { usePermission } from '../../features/auth/composables/usePermission'

const { can, canAny } = usePermission()

const workspaces = computed(() => [
  {
    label: 'Attendance',
    description: 'Review daily attendance records and check-in status.',
    icon: 'i-lucide-calendar-check-2',
    to: '/dashboard/reports/attendance',
    visible: can('attendance.view'),
    cta: 'Open',
  },
  {
    label: 'Attendance corrections',
    description: 'Approve or reject forgotten clock in/out requests.',
    icon: 'i-lucide-file-text',
    to: '/dashboard/reports/attendance-corrections',
    visible: can('attendance.correction.view'),
    cta: 'Manage',
  },
  {
    label: 'Leave',
    description: 'Review requests, balances, and approval status.',
    icon: 'i-lucide-calendar-off',
    to: '/dashboard/reports/leave',
    visible: can('leave.view'),
    cta: 'Manage',
  },
  {
    label: 'Overtime',
    description: 'Review requests, approvals, and actual hours logged.',
    icon: 'i-lucide-bar-chart-3',
    to: '/dashboard/reports/overtime',
    visible: can('overtime.view'),
    cta: 'Manage',
  },
  {
    label: 'Penalties',
    description: 'Review violations, adjustments, and penalty history.',
    icon: 'i-lucide-triangle-alert',
    to: '/dashboard/reports/penalties',
    visible: can('penalty.view'),
    cta: 'Manage',
  },
  {
    label: 'Outsource attendance',
    description: 'Review outsource worker attendance records and status.',
    icon: 'i-lucide-briefcase-business',
    to: '/dashboard/outsource-attendance',
    visible: can('outsource_attendance.view'),
    cta: 'Open',
  },
  {
    label: 'Monthly recap',
    description: 'Generate, review, finalize, and export monthly recaps.',
    icon: 'i-lucide-file-text',
    to: '/dashboard/reports/monthly-recaps',
    visible: canAny([
      'monthly_recap.view', 'monthly_recap.generate',
      'monthly_recap.review', 'monthly_recap.finalize', 'monthly_recap.export',
    ]),
    cta: 'Manage',
  },
].filter(w => w.visible))
</script>

<template>
  <UDashboardPanel id="reports-index">
    <template #header>
      <UDashboardNavbar title="Report workspaces">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UBadge color="neutral" variant="subtle">
            {{ workspaces.length }} workspaces
          </UBadge>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-6">
        <!-- Intro -->
        <div>
          <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[var(--ui-text-dimmed)]">
            People Operations
          </p>
          <h2 class="mt-1 text-xl font-bold text-[var(--ui-text-highlighted)]">
            Report workspaces
          </h2>
          <p class="mt-1 text-sm text-[var(--ui-text-muted)]">
            Review records, apply filters, and run the actions available to your role.
          </p>
        </div>

        <!-- Workspace cards -->
        <UPageGrid class="sm:grid-cols-2 xl:grid-cols-3">
          <UPageCard
            v-for="ws in workspaces"
            :key="ws.to"
            :to="ws.to"
            :icon="ws.icon"
            :title="ws.label"
            :description="ws.description"
            variant="outline"
            :ui="{
              leading: 'p-2.5 rounded-xl bg-primary/10 ring ring-inset ring-primary/25',
              title: 'font-semibold',
            }"
            class="group transition hover:border-primary/40 hover:shadow-sm"
          >
            <template #footer>
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--ui-text-muted)] transition group-hover:text-primary">
                {{ ws.cta }}
                <UIcon name="i-lucide-arrow-right" class="size-3.5 transition-transform group-hover:translate-x-0.5" />
              </span>
            </template>
          </UPageCard>
        </UPageGrid>
      </div>
    </template>
  </UDashboardPanel>
</template>
