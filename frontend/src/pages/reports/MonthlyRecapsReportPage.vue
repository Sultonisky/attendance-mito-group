<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { usePermission } from '../../features/auth/composables/usePermission'
import { useAppToast } from '../../composables/useAppToast'
import { fetchMonthlyRecaps } from '../../services/reports/monthlyRecapApi'
import {
  exportMonthlyRecap,
  finalizeMonthlyRecap,
  generateMonthlyRecap,
  reopenMonthlyRecap,
  reviewMonthlyRecap,
} from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { MonthlyRecapRow } from '../../types/reports'

const route = useRoute()
const { can } = usePermission()
const toast = useAppToast()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<MonthlyRecapRow[]>([])
const actionBusyId = ref<number | null>(null)
const generating = ref(false)
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('all')
const search = ref('')

const employeeId = ref('')
const year = ref(new Date().getFullYear())
const month = ref(new Date().getMonth() + 1)

const sortState = reactive({
  sort: 'period',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(sortState, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Generated', value: 'generated' },
  { label: 'Reviewed', value: 'reviewed' },
  { label: 'Finalized', value: 'finalized' },
  { label: 'Exported', value: 'exported' },
]

const hideableColumns = [
  { id: 'period', label: 'Period' },
  { id: 'status', label: 'Status' },
  { id: 'finalized_at', label: 'Finalized At' },
  { id: 'exported_at', label: 'Exported At' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const allRecapActions: AdminRowAction[] = [
  { key: 'review',   label: 'Review',   permission: 'monthly_recap.review',   icon: 'Eye',       variant: 'secondary' },
  { key: 'finalize', label: 'Finalize', permission: 'monthly_recap.finalize', icon: 'Check',     variant: 'primary'   },
  { key: 'export',   label: 'Export',   permission: 'monthly_recap.export',   icon: 'Download',  variant: 'secondary' },
  { key: 'reopen',   label: 'Reopen',   permission: 'monthly_recap.finalize', icon: 'ArrowLeft', variant: 'ghost'     },
]

/**
 * State machine for monthly recap actions.
 *
 * draft      → review
 * generated  → review, finalize
 * reviewed   → finalize, reopen
 * finalized  → export, reopen
 * exported   → export (re-export), reopen
 */
function recapActionsFor(status: string): AdminRowAction[] {
  const keys: Record<string, string[]> = {
    draft:     ['review'],
    generated: ['review', 'finalize'],
    reviewed:  ['finalize', 'reopen'],
    finalized: ['export', 'reopen'],
    exported:  ['export', 'reopen'],
  }
  const allowed = keys[status] ?? []
  return allRecapActions.filter(a => allowed.includes(a.key))
}

const statusColor: Record<string, 'success' | 'warning' | 'info' | 'neutral' | 'error'> = {
  finalized: 'success',
  reviewed: 'info',
  generated: 'warning',
  draft: 'neutral',
  exported: 'success',
}

const columns = computed<TableColumn<MonthlyRecapRow>[]>(() => [
  {
    accessorKey: 'period',
    header: ({ column }) => createSortableHeader(column, 'Period'),
  },
  {
    accessorKey: 'status',
    header: ({ column }) => createSortableHeader(column, 'Status'),
    filterFn: 'equals',
    cell: ({ row }) => {
      const s = row.getValue<string>('status')
      return createStatusBadge(s, statusColor[s] ?? 'neutral')
    },
  },
  {
    accessorKey: 'finalized_at',
    header: ({ column }) => createSortableHeader(column, 'Finalized At'),
    cell: ({ row }) => {
      const v = row.getValue<string | null>('finalized_at')
      return v ? (() => { const d = new Date(v); return isNaN(d.getTime()) ? '—' : d.toLocaleString() })() : '—'
    },
  },
  {
    accessorKey: 'exported_at',
    header: ({ column }) => createSortableHeader(column, 'Exported At'),
    cell: ({ row }) => {
      const v = row.getValue<string | null>('exported_at')
      return v ? (() => { const d = new Date(v); return isNaN(d.getTime()) ? '—' : d.toLocaleString() })() : '—'
    },
  },
  {
    id: 'actions',
    header: 'Actions',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => h(AdminRowActions, {
      actions: recapActionsFor(row.original.status),
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original.id),
    }),
  },
])

watch([search, statusFilter], () => {
  const next: ColumnFiltersState = []
  if (search.value.trim()) next.push({ id: 'period', value: search.value.trim() })
  if (statusFilter.value !== 'all') next.push({ id: 'status', value: statusFilter.value })
  columnFilters.value = next
})

watch(employeeId, () => {
  if (!ready.value) return
  meta.current_page = 1
  load()
})

const ready = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const params: Record<string, string | number | null | undefined> = {
      page: meta.current_page,
      sort: sortState.sort,
      direction: sortState.direction,
    }
    if (employeeId.value) params.employee_id = employeeId.value

    const res = await fetchMonthlyRecaps(params)
    data.value = res.data

    if (res.meta) {
      applyMeta({
        current_page: Number(res.meta.current_page ?? 1),
        per_page: Number(res.meta.per_page ?? 30),
        total: Number(res.meta.total ?? res.data.length),
        last_page: Number(res.meta.last_page ?? 1),
      })
    }
    else {
      applyMeta({ current_page: 1, per_page: 30, total: res.data.length, last_page: 1 })
    }
  }
  catch (err) {
    await handleApiError(err, 'Unable to load monthly recaps. Please try again.')
  }
  finally {
    loading.value = false
  }
}

async function generate(): Promise<void> {
  if (!employeeId.value) {
    error.value = 'Employee ID is required to generate a recap.'
    return
  }
  generating.value = true
  error.value = ''
  try {
    await generateMonthlyRecap({
      employee_id: Number(employeeId.value),
      year: year.value,
      month: month.value,
    })
    toast.success('Monthly recap generated')
    await load()
  }
  catch {
    error.value = 'Unable to generate the monthly recap. Please try again.'
    toast.error('Generate failed', 'Unable to generate the monthly recap. Please try again.')
  }
  finally {
    generating.value = false
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id
  error.value = ''
  try {
    if (action === 'review') {
      await reviewMonthlyRecap(id)
      toast.success('Recap marked for review')
    }
    if (action === 'finalize') {
      await finalizeMonthlyRecap(id)
      toast.success('Recap finalized')
    }
    if (action === 'export') {
      await exportMonthlyRecap(id)
      toast.success('Recap exported')
    }
    if (action === 'reopen') {
      await reopenMonthlyRecap(id)
      toast.success('Recap reopened')
    }
    await load()
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Unable to update this monthly recap. Please try again.'
    toast.fromError(e, 'Unable to update this monthly recap.')
  } finally {
    actionBusyId.value = null
  }
}

onMounted(async () => {
  if (typeof route.query.employee_id === 'string') employeeId.value = route.query.employee_id
  await load()
  ready.value = true
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
            color="primary"
            size="sm"
            icon="i-lucide-zap"
            :loading="generating"
            :disabled="!employeeId"
            @click="generate"
          >
            Generate recap
          </UButton>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
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
          <DataTableToolbar
            v-model:search="search"
            v-model:status="statusFilter"
            v-model:employee-id="employeeId"
            search-placeholder="Filter period..."
            :status-options="statusOptions"
            :display-items="displayItems"
            show-employee-id
          >
            <template #filters>
              <UInput v-model.number="year" type="number" min="2000" max="2200" placeholder="Year" class="w-24" />
              <UInput v-model.number="month" type="number" min="1" max="12" placeholder="Month" class="w-20" />
            </template>
          </DataTableToolbar>
          <DataTable
            v-model:sorting="sorting"
            v-model:column-filters="columnFilters"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-file-text"
            empty-message="No monthly recap records found."
            @update:page="goToPage($event, load)"
          >
            <template #empty-extra>
              <p v-if="can('monthly_recap.generate')" class="mt-1 text-xs text-[var(--ui-text-dimmed)]">
                Enter an Employee ID and click Generate recap to create one.
              </p>
            </template>
          </DataTable>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
