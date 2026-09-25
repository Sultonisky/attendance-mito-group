<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";
import type { TableColumn } from "@nuxt/ui";
import type {
  ColumnFiltersState,
  RowSelectionState,
  VisibilityState,
} from "@tanstack/vue-table";
import { useReportPage } from "../../composables/useReportPage";
import { useDataTableSort } from "../../composables/useDataTableSort";
import { useDataTableDisplay } from "../../composables/useDataTableDisplay";
import { usePermission } from "../../features/auth/composables/usePermission";
import { useAppToast } from "../../composables/useAppToast";
import { firstValidationMessage } from "../../services/apiClient";
import { fetchMonthlyRecaps } from "../../services/reports/monthlyRecapApi";
import {
  exportMonthlyRecap,
  exportMonthlyRecapBulk,
  finalizeMonthlyRecap,
  generateMonthlyRecapBulk,
  reopenMonthlyRecap,
  reviewMonthlyRecap,
  transitionMonthlyRecapBulk,
  type MonthlyRecapTransitionAction,
} from "../../services/adminCrudApi";
import ReportDataToolbar from "../../components/ReportDataToolbar.vue";
import type { ReportCsvColumn } from "../../components/ReportDataToolbar.vue";
import DataTableToolbar from "../../components/DataTableToolbar.vue";
import DataTable from "../../components/DataTable.vue";
import AdminRowActions, {
  type AdminRowAction,
} from "../../components/AdminRowActions.vue";
import {
  createSortableHeader,
  createStatusBadge,
  createTruncatedText,
  UCheckbox,
} from "../../utils/dataTable";
import type { MonthlyRecapRow } from "../../types/reports";

type RecapSource = "employee" | "outsource";

/** Mirrors backend TransitionMonthlyRecapsBulk::ALLOWED_FROM */
const BULK_ALLOWED_FROM: Record<MonthlyRecapTransitionAction, string[]> = {
  review: ["draft"],
  finalize: ["review"],
  export: ["finalized"],
  reopen: ["finalized", "exported"],
};

const route = useRoute();
const { can } = usePermission();
const toast = useAppToast();
const { loading, error, meta, handleApiError, applyMeta, goToPage } =
  useReportPage();

const data = ref<MonthlyRecapRow[]>([]);
const actionBusyId = ref<number | null>(null);
const bulkBusy = ref(false);
/** Soft action/generate message — keeps the table visible (unlike hard `error`). */
const actionError = ref("");
const columnFilters = ref<ColumnFiltersState>([]);
const columnVisibility = ref<VisibilityState>();
const rowSelection = ref<RowSelectionState>({});
const statusFilter = ref("all");
const search = ref("");

// ── List filters only (not generate inputs) ───────────────────────────────────
const filterSource = ref<RecapSource>("employee");
const filterSubjectId = ref("");
const filterPeriod = ref(""); // YYYY-MM

const sourceOptions = [
  { label: "Employee", value: "employee" as RecapSource },
  { label: "Outsource", value: "outsource" as RecapSource },
];

const subjectPlaceholder = computed(() =>
  filterSource.value === "outsource" ? "Outsource ID" : "Employee ID",
);

// ── Generate modal ────────────────────────────────────────────────────────────
const showGenerateModal = ref(false);
const generating = ref(false);
const generateError = ref("");
const genForm = reactive({
  source: "employee" as RecapSource,
  period: currentPeriodValue(),
});

function currentPeriodValue(): string {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  return `${y}-${m}`;
}

function openGenerateModal(): void {
  genForm.source = filterSource.value;
  genForm.period = filterPeriod.value || currentPeriodValue();
  generateError.value = "";
  showGenerateModal.value = true;
}

function parsePeriod(period: string): { year: number; month: number } | null {
  const match = /^(\d{4})-(\d{2})$/.exec(period.trim());
  if (!match) return null;
  const year = Number(match[1]);
  const month = Number(match[2]);
  if (month < 1 || month > 12) return null;
  return { year, month };
}

