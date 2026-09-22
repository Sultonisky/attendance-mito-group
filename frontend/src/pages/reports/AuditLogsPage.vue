<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import type { ColumnFiltersState, VisibilityState } from '@tanstack/vue-table'
import { useReportPage } from '../../composables/useReportPage'
import { useDataTableSort } from '../../composables/useDataTableSort'
import { useDataTableDisplay } from '../../composables/useDataTableDisplay'
import { fetchAuditLogs } from '../../services/auditLogApi'
import DataTableToolbar from '../../components/DataTableToolbar.vue'
import DataTable from '../../components/DataTable.vue'
import { createSortableHeader } from '../../utils/dataTable'
import type { AuditLogRow } from '../../types/reports'
import { defaultReportDates } from '../../types/reportDates'

const route = useRoute()
const { loading, error, meta, handleApiError, applyMeta, goToPage } = useReportPage()

const data = ref<AuditLogRow[]>([])
const columnFilters = ref<ColumnFiltersState>([])
const columnVisibility = ref<VisibilityState>()
const search = ref('')

const filters = reactive({
  ...defaultReportDates(),
  per_page: 25,
  sort: 'created_at',
  direction: 'desc' as 'asc' | 'desc',
})

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1
  load()
})

const hideableColumns = [
  { id: 'id', label: 'ID' },
  { id: 'action', label: 'Action' },
  { id: 'actor', label: 'Actor' },
  { id: 'auditable_type', label: 'Resource' },
  { id: 'ip_address', label: 'IP Address' },
  { id: 'created_at', label: 'Created At' },
]
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility)

const columns = computed<TableColumn<AuditLogRow>[]>(() => [
  {
    accessorKey: 'id',
    header: ({ column }) => createSortableHeader(column, 'ID'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.id),
  },
  {
    accessorKey: 'action',
    header: ({ column }) => createSortableHeader(column, 'Action'),
    cell: ({ row }) => h('span', { class: 'text-sm font-mono' }, row.original.action),
  },
  {
    accessorKey: 'actor',
    header: ({ column }) => createSortableHeader(column, 'Actor'),
    cell: ({ row }) => {
      const actor = row.original.actor
      if (!actor) {
        return h('span', { class: 'text-xs text-[var(--ui-text-dimmed)]' }, 'System')
      }
      return h('div', { class: 'space-y-0.5' }, [
        h('p', { class: 'text-sm' }, actor.name),
        h('p', { class: 'text-xs text-[var(--ui-text-muted)]' }, actor.email),
      ])
    },
  },
  {
    accessorKey: 'auditable_type',
    header: ({ column }) => createSortableHeader(column, 'Resource'),
    cell: ({ row }) => h('span', { class: 'text-sm' }, row.original.auditable_type ?? '—'),
  },
  {
    accessorKey: 'ip_address',
    header: ({ column }) => createSortableHeader(column, 'IP Address'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.ip_address ?? '—'),
  },
  {
    accessorKey: 'created_at',
    header: ({ column }) => createSortableHeader(column, 'Created At'),
    cell: ({ row }) => h('span', { class: 'text-xs text-[var(--ui-text-muted)]' }, row.original.created_at ? new Date(row.original.created_at + 'Z').toLocaleString() : '—'),
  },
])

watch(search, () => {
  const next: ColumnFiltersState = []
  if (search.value.trim()) next.push({ id: 'action', value: search.value.trim() })
  columnFilters.value = next
})

watch(
  () => [filters.from, filters.to, filters.per_page] as const,
  () => {
    if (!ready.value) return
    meta.current_page = 1
    load()
  },
)

const ready = ref(false)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await fetchAuditLogs({
      search: search.value || null,
      from: filters.from,
      to: filters.to,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
      page: meta.current_page,
    })
    data.value = res.data
    applyMeta(res.meta)
  } catch (err) {
    await handleApiError(err, 'Unable to load audit logs. Please try again.')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (typeof route.query.from === 'string') filters.from = route.query.from
  if (typeof route.query.to === 'string') filters.to = route.query.to
  await load()
  ready.value = true
})
</script>

<template>
  <UDashboardPanel id="audit-logs">
    <template #header>
      <UDashboardNavbar title="Audit Logs">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="loading"
            @click="load"
          >
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
            <UButton color="primary" variant="subtle" size="sm" icon="i-lucide-refresh-cw" @click="load">
              Retry
            </UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar
            :total="meta.total"
            :rows="data"
            filename="audit-logs"
            :loading="loading"
          />

          <DataTableToolbar
            v-model:search="search"
            v-model:from="filters.from"
            v-model:to="filters.to"
            v-model:per-page="filters.per_page"
            search-placeholder="Search action, resource, or actor…"
            :display-items="displayItems"
            show-date-range
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
            empty-icon="i-lucide-scroll-text"
            empty-message="No audit logs found for the selected filters."
            @update:page="goToPage($event, load)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
