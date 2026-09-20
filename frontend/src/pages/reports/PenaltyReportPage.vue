<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { fetchPenaltyReport } from '../../services/reports/penaltyReportApi'
import { adjustPenalty, voidPenalty } from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge } from '../../utils/dataTable'
import type { PenaltyReportRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data          = ref<PenaltyReportRow[]>([])
const actionBusyId  = ref<number | null>(null)
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter  = ref('all')
const search        = ref('')

// ── Adjust modal ──────────────────────────────────────────────────────────────
const showAdjustModal  = ref(false)
const adjustTargetId   = ref<number | null>(null)
const adjustTargetRow  = ref<PenaltyReportRow | null>(null)
const adjustPoints     = ref<number | null>(null)
const adjustReason     = ref('')
const adjustBusy       = ref(false)
const adjustError      = ref('')

// ── Void modal ────────────────────────────────────────────────────────────────
const showVoidModal  = ref(false)
const voidTargetId   = ref<number | null>(null)
const voidTargetRow  = ref<PenaltyReportRow | null>(null)
const voidReason     = ref('')
const voidBusy       = ref(false)
const voidError      = ref('')

const filters = reactive({
  ...defaultReportDates(),
  employee_id: '',
  per_page: 25,
  sort: 'occurred_at',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const statusOptions = [
  { label: 'All',      value: 'all'      },
  { label: 'Active',   value: 'active'   },
  { label: 'Adjusted', value: 'adjusted' },
  { label: 'Voided',   value: 'voided'   },
]

const hideableColumns = [
  { id: 'employee_name',    label: 'Employee'    },
  { id: 'penalty_rule_name',label: 'Rule'        },
  { id: 'source',           label: 'Source'      },
  { id: 'violation_type',   label: 'Violation'   },
  { id: 'original_points',  label: 'Original'    },
  { id: 'adjusted_points',  label: 'Adjusted'    },
  { id: 'final_points',     label: 'Final'       },
  { id: 'status',           label: 'Status'      },
  { id: 'occurred_at',      label: 'Occurred At' },
]

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const allPenaltyActions: AdminRowAction[] = [
  { key: 'adjust', label: 'Adjust', permission: 'penalty.adjust', icon: 'ArrowRight', variant: 'secondary' },
  { key: 'void',   label: 'Void',   permission: 'penalty.void',   icon: 'X', variant: 'ghost', destructive: true },
]

/** Voided is a terminal state — no further actions allowed. */
function penaltyActionsFor(status: string): AdminRowAction[] {
  if (status === 'voided') return []
  return allPenaltyActions
}

const statusColor: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  active:   'error',
  adjusted: 'warning',
  voided:   'neutral',
}

const columns = computed<TableColumn<PenaltyReportRow>[]>(() => [
  {
    accessorKey: 'employee_name',
    header: ({ column }) => createSortableHeader(column, 'Employee'),
  },
  {
    accessorKey: 'penalty_rule_name',
    header: ({ column }) => createSortableHeader(column, 'Rule'),
  },
  {
    accessorKey: 'source',
    header: ({ column }) => createSortableHeader(column, 'Source'),
  },
  {
    accessorKey: 'violation_type',
    header: ({ column }) => createSortableHeader(column, 'Violation'),
  },
  {
    accessorKey: 'original_points',
    header: ({ column }) => createSortableHeader(column, 'Original'),
  },
  {
    accessorKey: 'adjusted_points',
    header: ({ column }) => createSortableHeader(column, 'Adjusted'),
  },
  {
    accessorKey: 'final_points',
    header: ({ column }) => createSortableHeader(column, 'Final'),
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
    accessorKey: 'occurred_at',
    header: ({ column }) => createSortableHeader(column, 'Occurred At'),
    cell: ({ row }) => new Date(row.getValue<string>('occurred_at') + 'Z').toLocaleString(),
  },
  {
    id: 'actions',
    header: 'Actions',
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) => h(AdminRowActions, {
      actions: penaltyActionsFor(row.original.status),
      busy: actionBusyId.value === row.original.id,
      onAction: (key: string) => handleRowAction(key, row.original),
    }),
  },
])

watch([search, statusFilter], () => {
  const next: ColumnFiltersState = []
  if (search.value.trim()) next.push({ id: 'employee_name', value: search.value.trim() })
  if (statusFilter.value !== 'all') next.push({ id: 'status', value: statusFilter.value })
  columnFilters.value = next
})

watch(
  () => [filters.from, filters.to, filters.employee_id, filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    load()
  },
)

const ready = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value   = ''
  try {
    const res = await fetchPenaltyReport({
      from:        filters.from,
      to:          filters.to,
      employee_id: filters.employee_id || null,
      per_page:    filters.per_page,
      sort:        filters.sort,
      direction:   filters.direction,
      page:        meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load penalty report. Please try again.')
  } finally {
    loading.value = false
  }
}

function handleRowAction(action: string, row: PenaltyReportRow): void {
  if (action === 'adjust') {
    adjustTargetId.value  = row.id
    adjustTargetRow.value = row
    adjustPoints.value    = row.final_points ?? row.original_points ?? 0
    adjustReason.value    = ''
    adjustError.value     = ''
    showAdjustModal.value = true
    return
  }

  if (action === 'void') {
    voidTargetId.value  = row.id
    voidTargetRow.value = row
    voidReason.value    = ''
    voidError.value     = ''
    showVoidModal.value = true
  }
}

async function submitAdjust(): Promise<void> {
  if (!adjustReason.value.trim()) {
    adjustError.value = 'A reason is required.'
    return
  }
  const points = adjustPoints.value
  if (points === null || !Number.isFinite(points)) {
    adjustError.value = 'Final points must be a valid number.'
    return
  }
  if (adjustTargetId.value === null) return

  adjustBusy.value  = true
  adjustError.value = ''
  try {
    await adjustPenalty(adjustTargetId.value, points, adjustReason.value.trim())
    showAdjustModal.value = false
    adjustTargetId.value  = null
    adjustTargetRow.value = null
    await load()
  } catch (e: unknown) {
    adjustError.value = e instanceof Error ? e.message : 'Unable to adjust this penalty. Please try again.'
  } finally {
    adjustBusy.value = false
  }
}

async function submitVoid(): Promise<void> {
  if (!voidReason.value.trim()) {
    voidError.value = 'A reason is required to void this penalty.'
    return
  }
  if (voidTargetId.value === null) return

  voidBusy.value  = true
  voidError.value = ''
  try {
    await voidPenalty(voidTargetId.value, voidReason.value.trim())
    showVoidModal.value = false
    voidTargetId.value  = null
    voidTargetRow.value = null
    await load()
  } catch (e: unknown) {
    voidError.value = e instanceof Error ? e.message : 'Unable to void this penalty. Please try again.'
  } finally {
    voidBusy.value = false
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string')   filters.to   = route.query.to
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="penalty-report">
    <template #header>
      <UDashboardNavbar title="Penalties">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-refresh-cw" :loading="loading" @click="load">
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-4 sm:p-6 space-y-4">
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Failed to load"
          :description="error"
        >
          <template #actions>
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">Retry</UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="penalty-report" :loading="loading" />
          <DataTableToolbar
            v-model:search="search"
            v-model:status="statusFilter"
            v-model:from="filters.from"
            v-model:to="filters.to"
            v-model:employee-id="filters.employee_id"
            v-model:per-page="filters.per_page"
            search-placeholder="Filter employees..."
            :status-options="statusOptions"
            :display-items="displayItems"
            show-date-range
            show-employee-id
            show-per-page
          />
          <DataTable
            v-model:sorting="sorting"
            v-model:column-filters="columnFilters"
            v-model:column-visibility="columnVisibility"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            manual-sorting
            empty-icon="i-lucide-triangle-alert"
            empty-message="No penalty records found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <!-- ── Adjust modal ───────────────────────────────────────────────────────── -->
  <UModal v-model:open="showAdjustModal" title="Adjust penalty">
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Adjusting penalty for
          <strong class="text-highlighted">{{ adjustTargetRow?.employee_name }}</strong>.
          Original points: <strong class="text-highlighted">{{ adjustTargetRow?.original_points }}</strong>.
        </p>

        <UFormField label="Final points" required>
          <UInput
            v-model.number="adjustPoints"
            type="number"
            step="0.1"
            min="0"
            placeholder="0"
            class="w-full"
          />
        </UFormField>

        <UFormField label="Reason" required>
          <UTextarea
            v-model="adjustReason"
            placeholder="Enter adjustment reason…"
            :rows="3"
            class="w-full"
          />
        </UFormField>

        <UAlert v-if="adjustError" color="error" variant="subtle" :description="adjustError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="adjustBusy" @click="showAdjustModal = false">
          Cancel
        </UButton>
        <UButton color="primary" :loading="adjustBusy" @click="submitAdjust">
          Save adjustment
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- ── Void modal ─────────────────────────────────────────────────────────── -->
  <UModal v-model:open="showVoidModal" title="Void penalty">
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          You are about to void the penalty for
          <strong class="text-highlighted">{{ voidTargetRow?.employee_name }}</strong>
          ({{ voidTargetRow?.final_points }} points). This cannot be undone.
        </p>

        <UFormField label="Reason" required>
          <UTextarea
            v-model="voidReason"
            placeholder="Enter void reason…"
            :rows="3"
            class="w-full"
            autofocus
          />
        </UFormField>

        <UAlert v-if="voidError" color="error" variant="subtle" :description="voidError" />
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="voidBusy" @click="showVoidModal = false">
          Cancel
        </UButton>
        <UButton color="error" :loading="voidBusy" @click="submitVoid">
          Void penalty
        </UButton>
      </div>
    </template>
  </UModal>
</template>
