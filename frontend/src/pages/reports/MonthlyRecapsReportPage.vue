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
import { firstValidationMessage } from '../../services/apiClient'
import { fetchMonthlyRecaps } from '../../services/reports/monthlyRecapApi'
import {
  exportMonthlyRecap,
  finalizeMonthlyRecap,
  generateMonthlyRecapBulk,
  reopenMonthlyRecap,
  reviewMonthlyRecap,
} from '../../services/adminCrudApi'
import ReportDataToolbar from '../../components/ReportDataToolbar.vue'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import AdminRowActions, { type AdminRowAction } from '../../components/AdminRowActions.vue'
import { createSortableHeader, createStatusBadge, createTruncatedText } from '../../utils/dataTable'
import type { MonthlyRecapRow } from '../../types/reports'

type RecapSource = 'employee' | 'outsource'

const route = useRoute()
const { can } = usePermission()
const toast = useAppToast()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<MonthlyRecapRow[]>([])
const actionBusyId = ref<number | null>(null)
/** Soft action/generate message — keeps the table visible (unlike hard `error`). */
const actionError = ref('')
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const statusFilter = ref('all')
const search = ref('')

// ── List filters only (not generate inputs) ───────────────────────────────────
const filterSource = ref<RecapSource>('employee')
const filterSubjectId = ref('')
const filterPeriod = ref('') // YYYY-MM

const sourceOptions = [
  { label: 'Employee', value: 'employee' as RecapSource },
  { label: 'Outsource', value: 'outsource' as RecapSource },
]

const subjectPlaceholder = computed(() =>
  filterSource.value === 'outsource' ? 'Outsource ID' : 'Employee ID',
)

// ── Generate modal ────────────────────────────────────────────────────────────
const showGenerateModal = ref(false)
const generating = ref(false)
const generateError = ref('')
const genForm = reactive({
  source: 'employee' as RecapSource,
  period: currentPeriodValue(),
})

function currentPeriodValue(): string {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  return `${y}-${m}`
}

function openGenerateModal(): void {
  genForm.source = filterSource.value
  genForm.period = filterPeriod.value || currentPeriodValue()
  generateError.value = ''
  showGenerateModal.value = true
}

function parsePeriod(period: string): { year: number; month: number } | null {
  const match = /^(\d{4})-(\d{2})$/.exec(period.trim())
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  if (month < 1 || month > 12) return null
  return { year, month }
}

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
  { label: 'Review', value: 'review' },
  { label: 'Finalized', value: 'finalized' },
  { label: 'Exported', value: 'exported' },
]

const isOutsourceFilter = computed(() => filterSource.value === 'outsource')

const hideableColumns = computed(() => {
  const cols = [
    { id: 'source', label: 'Source' },
    { id: 'subject', label: 'Subject' },
    { id: 'period', label: 'Period' },
    { id: 'status', label: 'Status' },
    { id: 'present_days', label: 'Present' },
    ...(!isOutsourceFilter.value ? [{ id: 'late_days', label: 'Late' }] : []),
    { id: 'incomplete_days', label: 'Incomplete' },
    { id: 'absent_days', label: 'Absent' },
    { id: 'total_present', label: 'Total' },
    { id: 'finalized_at', label: 'Finalized At' },
  ]
  return cols
})

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const allRecapActions: AdminRowAction[] = [
  { key: 'review',   label: 'Review',   permission: 'monthly_recap.review',   icon: 'Eye',       variant: 'secondary' },
  { key: 'finalize', label: 'Finalize', permission: 'monthly_recap.finalize', icon: 'Check',     variant: 'primary'   },
  { key: 'export',   label: 'Export',   permission: 'monthly_recap.export',   icon: 'Download',  variant: 'secondary' },
  { key: 'reopen',   label: 'Reopen',   permission: 'monthly_recap.finalize', icon: 'ArrowLeft', variant: 'ghost'     },
]

function recapActionsFor(status: string): AdminRowAction[] {
  const keys: Record<string, string[]> = {
    draft:     ['review'],
    review:    ['finalize'],
    finalized: ['export', 'reopen'],
    exported:  [],
  }
  const allowed = keys[status] ?? []
  return allRecapActions.filter(a => allowed.includes(a.key))
}

const statusColor: Record<string, 'success' | 'warning' | 'info' | 'neutral' | 'error'> = {
  finalized: 'success',
  review: 'info',
  draft: 'neutral',
  exported: 'success',
}

function summaryNum(row: MonthlyRecapRow, key: keyof NonNullable<MonthlyRecapRow['summary']>): number {
  return Number(row.summary?.[key] ?? 0)
}

function subjectLabel(row: MonthlyRecapRow): string {
  if (row.source === 'outsource') {
    return row.outsource_code || (row.outsource_id != null ? `#${row.outsource_id}` : '—')
  }
  return row.employee_code || (row.employee_id != null ? `#${row.employee_id}` : '—')
}

