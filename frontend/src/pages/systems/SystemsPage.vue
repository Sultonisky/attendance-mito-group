<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../../services/apiClient'
import { fetchSystemsHealth } from '../../services/systemsApi'
import {
  fetchMaintenanceFlag,
  type MaintenanceFlag,
} from '../../services/maintenanceFlag'
import type {
  SystemServiceStatus,
  SystemsHealthData,
} from '../../types/dashboard'
import { formatAttendanceTime } from '../../utils/attendanceDateTime'

const router = useRouter()

const loading = ref(true)
const refreshing = ref(false)
const error = ref('')
const health = ref<SystemsHealthData | null>(null)
const spaMaintenance = ref<MaintenanceFlag | null>(null)

const overallLabel = computed(() => {
  const status = health.value?.overall_status
  if (status === 'ok') return 'Operational'
  if (status === 'degraded') return 'Degraded'
  if (status === 'fail') return 'Critical'
  return 'Unknown'
})

const overallColor = computed<'success' | 'warning' | 'error' | 'neutral'>(() => {
  const status = health.value?.overall_status
  if (status === 'ok') return 'success'
  if (status === 'degraded') return 'warning'
  if (status === 'fail') return 'error'
  return 'neutral'
})

const overallTitle = computed(() => {
  const status = health.value?.overall_status
  if (status === 'ok') return 'Everything is up'
  if (status === 'degraded') return 'Optional services need attention'
  if (status === 'fail') return 'Service issues detected'
  return 'Unable to determine status'
})

const spaMaintenanceActive = computed(() => spaMaintenance.value?.enabled === true)
const apiMaintenanceActive = computed(() => health.value?.runtime.maintenance === true)

function statusColor(status: SystemServiceStatus): 'success' | 'warning' | 'error' {
  if (status === 'ok') return 'success'
  if (status === 'warn') return 'warning'
  return 'error'
}

function statusLabel(status: SystemServiceStatus): string {
  if (status === 'ok') return 'Online'
  if (status === 'warn') return 'Warning'
  return 'Offline'
}

function statusDotClass(status: SystemServiceStatus): string {
  if (status === 'ok') return 'bg-emerald-500'
  if (status === 'warn') return 'bg-amber-500'
  return 'bg-red-500'
}

function formatRefreshedAt(iso: string | undefined): string {
  return formatAttendanceTime(iso)
}

