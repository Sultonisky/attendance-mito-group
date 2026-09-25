<script setup lang="ts">
import { computed } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { usePermission } from '../features/auth/composables/usePermission'
import MitoTeamsMenu from '../components/MitoTeamsMenu.vue'
import MitoUserMenu from '../components/MitoUserMenu.vue'
import NotificationsSlideover from '../components/NotificationsSlideover.vue'

const { can, canAny, hasRole } = usePermission()
const isSuperAdmin = computed(() => hasRole('SUPER_ADMIN'))
const links = computed<NavigationMenuItem[][]>(() => [
  // Primary nav
  [
    { label: 'Overview', icon: 'i-lucide-layout-grid', to: '/dashboard', exact: true },
    canAny(['employees.view', 'attendance.view', 'attendance.correction.view', 'leave.view', 'overtime.view', 'penalty.view']) && {
      label: 'Employees',
      icon: 'i-lucide-contact',
      children: [
        can('employees.view') && { label: 'Person list', icon: 'i-lucide-users', to: '/dashboard/employees', exact: true },
        can('attendance.view') && { label: 'Attendance', icon: 'i-lucide-calendar-check-2', to: '/dashboard/reports/attendance', exact: true },
        can('attendance.correction.view') && { label: 'Corrections', icon: 'i-lucide-file-text', to: '/dashboard/reports/attendance-corrections', exact: true },
        can('leave.view') && { label: 'Leave', icon: 'i-lucide-calendar-off', to: '/dashboard/reports/leave', exact: true },
        can('overtime.view') && { label: 'Overtime', icon: 'i-lucide-clock-arrow-up', to: '/dashboard/reports/overtime', exact: true },
        can('penalty.view') && { label: 'Penalties', icon: 'i-lucide-triangle-alert', to: '/dashboard/reports/penalties', exact: true },
      ].filter(Boolean) as NavigationMenuItem[],
    },
    can('outsource_attendance.view') && {
      label: 'Outsource',
      icon: 'i-lucide-briefcase-business',
      children: [
        { label: 'Person list', icon: 'i-lucide-users', to: '/dashboard/outsource-persons', exact: true },
        { label: 'Attendance list', icon: 'i-lucide-calendar-check-2', to: '/dashboard/outsource-attendance', exact: true },
        { label: 'Work locations', icon: 'i-lucide-map-pin', to: '/dashboard/outsource-work-locations', exact: true },
      ],
    },
    canAny(['monthly_recap.view', 'monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize', 'monthly_recap.export'])
      && { label: 'Monthly recap', icon: 'i-lucide-file-text', to: '/dashboard/reports/monthly-recaps', exact: true },
    can('user.view') && { label: 'Users', icon: 'i-lucide-shield-user', to: '/dashboard/users', exact: true },
    can('permission.view') && { label: 'Permissions', icon: 'i-lucide-shield-check', to: '/dashboard/permissions', exact: true },
    can('audit.view') && { label: 'Audit Logs', icon: 'i-lucide-scroll-text', to: '/dashboard/audit-logs', exact: true },
    hasRole('SUPER_ADMIN') && { label: 'Systems', icon: 'i-lucide-server-cog', to: '/dashboard/systems', exact: true },
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
      :collapsed-size="5"
      :min-size="15"
      :default-size="17"
      :max-size="22"
      class="min-h-0"
      :ui="{
        root: [
          'min-h-0',
          'data-[collapsed=true]:min-w-20',
          'data-[collapsed=true]:[&_[data-slot=header]]:px-2',
          'data-[collapsed=true]:[&_[data-slot=header]]:justify-center',
          'data-[collapsed=true]:[&_[data-slot=body]]:px-2',
          'data-[collapsed=true]:[&_[data-slot=body]]:items-center',
          'data-[collapsed=true]:[&_[data-slot=footer]]:px-2',
          'data-[collapsed=true]:[&_[data-slot=footer]]:justify-center',
        ].join(' '),
        header: 'px-2.5',
        body: 'gap-3 px-2.5',
        footer: 'px-2.5 lg:border-t lg:border-[var(--ui-border)]',
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
          :class="collapsed ? 'mx-auto' : undefined"
        />

        <!-- Primary workspace nav -->
        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
          :class="collapsed ? 'w-full items-center space-y-1' : 'space-y-1.5'"
          :ui="{
            item: collapsed ? 'w-full flex justify-center' : 'rounded-lg',
            link: collapsed
              ? 'justify-center gap-0 size-9 p-0 rounded-lg'
              : 'gap-3 px-2.5 py-2.5 rounded-lg',
            linkLeadingIcon: 'size-5 shrink-0',
            linkLabel: 'text-[13px] font-semibold tracking-[-0.01em]',
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

    <!-- ── NOTIFICATIONS SLIDEOVER (SUPER_ADMIN only) ─────────────── -->
    <NotificationsSlideover v-if="isSuperAdmin" />
  </UDashboardGroup>
</template>
