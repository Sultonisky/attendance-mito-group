<script setup lang="ts">
const props = withDefaults(defineProps<{
  label: string
  field?: string
  activeField?: string
  direction?: 'asc' | 'desc'
}>(), {
  field: '',
  activeField: '',
  direction: 'asc',
})

const emit = defineEmits<{
  sort: [field: string, direction: 'asc' | 'desc']
}>()

function sortColumn(): void {
  if (!props.field) return
  const nextDirection = props.activeField === props.field && props.direction === 'asc' ? 'desc' : 'asc'
  emit('sort', props.field, nextDirection)
}
</script>

<template>
  <th
    class="sortable-header"
    :class="{ 'is-active': activeField === field }"
    :aria-sort="activeField === field ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'"
  >
    <UButton
      v-if="field"
      type="button"
      color="neutral"
      variant="ghost"
      size="xs"
      block
      class="table-sort-button"
      :class="{ 'is-active': activeField === field }"
      :trailing-icon="activeField === field
        ? (direction === 'asc' ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down')
        : 'i-lucide-chevrons-up-down'"
      :aria-label="`Sort by ${label}`"
      @click="sortColumn"
    >
      <span>{{ label }}</span>
    </UButton>
    <span v-else>{{ label }}</span>
  </th>
</template>

<style scoped>
.sortable-header {
  min-width: 8.5rem;
  padding: 0.55rem 0.85rem;
  background: var(--code-bg);
  color: var(--text);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-align: left;
  text-transform: uppercase;
  white-space: nowrap;
}

.sortable-header.is-active {
  background: var(--accent-bg);
  color: var(--accent);
}

.table-sort-button {
  width: 100%;
}

@media (max-width: 640px) {
  .sortable-header {
    min-width: 7.5rem;
    padding-inline: 0.65rem;
  }
}
</style>
