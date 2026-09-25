<script setup lang="ts">
import { ref } from 'vue'
import { useAppToast } from '../composables/useAppToast'

export type ReportCsvColumn = {
  header: string
  value: (row: unknown) => string | number | null | undefined
}

const props = defineProps<{
  total: number
  rows: unknown[]
  filename: string
  loading?: boolean
  /** When set, export uses these ordered human headers instead of raw API keys. */
  columns?: ReportCsvColumn[]
  /** When set, export fetches/builds rows on click (e.g. all filtered pages). */
  fetchRows?: () => Promise<unknown[]> | unknown[]
}>()

const toast = useAppToast()
const exporting = ref(false)

function csvValue(value: unknown): string {
  if (value === null || value === undefined) return '""'
  const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
  return `"${text.replaceAll('"', '""')}"`
}

function buildCsv(records: unknown[], columns?: ReportCsvColumn[]): string {
  const rows = records.filter((r): r is Record<string, unknown> =>
    typeof r === 'object' && r !== null,
  )
  if (!rows.length) return ''

  if (columns?.length) {
    return [
      columns.map(c => csvValue(c.header)).join(','),
      ...rows.map(r => columns.map(c => csvValue(c.value(r))).join(',')),
    ].join('\n')
  }

  const keys = [...new Set(rows.flatMap(r => Object.keys(r)))]
  return [
    keys.map(csvValue).join(','),
    ...rows.map(r => keys.map(k => csvValue(r[k])).join(',')),
  ].join('\n')
}

function downloadCsv(csv: string): void {
  const blob = new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${props.filename}.csv`
  a.rel = 'noopener'
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function exportCsv(): Promise<void> {
  if (exporting.value) return
  exporting.value = true
  try {
    const source = props.fetchRows ? await props.fetchRows() : props.rows
    if (!Array.isArray(source) || !source.length) {
      toast.error('No records to export for the current filters.')
      return
    }
    const csv = buildCsv(source, props.columns)
    if (!csv) {
      toast.error('Unable to build CSV from the current data.')
      return
    }
    downloadCsv(csv)
    toast.success(`Exported ${source.length.toLocaleString()} records.`)
  }
  catch (err) {
    toast.fromError(err, 'Unable to export CSV. Please try again.')
  }
  finally {
    exporting.value = false
  }
}
</script>

<template>
  <div class="flex items-center justify-between gap-4">
    <div>
      <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-[var(--ui-text-dimmed)]">
        Results
      </p>
      <p class="mt-0.5 text-sm text-[var(--ui-text-muted)]">
        <span class="text-base font-semibold text-[var(--ui-text-highlighted)]">
          {{ total.toLocaleString() }}
        </span>
        records found
      </p>
    </div>

    <UButton
      color="neutral"
      variant="outline"
      size="sm"
      icon="i-lucide-download"
      :disabled="loading || exporting || (!rows.length && total < 1)"
      :loading="exporting"
      @click="exportCsv"
    >
      Export CSV
    </UButton>
  </div>
</template>