const sortState = reactive({
  sort: "period",
  direction: "desc" as "asc" | "desc",
});

const { sorting } = useDataTableSort(sortState, () => {
  meta.current_page = 1;
  load();
});

const statusOptions = [
  { label: "All", value: "all" },
  { label: "Draft", value: "draft" },
  { label: "Review", value: "review" },
  { label: "Finalized", value: "finalized" },
  { label: "Exported", value: "exported" },
];

const isOutsourceFilter = computed(() => filterSource.value === "outsource");

const hideableColumns = computed(() => {
  const cols = [
    { id: "source", label: "Source" },
    { id: "subject", label: "Subject" },
    { id: "period", label: "Period" },
    { id: "status", label: "Status" },
    { id: "present_days", label: "Present" },
    ...(!isOutsourceFilter.value ? [{ id: "late_days", label: "Late" }] : []),
    { id: "incomplete_days", label: "Incomplete" },
    { id: "absent_days", label: "Absent" },
    { id: "total_present", label: "Total" },
    { id: "finalized_at", label: "Finalized At" },
  ];
  return cols;
});

const { displayItems } = useDataTableDisplay(hideableColumns, columnVisibility);

const allRecapActions: AdminRowAction[] = [
  {
    key: "review",
    label: "Mark for review",
    permission: "monthly_recap.review",
    icon: "Eye",
    variant: "secondary",
  },
  {
    key: "finalize",
    label: "Finalize (lock)",
    permission: "monthly_recap.finalize",
    icon: "Check",
    variant: "primary",
  },
  {
    key: "export",
    label: "Mark as exported",
    permission: "monthly_recap.export",
    icon: "Download",
    variant: "secondary",
  },
  {
    key: "reopen",
    label: "Reopen for correction",
    permission: "monthly_recap.finalize",
    icon: "ArrowLeft",
    variant: "ghost",
  },
];

function normalizeRecapStatus(status: string | null | undefined): string {
  return String(status ?? "")
    .trim()
    .toLowerCase()
    .replace(/^reviewed$/, "review");
}

function recapActionsFor(status: string): AdminRowAction[] {
  const normalized = normalizeRecapStatus(status);
  const keys: Record<string, string[]> = {
    draft: ["review"],
    review: ["finalize"],
    finalized: ["export", "reopen"],
    exported: ["reopen"],
  };
  const allowed = keys[normalized] ?? [];
  return allRecapActions.filter((a) => allowed.includes(a.key));
}

const selectedRows = computed(() => {
  const selected = rowSelection.value;
  return data.value.filter((row) => selected[String(row.id)]);
});

const selectedCount = computed(() => selectedRows.value.length);

const selectedStatus = computed(() => {
  const rows = selectedRows.value;
  if (!rows.length) return null;
  const statuses = [
    ...new Set(rows.map((r) => normalizeRecapStatus(r.status)).filter(Boolean)),
  ];
  return statuses.length === 1 ? statuses[0]! : null;
});

const bulkStatusMixed = computed(
  () => selectedCount.value > 0 && selectedStatus.value === null,
);

/** Same-status actions the current user is allowed to run in bulk. */
const bulkActions = computed(() => {
  const status = selectedStatus.value;
  if (!status) return [];
  return recapActionsFor(status).filter((a) => can(a.permission));
});

const bulkActionsBlockedReason = computed(() => {
  if (selectedCount.value < 1) return "";
  if (bulkStatusMixed.value) {
    return "Bulk status change requires all selected rows to have the same status.";
  }
  if (!selectedStatus.value) return "";
  if (bulkActions.value.length > 0) return "";
  const available = recapActionsFor(selectedStatus.value);
  if (!available.length) {
    return `No bulk transition from status "${selectedStatus.value}".`;
  }
  return "You do not have permission for the next status action on these rows.";
});

