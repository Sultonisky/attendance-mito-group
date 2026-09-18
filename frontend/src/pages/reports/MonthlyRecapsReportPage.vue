<script setup lang="ts">
import { computed, h, onMounted, ref, resolveComponent } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import { useReportPage } from '../../composables/useReportPage'
import { usePermission } from '../../features/auth/composables/usePermission'
import { fetchMonthlyRecaps } from '../../services/reports/monthlyRecapApi'
import {
  exportMonthlyRecap,
  finalizeMonthlyRecap,
  generateMonthlyRecap,
  reopenMonthlyRecap,
  reviewMonthlyRecap,
} from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import type { MonthlyRecapRow } from '../../types/reports'

const route  = useRoute()
const { can } = usePermission()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data         = ref<MonthlyRecapRow[]>([])
const actionBusyId = ref<number | null>(null)
const generating   = ref(false)

// Filters
const employeeId = ref('')
const year  = ref(new Date().getFullYear())
const month = ref(new Date().getMonth() + 1)
const sortField     = ref('period')
const sortDirection = ref<'asc' | 'desc'>('desc')

const rowActions: AdminRowAction[] = [
  { key: 'review',   label: 'Review',   permission: 'monthly_recap.review',   icon: 'Eye',       variant: 'secondary' },
  { key: 'finalize', label: 'Finalize', permission: 'monthly_recap.finalize', icon: 'Check',     variant: 'primary'   },
  { key: 'export',   label: 'Export',   permission: 'monthly_recap.export',   icon: 'Download',  variant: 'secondary' },
  { key: 'reopen',   label: 'Reopen',   permission: 'monthly_recap.finalize', icon: 'ArrowLeft', variant: 'ghost'     },
]

const statusColor: Record<string, 'success' | 'warning' | 'info' | 'neutral' | 'error'> = {
  finalized: 'success',
  reviewed:  'info',
  generated: 'warning',
  draft:     'neutral',
  exported:  'success',
}

const UBadge = resolveComponent('UBadge')

const columns = computed<TableColumn<MonthlyRecapRow>[]>(() => [
  { accessorKey: 'period', header: 'Period' },
  {
    accessorKey: 'status', header: 'Status',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return h(UBadge, { color: statusColor[s] ?? 'neutral', variant: 'subtle', class: 'capitalize' }, () => s)
    },
  },
  {
    accessorKey: 'finalized_at', header: 'Finalized At',
    cell: ({ row }) => {
      const v = row.getValue<string | null>('finalized_at')
      return v ? new Date(v + 'Z').toLocaleString() : '—'
    },
  },
  {
    accessorKey: 'exported_at', header: 'Exported At',
    cell: ({ row }) => {
      const v = row.getValue<string | null>('exported_at')
      return v ? new Date(v + 'Z').toLocaleString() : '—'
    },
  },
  {
    id: 'actions', header: 'Actions',
    cell: ({ row }) => h(AdminRowActions, {
      actions: rowActions,
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original.id),
    }),
  },
])

async function load(): Promise<void> {
  loading.value = true; error.value = ''
  try {
    const params: Record<string, string | number | null | undefined> = {
      page:      meta.current_page,
      sort:      sortField.value,
      direction: sortDirection.value,
    }
    if (employeeId.value) params.employee_id = employeeId.value

    const res = await fetchMonthlyRecaps(params)
    data.value = res.data

    if (res.meta) {
      applyMeta({
        current_page: Number(res.meta.current_page ?? 1),
        per_page:     Number(res.meta.per_page     ?? 30),
        total:        Number(res.meta.total        ?? res.data.length),
        last_page:    Number(res.meta.last_page    ?? 1),
      })
    } else {
      applyMeta({ current_page: 1, per_page: 30, total: res.data.length, last_page: 1 })
    }
  } catch (err) {
    await handleApiError(err, 'Unable to load monthly recaps. Please try again.')
  } finally { loading.value = false }
}

