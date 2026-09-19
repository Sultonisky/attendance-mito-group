<script setup lang="ts">
import { computed } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { usePermission } from '../features/auth/composables/usePermission'
import MitoTeamsMenu from '../components/MitoTeamsMenu.vue'
import MitoUserMenu from '../components/MitoUserMenu.vue'
import NotificationsSlideover from '../components/NotificationsSlideover.vue'

const { can, canAny } = usePermission()
const links = computed<NavigationMenuItem[][]>(() => [
  // Primary nav
  [
    { label: 'Overview',          icon: 'i-lucide-layout-grid',       to: '/dashboard', exact: true               },
    can('attendance.view')    && { label: 'Attendance',        icon: 'i-lucide-calendar-check-2',  to: '/dashboard/reports/attendance', exact: true },
    can('leave.view')         && { label: 'Leave',             icon: 'i-lucide-calendar-off',      to: '/dashboard/reports/leave', exact: true },
    can('overtime.view')      && { label: 'Overtime',          icon: 'i-lucide-bar-chart-3',       to: '/dashboard/reports/overtime', exact: true },
    can('penalty.view')       && { label: 'Penalties',         icon: 'i-lucide-triangle-alert',    to: '/dashboard/reports/penalties', exact: true },
    canAny(['monthly_recap.view','monthly_recap.generate','monthly_recap.review','monthly_recap.finalize','monthly_recap.export'])
      && { label: 'Monthly recap', icon: 'i-lucide-file-text',        to: '/dashboard/reports/monthly-recaps', exact: true },
    can('outsource_attendance.view') && { label: 'Outsource',  icon: 'i-lucide-briefcase-business', to: '/dashboard/outsource-attendance', exact: true },
  ].filter(Boolean) as NavigationMenuItem[],

])
</script>

<template>
  <UDashboardGroup unit="rem" storage="local" storage-key="mito-sidebar" class="min-h-dvh">
    <!-- ── SIDEBAR ──────────────────────────────────────────────────── -->
    <UDashboardSidebar
      id="mito-admin"
      collapsible
      resizable
      class="min-h-0"
      :ui="{
        root: 'min-h-0',
        footer: 'lg:border-t lg:border-[var(--ui-border)]',
      }"
    >
      <!-- Brand header -->
      <template #header="{ collapsed }">
        <MitoTeamsMenu :collapsed="collapsed" />
      </template>

      <!-- Nav items -->
      <template #default="{ collapsed }">
        <UDashboardSearchButton
          :collapsed="collapsed"
          class="bg-transparent ring-[var(--ui-border)]"
        />

        <!-- Primary workspace nav -->
        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
          class="space-y-1.5"
          :ui="{
            item: 'rounded-lg',
            link: 'gap-3 px-2.5 py-2.5',
            linkLeadingIcon: 'size-5 shrink-0',
            linkLabel: 'text-[13px] font-semibold tracking-[-0.01em]'
          }"
        />

      </template>

      <!-- User menu footer -->
      <template #footer="{ collapsed }">
        <MitoUserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <!-- ── COMMAND PALETTE / SEARCH ──────────────────────────────── -->
    <UDashboardSearch
      :groups="[{
        id: 'nav',
        label: 'Go to',
        items: links.flat(),
      }]"
    />

    <!-- ── PAGE CONTENT ──────────────────────────────────────────── -->
    <RouterView />

    <!-- ── NOTIFICATIONS SLIDEOVER ───────────────────────────────── -->
    <NotificationsSlideover />
  </UDashboardGroup>
</template>