function clearRowSelection(): void {
  rowSelection.value = {};
}

function validateBulkAction(
  action: MonthlyRecapTransitionAction,
): string | null {
  if (!selectedCount.value) {
    return "Select at least one monthly recap.";
  }
  if (bulkStatusMixed.value || !selectedStatus.value) {
    return "Bulk status change requires all selected rows to have the same status.";
  }
  const allowedFrom = BULK_ALLOWED_FROM[action] ?? [];
  if (!allowedFrom.includes(selectedStatus.value)) {
    return `Action "${action}" is not allowed for status "${selectedStatus.value}".`;
  }
  const perm = allRecapActions.find((a) => a.key === action)?.permission;
  if (perm && !can(perm)) {
    return "You do not have permission for this bulk action.";
  }
  return null;
}

const statusColor: Record<
  string,
  "success" | "warning" | "info" | "neutral" | "error"
> = {
  finalized: "success",
  review: "info",
  draft: "neutral",
  exported: "success",
};

function summaryNum(
  row: MonthlyRecapRow,
  key: keyof NonNullable<MonthlyRecapRow["summary"]>,
): number {
  return Number(row.summary?.[key] ?? 0);
}

function subjectLabel(row: MonthlyRecapRow): string {
  if (row.source === "outsource") {
    return (
      row.outsource_code ||
      (row.outsource_id != null ? `#${row.outsource_id}` : "—")
    );
  }
  return (
    row.employee_code || (row.employee_id != null ? `#${row.employee_id}` : "—")
  );
}

function asRecapRow(row: unknown): MonthlyRecapRow {
  return row as MonthlyRecapRow;
}

