<script setup lang="ts">
import type { ReportSortOption } from '../types/reports'

defineProps<{
  filters: {
    from: string
    to: string
    employee_id: string
    per_page: number
    sort: string
    direction: 'asc' | 'desc'
  }
  loading?: boolean
  sortOptions: ReportSortOption[]
}>()

const emit = defineEmits<{
  'update:from':        [value: string]
  'update:to':          [value: string]
  'update:employee_id': [value: string]
  'update:per_page':    [value: number]
  'update:sort':        [value: string]
  'update:direction':   [value: 'asc' | 'desc']
  search: []
}>()
</script>

<template>
  <form @submit.prevent="emit('search')">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
      <UFormField label="From">
        <UInput
          type="date"
          :model-value="filters.from"
          class="w-full"
          @update:model-value="emit('update:from', String($event ?? ''))"
        />
      </UFormField>

      <UFormField label="To">
        <UInput
          type="date"
          :model-value="filters.to"
          class="w-full"
          @update:model-value="emit('update:to', String($event ?? ''))"
        />
      </UFormField>

      <UFormField label="Employee ID">
        <UInput
          type="number"
          min="1"
          placeholder="All employees"
          :model-value="filters.employee_id"
          class="w-full"
          @update:model-value="emit('update:employee_id', String($event ?? ''))"
        />
      </UFormField>

      <UFormField label="Per page">
        <USelect
          :model-value="filters.per_page"
          :items="[10, 25, 50, 100]"
          class="w-full"
          @update:model-value="emit('update:per_page', Number($event))"
        />
      </UFormField>

      <UFormField label="Sort by">
        <USelect
          :model-value="filters.sort === '' ? '__all__' : filters.sort"
          :items="[{ label: 'Default', value: '__all__' }, ...sortOptions]"
          value-key="value"
          class="w-full"
          @update:model-value="emit('update:sort', $event === '__all__' ? '' : String($event ?? ''))"
        />
      </UFormField>

      <UFormField label="Direction">
        <USelect
          :model-value="filters.direction"
          :items="[
            { label: 'Ascending',  value: 'asc'  },
            { label: 'Descending', value: 'desc' },
          ]"
          value-key="value"
          class="w-full"
          @update:model-value="emit('update:direction', ($event ?? 'asc') as 'asc' | 'desc')"
        />
      </UFormField>
    </div>

    <div class="mt-3 flex justify-end">
      <UButton
        type="submit"
        color="primary"
        icon="i-lucide-search"
        :loading="loading"
      >
        Apply filters
      </UButton>
    </div>
  </form>
</template>
