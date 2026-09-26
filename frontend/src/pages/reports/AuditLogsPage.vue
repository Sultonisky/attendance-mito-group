<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";
import type { TableColumn } from "@nuxt/ui";
import type { ColumnFiltersState, VisibilityState } from "@tanstack/vue-table";
import { useReportPage } from "../../composables/useReportPage";
import { useDataTableSort } from "../../composables/useDataTableSort";
import { useDataTableDisplay } from "../../composables/useDataTableDisplay";
import { fetchAuditLogs } from "../../services/auditLogApi";
import DataTableToolbar from "../../components/DataTableToolbar.vue";
import DataTable from "../../components/DataTable.vue";
import ReportDataToolbar from "../../components/ReportDataToolbar.vue";
import type { ReportCsvColumn } from "../../components/ReportDataToolbar.vue";
import { createSortableHeader, UButton } from "../../utils/dataTable";
import { parseUserAgent } from "../../utils/parseUserAgent";
import type { AuditLogRow } from "../../types/reports";
import { defaultReportDates } from "../../types/reportDates";

const route = useRoute();
const { loading, error, meta, handleApiError, applyMeta, goToPage } =
  useReportPage();

const data = ref<AuditLogRow[]>([]);
const columnFilters = ref<ColumnFiltersState>([]);
const columnVisibility = ref<VisibilityState>();
const search = ref("");

const showDetailModal = ref(false);
const selectedLog = ref<AuditLogRow | null>(null);

const filters = reactive({
  ...defaultReportDates(),
  per_page: 25,
  sort: "created_at",
  direction: "desc" as "asc" | "desc",
});

const { sorting } = useDataTableSort(filters, () => {
  meta.current_page = 1;
  load();
});

const hideableColumns = [
  { id: "id", label: "ID" },
  { id: "action", label: "Action" },
  { id: "actor", label: "Actor" },
  { id: "auditable_type", label: "Resource" },
  { id: "ip_address", label: "IP Address" },
  { id: "created_at", label: "Created At" },
];
const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility);

function asAuditRow(row: unknown): AuditLogRow {
  return row as AuditLogRow;
}