function formatCsvDateTime(value: string | null | undefined): string {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function formatCsvStatus(status: string | null | undefined): string {
  if (!status) return "";
  return status.charAt(0).toUpperCase() + status.slice(1);
}

/** Flat Excel-friendly columns — OS omits Late (same as table). */
const csvColumns = computed<ReportCsvColumn[]>(() => [
  { header: "ID", value: (row) => asRecapRow(row).id },
  { header: "Source", value: (row) => asRecapRow(row).source ?? "" },
  {
    header: isOutsourceFilter.value ? "Outsource Code" : "Employee Code",
    value: (row) => {
      const r = asRecapRow(row);
      return r.source === "outsource"
        ? (r.outsource_code ?? "")
        : (r.employee_code ?? "");
    },
  },
  { header: "Name", value: (row) => asRecapRow(row).subject_name ?? "" },
  { header: "Period", value: (row) => asRecapRow(row).period ?? "" },
  { header: "Status", value: (row) => formatCsvStatus(asRecapRow(row).status) },
  {
    header: "Scheduled Days",
    value: (row) => summaryNum(asRecapRow(row), "scheduled_days"),
  },
  {
    header: "Present Days",
    value: (row) => summaryNum(asRecapRow(row), "present_days"),
  },
  ...(!isOutsourceFilter.value
    ? [
        {
          header: "Late Days",
          value: (row: unknown) => summaryNum(asRecapRow(row), "late_days"),
        } satisfies ReportCsvColumn,
      ]
    : []),
  {
    header: "Incomplete Days",
    value: (row) => summaryNum(asRecapRow(row), "incomplete_days"),
  },
  {
    header: "Absent Days",
    value: (row) => summaryNum(asRecapRow(row), "absent_days"),
  },
  {
    header: "Total Present",
    value: (row) => summaryNum(asRecapRow(row), "present_days"),
  },
  {
    header: "Finalized At",
    value: (row) => formatCsvDateTime(asRecapRow(row).finalized_at),
  },
  {
    header: "Exported At",
    value: (row) => formatCsvDateTime(asRecapRow(row).exported_at),
  },
  {
    header: "Created At",
    value: (row) => formatCsvDateTime(asRecapRow(row).created_at),
  },
]);

function resolveCsvPeriodLabel(rows: unknown[]): string {
  const filter = filterPeriod.value.trim()
  if (filter) return filter

  const periods = [...new Set(
    rows
      .map(r => asRecapRow(r).period)
      .filter((p): p is string => typeof p === 'string' && p.length > 0),
  )].sort()

  if (periods.length === 1) return periods[0]!
  if (periods.length > 1) return `${periods[0]}_to_${periods[periods.length - 1]}`
  return 'all'
}

function csvFilename(rows: unknown[]): string {
  return `monthly-recaps_${filterSource.value}_${resolveCsvPeriodLabel(rows)}`
}

async function afterCsvExport(rows: unknown[]): Promise<void> {
  toast.success(`Exported ${rows.length.toLocaleString()} records.`)

  if (!can('monthly_recap.export')) {
    return
  }

  const finalizedIds = rows
    .map(r => asRecapRow(r))
    .filter(r => r.status === 'finalized' && Number.isFinite(r.id))
    .map(r => r.id)

  if (!finalizedIds.length) {
    return
  }

  try {
    const res = await exportMonthlyRecapBulk(finalizedIds)
    const r = res.data
    if (r.exported > 0) {
      toast.success(
        'Marked as exported',
        `${r.exported} finalized recap(s) auto-marked after CSV download.`,
      )
      await load()
    }
    if (r.failed > 0 && r.failures[0]) {
      actionError.value = `Some recaps failed to mark exported (e.g. #${r.failures[0].id}: ${r.failures[0].message})`
    }
  }
  catch (e: unknown) {
    toast.fromError(
      e,
      'CSV downloaded, but auto mark-as-exported failed. Use ⋯ → Mark as exported per row.',
    )
  }
}

async function fetchAllRowsForExport(): Promise<MonthlyRecapRow[]> {
  if (data.value.length > 0 && data.value.length >= meta.total) {
    return [...data.value].sort((a, b) => a.id - b.id);
  }

  try {
    const all: MonthlyRecapRow[] = [];
    let page = 1;
    let lastPage = 1;
    const maxPages = 200;

    do {
      const params: Record<string, string | number | null | undefined> = {
        page,
        source: filterSource.value,
      };
      if (filterSubjectId.value) {
        if (filterSource.value === "outsource")
          params.outsource_id = filterSubjectId.value;
        else params.employee_id = filterSubjectId.value;
      }
      if (filterPeriod.value) params.period = filterPeriod.value;

      const res = await fetchMonthlyRecaps(params);
      const rows = Array.isArray(res.data) ? res.data : [];
      if (!rows.length) break;

      all.push(...rows);

      const parsedLast = Number(res.meta?.last_page);
      lastPage =
        Number.isFinite(parsedLast) && parsedLast > 0 ? parsedLast : page;

      if (Number(res.meta?.current_page) === 1 && page > 1) break;

      page += 1;
    } while (page <= lastPage && page <= maxPages);

    return all.sort((a, b) => a.id - b.id);
  } catch (err) {
    toast.fromError(err, "Unable to export monthly recaps. Please try again.");
    return [];
  }
}

const columns = computed<TableColumn<MonthlyRecapRow>[]>(() => [
  {
    id: "select",
    header: ({ table: tableApi }) =>
      h(UCheckbox, {
        modelValue: tableApi.getIsSomePageRowsSelected()
          ? "indeterminate"
          : tableApi.getIsAllPageRowsSelected(),
        "onUpdate:modelValue": (value: unknown) =>
          tableApi.toggleAllPageRowsSelected(!!value),
        "aria-label": "Select all",
      }),
    cell: ({ row }) =>
      h(UCheckbox, {
        modelValue: row.getIsSelected(),
        "onUpdate:modelValue": (value: unknown) =>
          row.toggleSelected(!!value),
        "aria-label": "Select row",
      }),
    enableSorting: false,
    enableHiding: false,
  },
  {
    accessorKey: "source",
    header: ({ column }) => createSortableHeader(column, "Source"),
    cell: ({ row }) =>
      createStatusBadge(
        row.original.source === "outsource" ? "outsource" : "employee",
        row.original.source === "outsource" ? "warning" : "info",
      ),
  },
  {
    id: "subject",
    header: "Subject",
    enableSorting: false,
    cell: ({ row }) => {
      const code = subjectLabel(row.original);
      const name = row.original.subject_name;
      return h("div", { class: "min-w-0" }, [
        createTruncatedText(code, "font-mono text-xs"),
        name
          ? h(
              "div",
              { class: "truncate text-xs text-[var(--ui-text-muted)]" },
              name,
            )
          : null,
      ]);
    },
  },
  {
    accessorKey: "period",
    header: ({ column }) => createSortableHeader(column, "Period"),
  },
  {
    accessorKey: "status",
    header: ({ column }) => createSortableHeader(column, "Status"),
    filterFn: "equals",
    cell: ({ row }) => {
      const s = row.getValue<string>("status");
      return createStatusBadge(s, statusColor[s] ?? "neutral");
    },
  },
  {
    id: "present_days",
    header: "Present",
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, "present_days")),
  },
  ...(!isOutsourceFilter.value
    ? [
        {
          id: "late_days",
          header: "Late",
          enableSorting: false,
          cell: ({ row }: { row: { original: MonthlyRecapRow } }) =>
            String(summaryNum(row.original, "late_days")),
        } satisfies TableColumn<MonthlyRecapRow>,
      ]
    : []),
  {
    id: "incomplete_days",
    header: "Incomplete",
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, "incomplete_days")),
  },
  {
    id: "absent_days",
    header: "Absent",
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, "absent_days")),
  },
  {
    id: "total_present",
    header: "Total",
    enableSorting: false,
    cell: ({ row }) => String(summaryNum(row.original, "present_days")),
  },
  {
    accessorKey: "finalized_at",
    header: ({ column }) => createSortableHeader(column, "Finalized At"),
    cell: ({ row }) => {
      const v = row.getValue<string | null>("finalized_at");
      return v
        ? (() => {
            const d = new Date(v);
            return isNaN(d.getTime()) ? "—" : d.toLocaleString();
          })()
        : "—";
    },
  },
  {
    id: "actions",
    header: "Actions",
    enableSorting: false,
    enableHiding: false,
    cell: ({ row }) =>
      h(AdminRowActions, {
        actions: recapActionsFor(row.original.status),
        busy: actionBusyId.value === row.original.id,
        onAction: (key: string) => handleRowAction(key, row.original.id),
      }),
  },
]);