const columns = computed<TableColumn<MonthlyRecapRow>[]>(() => [
  {
    accessorKey: 'source',
    header: ({ column }) => createSortableHeader(column, 'Source'),
    cell: ({ row }) => createStatusBadge(
      row.original.source === 'outsource' ? 'outsource' : 'employee',
      row.original.source === 'outsource' ? 'warning' : 'info',
    ),
  },
  {
    id: 'subject',
    header: 'Subject',
    enableSorting: false,
    cell: ({ row }) => {
      const code = subjectLabel(row.original)
      const name = row.original.subject_name
      return h('div', { class: 'min-w-0' }, [
        createTruncatedText(code, 'font-mono text-xs'),
        name
          ? h('div', { class: 'truncate text-xs text-[var(--ui-text-muted)]' }, name)
          : null,
      ])
    },
  },
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
    id: 'present_days',
    header: 'Present',
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, 'present_days')),
  },
  ...(!isOutsourceFilter.value
    ? [{
        id: 'late_days',
        header: 'Late',
        enableSorting: false,
        cell: ({ row }: { row: { original: MonthlyRecapRow } }) =>
          String(summaryNum(row.original, 'late_days')),
      } satisfies TableColumn<MonthlyRecapRow>]
    : []),
  {
    id: 'incomplete_days',
    header: 'Incomplete',
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, 'incomplete_days')),
  },
  {
    id: 'absent_days',
    header: 'Absent',
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, 'absent_days')),
  },
  {
    id: 'total_present',
    header: 'Total',
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, 'present_days')),
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

watch([filterSource, filterSubjectId, filterPeriod], () => {
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
      source: filterSource.value,
    }
    if (filterSubjectId.value) {
      if (filterSource.value === 'outsource') params.outsource_id = filterSubjectId.value
      else params.employee_id = filterSubjectId.value
    }
    if (filterPeriod.value) params.period = filterPeriod.value

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

async function submitGenerate(): Promise<void> {
  const parsed = parsePeriod(genForm.period)
  if (!parsed) {
    generateError.value = 'Pilih periode yang valid (YYYY-MM).'
    return
  }

  generating.value = true
  generateError.value = ''
  actionError.value = ''
  try {
    const res = await generateMonthlyRecapBulk({
      source: genForm.source,
      year: parsed.year,
      month: parsed.month,
    })
    const r = res.data
    toast.success(
      'Generate selesai',
      `All ${r.source} · ${r.period}: ${r.generated} generated, ${r.skipped} skipped, ${r.failed} failed.`,
    )
    if (r.failed > 0 && r.failures[0]) {
      actionError.value = `Some rows failed (e.g. #${r.failures[0].id}: ${r.failures[0].message})`
    }
    // Sync list filters to what was just generated.
    filterSource.value = genForm.source
    filterPeriod.value = r.period
    filterSubjectId.value = ''
    showGenerateModal.value = false
    meta.current_page = 1
    await load()
  }
  catch (e: unknown) {
    const msg = firstValidationMessage(e)
      ?? (e instanceof Error && e.message ? e.message : 'Unable to generate monthly recaps. Please try again.')
    generateError.value = msg
    toast.fromError(e, msg)
  }
  finally {
    generating.value = false
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id
  actionError.value = ''
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
    const msg = firstValidationMessage(e)
      ?? (e instanceof Error && e.message ? e.message : 'Unable to update this monthly recap. Please try again.')
    actionError.value = msg
    toast.fromError(e, msg)
  } finally {
    actionBusyId.value = null
  }
}

onMounted(async () => {
  if (typeof route.query.source === 'string' && (route.query.source === 'employee' || route.query.source === 'outsource')) {
    filterSource.value = route.query.source
  }
  if (typeof route.query.period === 'string') filterPeriod.value = route.query.period
  if (typeof route.query.employee_id === 'string') {
    filterSource.value = 'employee'
    filterSubjectId.value = route.query.employee_id
  }
  if (typeof route.query.outsource_id === 'string') {
    filterSource.value = 'outsource'
    filterSubjectId.value = route.query.outsource_id
  }
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
            @click="openGenerateModal"
          >
            Generate all
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
          <UAlert
            v-if="actionError"
            color="warning"
            variant="subtle"
            icon="i-lucide-circle-alert"
            title="Action tidak valid"
            :description="actionError"
            class="mb-0"
          >
            <template #actions>
              <UButton color="neutral" variant="ghost" size="xs" @click="actionError = ''">Dismiss</UButton>
            </template>
          </UAlert>
          <ReportDataToolbar :total="meta.total" :rows="data" filename="monthly-recaps" :loading="loading" />
          <DataTableToolbar
            v-model:search="search"
            v-model:status="statusFilter"
            search-placeholder="Filter period text..."
            :status-options="statusOptions"
            :display-items="displayItems"
          >
            <template #filters>
              <USelect
                v-model="filterSource"
                :items="sourceOptions"
                class="w-36"
              />
              <UInput
                v-model="filterPeriod"
                type="month"
                class="w-40"
                :ui="{ base: 'ps-2.5' }"
              />
              <UInput
                v-model="filterSubjectId"
                type="number"
                min="1"
                :placeholder="subjectPlaceholder"
                class="w-36"
              />
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
                Klik Generate all, pilih periode + type, lalu generate semua absensi bulan itu.
              </p>
            </template>
          </DataTable>
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="showGenerateModal"
    title="Generate monthly recap"
    description="Generate attendance-only recap untuk semua employee/outsource di periode yang dipilih."
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="generateError"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="generateError"
        />
        <UFormField label="Type" required>
          <USelect
            v-model="genForm.source"
            :items="sourceOptions"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Period" required hint="Bulan yang akan digenerate">
          <UInput
            v-model="genForm.period"
            type="month"
            class="w-full"
            :ui="{ base: 'ps-2.5' }"
          />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton color="neutral" variant="outline" :disabled="generating" @click="showGenerateModal = false">
          Cancel
        </UButton>
        <UButton color="primary" icon="i-lucide-zap" :loading="generating" @click="submitGenerate">
          Generate all
        </UButton>
      </div>
    </template>
  </UModal>
</template>