async function generate(): Promise<void> {
  if (!employeeId.value) { error.value = 'Employee ID is required to generate a recap.'; return }
  generating.value = true; error.value = ''
  try {
    await generateMonthlyRecap({
      employee_id: Number(employeeId.value),
      year:  year.value,
      month: month.value,
    })
    await load()
  } catch { error.value = 'Unable to generate the monthly recap. Please try again.'
  } finally { generating.value = false }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id; error.value = ''
  try {
    if (action === 'review')   await reviewMonthlyRecap(id)
    if (action === 'finalize') await finalizeMonthlyRecap(id)
    if (action === 'export')   await exportMonthlyRecap(id)
    if (action === 'reopen')   await reopenMonthlyRecap(id)
    await load()
  } catch { error.value = 'Unable to update this monthly recap. Please try again.'
  } finally { actionBusyId.value = null }
}

function applySort(field: string, direction: 'asc' | 'desc') {
  sortField.value = field
  sortDirection.value = direction
  meta.current_page = 1
  load()
}

onMounted(() => {
  if (typeof route.query.employee_id === 'string') employeeId.value = route.query.employee_id
  load()
})
</script>

<template>
  <UDashboardPanel id="monthly-recaps-report">
    <template #header>
      <UDashboardNavbar title="Monthly recap">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton
            v-if="can('monthly_recap.generate')"
            color="primary" size="sm" icon="i-lucide-zap"
            :loading="generating" :disabled="!employeeId"
            @click="generate"
          >
            Generate recap
          </UButton>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>

      <!-- Filter toolbar -->
      <UDashboardToolbar>
        <template #left>
          <form class="flex flex-wrap items-end gap-3" @submit.prevent="() => { meta.current_page = 1; load() }">
            <UFormField label="Employee ID">
              <UInput
                v-model="employeeId"
                type="number" min="1" placeholder="All employees"
                class="w-36"
              />
            </UFormField>
            <UFormField label="Year">
              <UInput v-model.number="year" type="number" min="2000" max="2200" class="w-24" />
            </UFormField>
            <UFormField label="Month">
              <UInput v-model.number="month" type="number" min="1" max="12" class="w-20" />
            </UFormField>
            <UFormField label="Sort">
              <USelect
                :model-value="sortField"
                :items="[
                  { label: 'Period',       value: 'period'       },
                  { label: 'Status',       value: 'status'       },
                  { label: 'Finalized At', value: 'finalized_at' },
                  { label: 'Exported At',  value: 'exported_at'  },
                ]"
                value-key="value"
                class="w-36"
                @update:model-value="applySort(String($event), sortDirection)"
              />
            </UFormField>
            <UFormField label="Direction">
              <USelect
                :model-value="sortDirection"
                :items="[{ label: 'Descending', value: 'desc' }, { label: 'Ascending', value: 'asc' }]"
                value-key="value"
                class="w-32"
                @update:model-value="applySort(sortField, ($event as 'asc' | 'desc'))"
              />
            </UFormField>
            <UButton type="submit" color="primary" icon="i-lucide-search" :loading="loading">
              Apply
            </UButton>
          </form>
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="Failed to load" :description="error">
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="monthly-recaps" :loading="loading" />

          <UTable
            :data="data" :columns="columns" :loading="loading"
            :ui="{
              base: 'table-fixed border-separate border-spacing-0 w-full text-sm',
              thead: '[&>tr]:bg-[var(--ui-bg-elevated)]/60 [&>tr]:after:content-none',
              tbody: '[&>tr]:last:[&>td]:border-b-0',
              th: 'first:rounded-l-lg last:rounded-r-lg border-y border-[var(--ui-border)] first:border-l last:border-r px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-muted)]',
              td: 'border-b border-[var(--ui-border)] px-3 py-2.5',
            }"
          />

          <div v-if="!loading && !data.length" class="py-16 text-center">
            <UIcon name="i-lucide-file-text" class="mx-auto mb-3 size-10 text-[var(--ui-text-dimmed)]" />
            <p class="text-sm text-[var(--ui-text-muted)]">No monthly recap records found.</p>
            <p v-if="can('monthly_recap.generate')" class="mt-1 text-xs text-[var(--ui-text-dimmed)]">
              Enter an Employee ID and click Generate recap to create one.
            </p>
          </div>

          <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-[var(--ui-border)] pt-4">
            <p class="text-xs text-[var(--ui-text-muted)]">Page {{ meta.current_page }} of {{ meta.last_page }}</p>
            <UPagination
              :page="meta.current_page" :total="meta.last_page" :items-per-page="1"
              show-edges :disabled="loading"
              @update:page="goToPage($event, load)"
            />
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
