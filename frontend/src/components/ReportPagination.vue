<script setup lang="ts">
import type { ReportMeta } from '../types/reports'

defineProps<{
  meta: ReportMeta
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:page': [page: number]
}>()
</script>

<template>
  <nav v-if="meta.last_page > 1" class="report-pagination" aria-label="Report pagination">
    <button
      type="button"
      :disabled="loading || meta.current_page <= 1"
      @click="emit('update:page', meta.current_page - 1)"
    >
      Previous
    </button>

    <span class="page-info">
      Page {{ meta.current_page }} of {{ meta.last_page }}
    </span>

    <button
      type="button"
      :disabled="loading || meta.current_page >= meta.last_page"
      @click="emit('update:page', meta.current_page + 1)"
    >
      Next
    </button>
  </nav>
</template>

<style scoped>
.report-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--border);
  margin-top: 0.5rem;
}

.page-info {
  font-size: 0.875rem;
  color: var(--text);
}

button {
  padding: 0.5rem 1rem;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--bg);
  color: var(--text-h);
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