async function load(isRefresh = false): Promise<void> {
  if (isRefresh) {
    refreshing.value = true
  } else {
    loading.value = true
  }
  error.value = ''

  try {
    const [response, flag] = await Promise.all([
      fetchSystemsHealth(),
      fetchMaintenanceFlag(true),
    ])
    health.value = response.data
    spaMaintenance.value = flag
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'error.unauthorized' })
        return
      }
      if (err.status === 403) {
        await router.push({ name: 'error.forbidden' })
        return
      }
    }
    error.value = err instanceof TypeError
      ? 'Unable to connect to the API. Check that Laravel is running.'
      : 'Unable to load systems health. Please try again.'
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <UDashboardPanel id="systems" class="min-w-0">
    <template #header>
      <UDashboardNavbar title="Systems" :ui="{ right: 'gap-3' }">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-refresh-cw"
            :loading="refreshing"
            :disabled="loading"
            aria-label="Refresh systems status"
            @click="load(true)"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4 sm:gap-6 p-4 sm:p-6 lg:p-8 min-w-0">
        <div class="space-y-1">
          <p class="text-xs text-muted uppercase tracking-wide">Super Admin</p>
          <h2 class="text-lg font-semibold text-highlighted">Infrastructure &amp; runtime</h2>
          <p class="text-sm text-muted max-w-2xl">
            Live checks for core services used by attendance, queues, geospatial validation, and face verification.
          </p>
        </div>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Failed to load"
          :description="error"
        />

        <div v-if="loading" class="grid gap-4 lg:grid-cols-2">
          <USkeleton class="h-64 w-full rounded-xl" />
          <USkeleton class="h-64 w-full rounded-xl" />
          <USkeleton class="h-48 w-full rounded-xl lg:col-span-2" />
        </div>

        <template v-else-if="health">
          <div class="grid gap-4 lg:grid-cols-2">
            <!-- System status -->
            <UCard class="min-w-0" :ui="{ body: 'p-4 sm:p-5 md:p-6' }">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted uppercase tracking-wide">System status</p>
                <UBadge :color="overallColor" variant="subtle" class="text-xs shrink-0">
                  <template #leading>
                    <span
                      class="h-1.5 w-1.5 rounded-full"
                      :class="{
                        'bg-emerald-500': overallColor === 'success',
                        'bg-amber-500': overallColor === 'warning',
                        'bg-red-500': overallColor === 'error',
                        'bg-zinc-400': overallColor === 'neutral',
                      }"
                    />
                  </template>
                  {{ overallLabel }}
                </UBadge>
              </div>

              <h3 class="mt-2 text-base font-semibold text-highlighted">
                {{ overallTitle }}
              </h3>

              <ul class="mt-4 space-y-2.5">
                <li
                  v-for="svc in health.services"
                  :key="svc.key"
                  class="flex items-start justify-between gap-3 text-sm"
                >
                  <div class="min-w-0">
                    <p class="text-highlighted truncate">{{ svc.label }}</p>
                    <p class="text-xs text-muted truncate">{{ svc.detail }}</p>
                  </div>
                  <span
                    class="flex items-center gap-1.5 text-xs font-semibold shrink-0 pt-0.5"
                    :class="{
                      'text-success': svc.status === 'ok',
                      'text-warning': svc.status === 'warn',
                      'text-error': svc.status === 'fail',
                    }"
                  >
                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(svc.status)" />
                    {{ statusLabel(svc.status) }}
                  </span>
                </li>
              </ul>

              <div class="mt-5 flex items-center justify-between gap-3 border-t border-[var(--ui-border)] pt-4 text-xs">
                <span class="text-muted">Last refreshed</span>
                <strong class="font-semibold text-highlighted tabular-nums">
                  {{ formatRefreshedAt(health.refreshed_at) }}
                </strong>
              </div>
            </UCard>

            <!-- Maintenance -->
            <UCard class="min-w-0" :ui="{ body: 'p-4 sm:p-5 md:p-6' }">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted uppercase tracking-wide">Maintenance mode</p>
                <UBadge
                  :color="spaMaintenanceActive || apiMaintenanceActive ? 'warning' : 'success'"
                  variant="subtle"
                  class="text-xs shrink-0"
                >
                  {{ spaMaintenanceActive || apiMaintenanceActive ? 'Active' : 'Off' }}
                </UBadge>
              </div>

              <h3 class="mt-2 text-base font-semibold text-highlighted">
                {{ spaMaintenanceActive || apiMaintenanceActive ? 'Clients may be blocked' : 'Public access is open' }}
              </h3>

              <ul class="mt-4 space-y-3 text-sm">
                <li class="flex items-center justify-between gap-3">
                  <span class="text-muted">Laravel API</span>
                  <UBadge
                    :color="apiMaintenanceActive ? 'warning' : 'success'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ apiMaintenanceActive ? 'Down' : 'Up' }}
                  </UBadge>
                </li>
                <li class="flex items-center justify-between gap-3">
                  <span class="text-muted">Vue SPA flag</span>
                  <UBadge
                    :color="spaMaintenanceActive ? 'warning' : 'success'"
                    variant="subtle"
                    size="sm"
                  >
                    {{ spaMaintenanceActive ? 'Enabled' : 'Disabled' }}
                  </UBadge>
                </li>
              </ul>

              <p v-if="spaMaintenance?.message" class="mt-4 text-xs text-muted">
                {{ spaMaintenance.message }}
              </p>

              <p class="mt-5 text-xs text-muted border-t border-[var(--ui-border)] pt-4">
                Toggle with
                <code class="font-mono text-highlighted">php artisan mito:maintenance down|up</code>
              </p>
            </UCard>
          </div>

          <!-- Runtime -->
          <UCard class="min-w-0" :ui="{ body: 'p-4 sm:p-5 md:p-6' }">
            <p class="text-xs text-muted uppercase tracking-wide">Runtime</p>
            <h3 class="mt-2 text-base font-semibold text-highlighted">Application environment</h3>

            <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">Environment</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted">{{ health.runtime.environment }}</dd>
              </div>
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">Debug</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted">
                  {{ health.runtime.debug ? 'Enabled' : 'Disabled' }}
                </dd>
              </div>
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">Timezone</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted">{{ health.runtime.timezone }}</dd>
              </div>
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">Laravel</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted tabular-nums">{{ health.runtime.laravel_version }}</dd>
              </div>
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">PHP</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted tabular-nums">{{ health.runtime.php_version }}</dd>
              </div>
              <div class="rounded-lg border border-[var(--ui-border)] px-3 py-2.5">
                <dt class="text-xs text-muted">Components checked</dt>
                <dd class="mt-1 text-sm font-semibold text-highlighted tabular-nums">{{ health.services.length }}</dd>
              </div>
            </dl>
          </UCard>

          <!-- Service detail table -->
          <UCard class="min-w-0 overflow-hidden" :ui="{ body: 'p-0!' }">
            <div class="px-4 py-3 sm:px-5 border-b border-[var(--ui-border)]">
              <p class="text-xs text-muted uppercase tracking-wide">Component detail</p>
              <h3 class="mt-1 text-base font-semibold text-highlighted">Probe results</h3>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-muted border-b border-[var(--ui-border)]">
                    <th class="px-4 py-2.5 sm:px-5 font-semibold">Component</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 sm:px-5 font-semibold">Detail</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="svc in health.services"
                    :key="`detail-${svc.key}`"
                    class="border-b border-[var(--ui-border)] last:border-0"
                  >
                    <td class="px-4 py-3 sm:px-5 font-medium text-highlighted whitespace-nowrap">
                      {{ svc.label }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                      <UBadge :color="statusColor(svc.status)" variant="subtle" size="sm">
                        {{ statusLabel(svc.status) }}
                      </UBadge>
                    </td>
                    <td class="px-4 py-3 sm:px-5 text-muted">
                      {{ svc.detail }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UCard>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
