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
    <UPagination
      :page="meta.current_page"
      :total="meta.last_page"
      :items-per-page="1"
      :disabled="loading"
      show-edges
      @update:page="emit('update:page', $event)"
    />

    <span class="page-info">
      Page {{ meta.current_page }} of {{ meta.last_page }}
    </span>
  </nav>
</template>

<style scoped>
.report-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 0.15rem 0.15rem;
  border-top: 1px solid var(--border);
  margin-top: 0.5rem;
}

.page-info {
  font-size: 0.875rem;
  color: var(--text);
}

nav :deep(ul) {
  flex-wrap: wrap;
}
</style>