watch([search, statusFilter], () => {
  const next: ColumnFiltersState = [];
  if (search.value.trim())
    next.push({ id: "period", value: search.value.trim() });
  if (statusFilter.value !== "all")
    next.push({ id: "status", value: statusFilter.value });
  columnFilters.value = next;
});

watch([filterSource, filterSubjectId, filterPeriod], () => {
  if (!ready.value) return;
  meta.current_page = 1;
  clearRowSelection();
  load();
});

const ready = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const params: Record<string, string | number | null | undefined> = {
      page: meta.current_page,
      sort: sortState.sort,
      direction: sortState.direction,
      source: filterSource.value,
    };
    if (filterSubjectId.value) {
      if (filterSource.value === "outsource")
        params.outsource_id = filterSubjectId.value;
      else params.employee_id = filterSubjectId.value;
    }
    if (filterPeriod.value) params.period = filterPeriod.value;

    const res = await fetchMonthlyRecaps(params);
    data.value = res.data;
    clearRowSelection();

    if (res.meta) {
      applyMeta({
        current_page: Number(res.meta.current_page ?? 1),
        per_page: Number(res.meta.per_page ?? 30),
        total: Number(res.meta.total ?? res.data.length),
        last_page: Number(res.meta.last_page ?? 1),
      });
    } else {
      applyMeta({
        current_page: 1,
        per_page: 30,
        total: res.data.length,
        last_page: 1,
      });
    }
  } catch (err) {
    await handleApiError(
      err,
      "Unable to load monthly recaps. Please try again.",
    );
  } finally {
    loading.value = false;
  }
}

