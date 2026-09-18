<script setup lang="ts">
const props = defineProps<{
  total:    number
  rows:     unknown[]
  filename: string
  loading?: boolean
}>()

function csvValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
  return `"${text.replaceAll('"', '""')}"`
}

function exportCsv(): void {
  if (!props.rows.length) return
  const records = props.rows.filter((r): r is Record<string, unknown> =>
    typeof r === 'object' && r !== null,
  )
  if (!records.length) return
  const columns = [...new Set(records.flatMap(r => Object.keys(r)))]
  const csv = [
    columns.map(csvValue).join(','),
    ...records.map(r => columns.map(c => csvValue(r[c])).join(',')),
  ].join('\n')
  const blob = new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8;' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = `${props.filename}.csv`; a.click()
  URL.revokeObjectURL(url)
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
      :disabled="loading || !rows.length"
      @click="exportCsv"
    >
      Export CSV
    </UButton>
  </div>
</template>