function csvJson(value: unknown): string {
  if (value === null || value === undefined) return "";
  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

function csvResourceType(type: string | null | undefined): string {
  if (!type) return "";
  const parts = type.split("\\");
  return parts[parts.length - 1] || type;
}

function csvActorKind(actor: AuditLogRow["actor"]): string {
  if (!actor) return "system";
  return actor.kind === "outsource" ? "outsource" : "user";
}

/** Flat Excel-friendly columns (nested actor/JSON expanded). */
const csvColumns: ReportCsvColumn[] = [
  { header: "ID", value: (row) => asAuditRow(row).id },
  {
    header: "Created At",
    value: (row) => {
      const value = asAuditRow(row).created_at;
      if (!value) return "";
      const date = new Date(value);
      return isNaN(date.getTime()) ? value : date.toLocaleString();
    },
  },
  { header: "Action", value: (row) => asAuditRow(row).action },
  {
    header: "Actor Kind",
    value: (row) => csvActorKind(asAuditRow(row).actor),
  },
  {
    header: "Actor Name",
    value: (row) => asAuditRow(row).actor?.name ?? "System",
  },
  {
    header: "Actor Email/Code",
    value: (row) => asAuditRow(row).actor?.email ?? "",
  },
  {
    header: "Resource",
    value: (row) => csvResourceType(asAuditRow(row).auditable_type),
  },
  {
    header: "Resource ID",
    value: (row) => asAuditRow(row).auditable_id ?? "",
  },
  {
    header: "IP Address",
    value: (row) => asAuditRow(row).ip_address ?? "",
  },
  {
    header: "Client",
    value: (row) => parseUserAgent(asAuditRow(row).user_agent).label,
  },
  {
    header: "User Agent",
    value: (row) => asAuditRow(row).user_agent ?? "",
  },
  {
    header: "Old Values",
    value: (row) => csvJson(asAuditRow(row).old_values),
  },
  {
    header: "New Values",
    value: (row) => csvJson(asAuditRow(row).new_values),
  },
  {
    header: "Metadata",
    value: (row) => csvJson(asAuditRow(row).metadata),
  },
];

const csvFilename = computed(
  () => `audit-logs_${filters.from}_${filters.to}`,
);

async function fetchAllRowsForExport(): Promise<AuditLogRow[]> {
  if (data.value.length > 0 && data.value.length >= meta.total) {
    return [...data.value];
  }

  const all: AuditLogRow[] = [];
  let page = 1;
  let lastPage = 1;
  const maxPages = 200;

  do {
    const res = await fetchAuditLogs({
      search: search.value || null,
      from: filters.from,
      to: filters.to,
      per_page: 100,
      sort: filters.sort,
      direction: filters.direction,
      page,
    });

    const rows = Array.isArray(res.data) ? res.data : [];
    if (!rows.length) break;

    all.push(...rows);

    const parsedLast = Number(res.meta?.last_page);
    lastPage =
      Number.isFinite(parsedLast) && parsedLast > 0 ? parsedLast : page;

    if (Number(res.meta?.current_page) === 1 && page > 1) break;

    page += 1;
  } while (page <= lastPage && page <= maxPages);

  return all;
}

const columns = computed<TableColumn<AuditLogRow>[]>(() => [
  {
    accessorKey: "id",
    header: ({ column }) => createSortableHeader(column, "ID"),
    cell: ({ row }) =>
      h(
        "span",
        { class: "text-xs text-[var(--ui-text-muted)]" },
        row.original.id,
      ),
  },
  {
    accessorKey: "action",
    header: ({ column }) => createSortableHeader(column, "Action"),
    cell: ({ row }) =>
      h("span", { class: "text-sm font-mono" }, row.original.action),
  },
  {
    accessorKey: "actor",
    id: "actor_id",
    header: ({ column }) => createSortableHeader(column, "Actor"),
    cell: ({ row }) => {
      const actor = row.original.actor;
      if (!actor) {
        return h(
          "span",
          { class: "text-xs text-[var(--ui-text-dimmed)]" },
          "System",
        );
      }
      const secondary =
        actor.kind === "outsource"
          ? actor.email
            ? `OS · ${actor.email}`
            : "Outsource"
          : actor.email;
      return h("div", { class: "space-y-0.5" }, [
        h("p", { class: "text-sm" }, actor.name),
        secondary
          ? h("p", { class: "text-xs text-[var(--ui-text-muted)]" }, secondary)
          : null,
      ]);
    },
  },
  {
    accessorKey: "auditable_type",
    header: ({ column }) => createSortableHeader(column, "Resource"),
    cell: ({ row }) =>
      h("span", { class: "text-sm" }, row.original.auditable_type ?? "—"),
  },
  {
    accessorKey: "ip_address",
    header: ({ column }) => createSortableHeader(column, "IP Address"),
    cell: ({ row }) =>
      h(
        "span",
        { class: "text-xs text-[var(--ui-text-muted)]" },
        row.original.ip_address ?? "—",
      ),
  },
  {
    accessorKey: "created_at",
    header: ({ column }) => createSortableHeader(column, "Created At"),
    cell: ({ row }) => {
      const value = row.original.created_at;
      if (!value) return "—";
      const date = new Date(value);
      return isNaN(date.getTime()) ? "—" : date.toLocaleString();
    },
  },
  {
    id: "actions",
    header: "",
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) =>
      h("div", { class: "flex justify-end" }, [
        h(UButton, {
          size: "xs",
          color: "primary",
          variant: "ghost",
          icon: "i-lucide-eye",
          "aria-label": "Show audit detail",
          onClick: () => openShow(row.original),
        }),
      ]),
  },
]);

watch(search, () => {
  const next: ColumnFiltersState = [];
  if (search.value.trim())
    next.push({ id: "action", value: search.value.trim() });
  columnFilters.value = next;
});

watch(
  () => [filters.from, filters.to, filters.per_page] as const,
  () => {
    if (!ready.value) return;
    meta.current_page = 1;
    load();
  },
);

const ready = ref(false);