async function submitGenerate(): Promise<void> {
  const parsed = parsePeriod(genForm.period);
  if (!parsed) {
    generateError.value = "Pilih periode yang valid (YYYY-MM).";
    return;
  }

  generating.value = true;
  generateError.value = "";
  actionError.value = "";
  try {
    const res = await generateMonthlyRecapBulk({
      source: genForm.source,
      year: parsed.year,
      month: parsed.month,
    });
    const r = res.data;
    toast.success(
      "Generate selesai",
      `All ${r.source} · ${r.period}: ${r.generated} generated, ${r.skipped} skipped, ${r.failed} failed.`,
    );
    if (r.failed > 0 && r.failures[0]) {
      actionError.value = `Some rows failed (e.g. #${r.failures[0].id}: ${r.failures[0].message})`;
    }
    // Sync list filters to what was just generated.
    filterSource.value = genForm.source;
    filterPeriod.value = r.period;
    filterSubjectId.value = "";
    showGenerateModal.value = false;
    meta.current_page = 1;
    await load();
  } catch (e: unknown) {
    const msg =
      firstValidationMessage(e) ??
      (e instanceof Error && e.message
        ? e.message
        : "Unable to generate monthly recaps. Please try again.");
    generateError.value = msg;
    toast.fromError(e, msg);
  } finally {
    generating.value = false;
  }
}

async function handleRowAction(action: string, id: number): Promise<void> {
  actionBusyId.value = id;
  actionError.value = "";
  try {
    if (action === "review") {
      await reviewMonthlyRecap(id);
      toast.success("Marked for review", "Next: Finalize when numbers look correct.");
    }
    if (action === "finalize") {
      await finalizeMonthlyRecap(id);
      toast.success("Finalized", "Recap is locked. Mark as exported when sent out, or reopen to correct.");
    }
    if (action === "export") {
      await exportMonthlyRecap(id);
      toast.success("Marked as exported", "Lifecycle complete. Use Export CSV above to download the file.");
    }
    if (action === "reopen") {
      await reopenMonthlyRecap(id);
      toast.success("Reopened", "Back to review. Regenerate if numbers changed, then finalize again.");
    }
    await load();
  } catch (e: unknown) {
    const msg =
      firstValidationMessage(e) ??
      (e instanceof Error && e.message
        ? e.message
        : "Unable to update this monthly recap. Please try again.");
    actionError.value = msg;
    toast.fromError(e, msg);
  } finally {
    actionBusyId.value = null;
  }
}

async function handleBulkAction(action: MonthlyRecapTransitionAction): Promise<void> {
  const validationError = validateBulkAction(action);
  if (validationError) {
    toast.error(validationError);
    return;
  }

  const ids = selectedRows.value.map((r) => r.id);
  const label =
    allRecapActions.find((a) => a.key === action)?.label ?? action;

  bulkBusy.value = true;
  actionError.value = "";
  try {
    const res = await transitionMonthlyRecapBulk(ids, action);
    const r = res.data;
    if (r.updated > 0) {
      toast.success(
        `Bulk: ${label}`,
        `${r.updated} recap(s) updated from ${r.from_status}.`,
      );
    } else if (r.skipped > 0 && r.failed === 0) {
      toast.success(`Bulk: ${label}`, "No status changes needed.");
    }
    if (r.failed > 0 && r.failures[0]) {
      toast.error(
        "Some rows failed",
        `#${r.failures[0].id}: ${r.failures[0].message}`,
      );
    }
    clearRowSelection();
    await load();
  } catch (e: unknown) {
    toast.fromError(
      e,
      "Unable to apply bulk status change. Please try again.",
    );
  } finally {
    bulkBusy.value = false;
  }
}

