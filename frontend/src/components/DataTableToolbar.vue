<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { DataTableFilterOption } from '../utils/dataTable'

withDefaults(defineProps<{
  searchPlaceholder?: string
  searchMaxlength?: number
  statusOptions?: DataTableFilterOption[]
  displayItems?: DropdownMenuItem[]
  selectedCount?: number
  showDelete?: boolean
  showDateRange?: boolean
  showEmployeeId?: boolean
  showPerPage?: boolean
}>(), {
  searchPlaceholder: 'Filter...',
  searchMaxlength: undefined,
  statusOptions: () => [],
  displayItems: () => [],
  selectedCount: 0,
  showDelete: false,
  showDateRange: false,
  showEmployeeId: false,
  showPerPage: false,
})

const search = defineModel<string>('search', { default: '' })
const status = defineModel<string>('status', { default: 'all' })
const from = defineModel<string>('from', { default: '' })
const to = defineModel<string>('to', { default: '' })
const employeeId = defineModel<string>('employeeId', { default: '' })
const perPage = defineModel<number>('perPage', { default: 25 })

const emit = defineEmits<{
  delete: []
}>()
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-1.5">
      <div class="flex w-full flex-col gap-1.5 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
        <UInput
          v-model="search"
          class="w-full min-w-0 sm:max-w-sm sm:min-w-[12ch]"
          icon="i-lucide-search"
          :placeholder="searchPlaceholder"
          :maxlength="searchMaxlength"
        />

        <div
          v-if="showDateRange || showEmployeeId || showPerPage"
          class="flex w-full flex-wrap items-center gap-1.5 sm:w-auto"
        >
          <UInput
            v-if="showDateRange"
            v-model="from"
            type="date"
            class="min-w-0 flex-1 basis-[9rem] sm:w-36 sm:flex-none"
            :ui="{ base: 'ps-2.5' }"
          />
          <UInput
            v-if="showDateRange"
            v-model="to"
            type="date"
            class="min-w-0 flex-1 basis-[9rem] sm:w-36 sm:flex-none"
            :ui="{ base: 'ps-2.5' }"
          />
          <UInput
            v-if="showEmployeeId"
            v-model="employeeId"
            type="number"
            min="1"
            placeholder="Employee ID"
            class="min-w-0 flex-1 basis-[8rem] sm:w-32 sm:flex-none"
          />
          <USelect
            v-if="showPerPage"
            v-model="perPage"
            :items="[10, 25, 50, 100]"
            class="w-20 shrink-0"
          />
        </div>

        <slot name="filters" />
      </div>

      <div class="flex flex-wrap items-center gap-1.5 sm:justify-end">
        <slot name="actions" />

        <UButton
          v-if="showDelete && selectedCount > 0"
          color="error"
          variant="subtle"
          icon="i-lucide-trash"
          @click="emit('delete')"
        >
          <span class="hidden sm:inline">Delete</span>
          <template #trailing>
            <UBadge color="error" variant="solid" size="xs" class="min-w-5 justify-center rounded-full">
              {{ selectedCount }}
            </UBadge>
          </template>
        </UButton>

        <UDropdownMenu
          v-if="displayItems.length"
          :items="displayItems"
          :content="{ align: 'end' }"
        >
          <UButton
            color="neutral"
            variant="outline"
            trailing-icon="i-lucide-settings-2"
            aria-label="Display columns"
          >
            <span class="hidden sm:inline">Display</span>
          </UButton>
        </UDropdownMenu>
      </div>
    </div>

    <div v-if="statusOptions.length" class="overflow-x-auto">
      <UTabs
        v-model="status"
        :items="statusOptions"
        variant="pill"
        size="sm"
        :content="false"
        class="w-max min-w-full"
      />
    </div>
  </div>
</template>