function formatJson(value: unknown): string {
  if (value === null || value === undefined) return "—";
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

function formatActor(actor: AuditLogRow["actor"]): string {
  if (!actor) return "System";
  if (actor.kind === "outsource") {
    return actor.email
      ? `${actor.name} (OS · ${actor.email})`
      : `${actor.name} (Outsource)`;
  }
  return actor.email ? `${actor.name} <${actor.email}>` : actor.name;
}

function formatCreatedAt(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  return isNaN(date.getTime()) ? value : date.toLocaleString();
}

const selectedUserAgent = computed(() =>
  parseUserAgent(selectedLog.value?.user_agent),
);

function openShow(row: AuditLogRow): void {
  selectedLog.value = row;
  showDetailModal.value = true;
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const res = await fetchAuditLogs({
      search: search.value || null,
      from: filters.from,
      to: filters.to,
      per_page: filters.per_page,
      sort: filters.sort,
      direction: filters.direction,
      page: meta.current_page,
    });
    data.value = res.data;
    applyMeta(res.meta);
  } catch (err) {
    await handleApiError(err, "Unable to load audit logs. Please try again.");
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  if (typeof route.query.from === "string") filters.from = route.query.from;
  if (typeof route.query.to === "string") filters.to = route.query.to;
  await load();
  ready.value = true;
});
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
            <UButton
              color="primary"
              variant="subtle"
              size="sm"
              icon="i-lucide-refresh-cw"
              @click="load"
            >
              Retry
            </UButton>
          </template>
        </UAlert>

        <template v-else>
          <ReportDataToolbar
            :total="meta.total"
            :rows="data"
            :columns="csvColumns"
            :filename="csvFilename"
            :fetch-rows="fetchAllRowsForExport"
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

  <UModal
    v-model:open="showDetailModal"
    :title="selectedLog ? `Audit #${selectedLog.id}` : 'Audit detail'"
    description="Full audit log payload for this event."
  >
    <template #body>
      <div v-if="selectedLog" class="space-y-4 text-sm">
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-[var(--ui-text-muted)]">Action</dt>
            <dd class="mt-0.5 font-mono break-all">{{ selectedLog.action }}</dd>
          </div>
          <div>
            <dt class="text-xs text-[var(--ui-text-muted)]">Created At</dt>
            <dd class="mt-0.5">
              {{ formatCreatedAt(selectedLog.created_at) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-[var(--ui-text-muted)]">Actor</dt>
            <dd class="mt-0.5 break-words">
              {{ formatActor(selectedLog.actor) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-[var(--ui-text-muted)]">IP Address</dt>
            <dd class="mt-0.5 font-mono">
              {{ selectedLog.ip_address ?? "—" }}
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-[var(--ui-text-muted)]">Resource</dt>
            <dd class="mt-0.5 break-all">
              <span v-if="selectedLog.auditable_type">
                {{ selectedLog.auditable_type }}
                <span
                  v-if="selectedLog.auditable_id != null"
                  class="text-[var(--ui-text-muted)]"
                >
                  #{{ selectedLog.auditable_id }}
                </span>
              </span>
              <span v-else>—</span>
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-[var(--ui-text-muted)]">User Agent</dt>
            <dd class="mt-0.5">
              <p class="text-sm">{{ selectedUserAgent.label }}</p>
              <p
                v-if="selectedLog.user_agent"
                class="mt-1 break-all text-xs text-[var(--ui-text-muted)]"
                :title="selectedLog.user_agent"
              >
                {{ selectedLog.user_agent }}
              </p>
            </dd>
          </div>
        </dl>

        <div>
          <p class="mb-1 text-xs font-medium text-[var(--ui-text-muted)]">
            Old values
          </p>
          <pre
            class="max-h-48 overflow-auto rounded-md bg-[var(--ui-bg-elevated)] p-3 text-xs leading-relaxed"
            >{{ formatJson(selectedLog.old_values) }}</pre
          >
        </div>
        <div>
          <p class="mb-1 text-xs font-medium text-[var(--ui-text-muted)]">
            New values
          </p>
          <pre
            class="max-h-48 overflow-auto rounded-md bg-[var(--ui-bg-elevated)] p-3 text-xs leading-relaxed"
            >{{ formatJson(selectedLog.new_values) }}</pre
          >
        </div>
        <div>
          <p class="mb-1 text-xs font-medium text-[var(--ui-text-muted)]">
            Metadata
          </p>
          <pre
            class="max-h-48 overflow-auto rounded-md bg-[var(--ui-bg-elevated)] p-3 text-xs leading-relaxed"
            >{{ formatJson(selectedLog.metadata) }}</pre
          >
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end">
        <UButton
          color="neutral"
          variant="outline"
          @click="showDetailModal = false"
        >
          Close
        </UButton>
      </div>
    </template>
  </UModal>
</template>