onMounted(async () => {
  if (
    typeof route.query.source === "string" &&
    (route.query.source === "employee" || route.query.source === "outsource")
  ) {
    filterSource.value = route.query.source;
  }
  if (typeof route.query.period === "string")
    filterPeriod.value = route.query.period;
  if (typeof route.query.employee_id === "string") {
    filterSource.value = "employee";
    filterSubjectId.value = route.query.employee_id;
  }
  if (typeof route.query.outsource_id === "string") {
    filterSource.value = "outsource";
    filterSubjectId.value = route.query.outsource_id;
  }
  await load();
  ready.value = true;
});
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
            Generate Recap
          </UButton>
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
              >Retry</UButton
            >
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
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                @click="actionError = ''"
                >Dismiss</UButton
              >
            </template>
          </UAlert>
          <ReportDataToolbar
            :total="meta.total"
            :rows="data"
            :columns="csvColumns"
            :filename="csvFilename"
            :fetch-rows="fetchAllRowsForExport"
            :after-export="afterCsvExport"
            :loading="loading"
          />
          <DataTableToolbar
            v-model:search="search"
            v-model:status="statusFilter"
            search-placeholder="Filter period text..."
            :status-options="statusOptions"
            :display-items="displayItems"
            :selected-count="selectedCount"
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

          <div
            v-if="selectedCount > 0"
            class="flex flex-col gap-2 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-bg-elevated)] px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <UBadge
                :color="bulkStatusMixed ? 'warning' : 'primary'"
                variant="subtle"
                size="sm"
              >
                {{ selectedCount }} selected
                <template v-if="selectedStatus"> · {{ selectedStatus }}</template>
                <template v-else-if="bulkStatusMixed"> · mixed status</template>
              </UBadge>
              <p
                v-if="bulkActionsBlockedReason"
                class="text-xs text-[var(--ui-text-muted)]"
              >
                {{ bulkActionsBlockedReason }}
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
              <UButton
                v-for="action in bulkActions"
                :key="action.key"
                size="sm"
                :color="action.variant === 'primary' ? 'primary' : 'neutral'"
                :variant="action.variant === 'primary' ? 'solid' : 'outline'"
                :loading="bulkBusy"
                :disabled="bulkBusy || bulkStatusMixed"
                @click="handleBulkAction(action.key as MonthlyRecapTransitionAction)"
              >
                Bulk: {{ action.label }}
              </UButton>
              <UButton
                size="sm"
                color="neutral"
                variant="ghost"
                :disabled="bulkBusy"
                @click="clearRowSelection"
              >
                Clear
              </UButton>
            </div>
          </div>

          <DataTable
            v-model:sorting="sorting"
            v-model:column-filters="columnFilters"
            v-model:column-visibility="columnVisibility"
            v-model:row-selection="rowSelection"
            :data="data"
            :columns="columns"
            :loading="loading"
            :meta="meta"
            :get-row-id="(row) => String(row.id)"
            manual-sorting
            empty-icon="i-lucide-file-text"
            empty-message="No monthly recap records found."
            @update:page="goToPage($event, load)"
          >
            <template #empty-extra>
              <p
                v-if="can('monthly_recap.generate')"
                class="mt-1 text-xs text-[var(--ui-text-dimmed)]"
              >
                Klik Generate Recap, pilih periode + type, lalu generate semua
                absensi bulan itu.
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
        <UButton
          color="neutral"
          variant="outline"
          :disabled="generating"
          @click="showGenerateModal = false"
        >
          Cancel
        </UButton>
        <UButton
          color="primary"
          icon="i-lucide-zap"
          :loading="generating"
          @click="submitGenerate"
        >
          Generate Recap
        </UButton>
      </div>
    </template>
  </UModal>
</template>
