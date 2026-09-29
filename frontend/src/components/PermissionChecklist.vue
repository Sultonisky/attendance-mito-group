<script setup lang="ts">
import { computed, ref } from 'vue'

export interface PermissionCatalogItem {
  name: string
  description: string | null
}

const props = defineProps<{
  catalog: PermissionCatalogItem[]
  disabled?: boolean
}>()

const selected = defineModel<string[]>({ required: true })

const search = ref('')

/** Catalog filtered by search and grouped by module prefix (`module.action`). */
const groups = computed(() => {
  const term = search.value.trim().toLowerCase()
  const byModule = new Map<string, PermissionCatalogItem[]>()
  for (const p of props.catalog) {
    if (term && !p.name.toLowerCase().includes(term) && !(p.description ?? '').toLowerCase().includes(term)) continue
    const module = p.name.split('.')[0] ?? p.name
    byModule.set(module, [...(byModule.get(module) ?? []), p])
  }
  return [...byModule.entries()].map(([module, items]) => ({ module, items }))
})

function toggle(name: string, checked: boolean | 'indeterminate'): void {
  selected.value = checked === true
    ? [...new Set([...selected.value, name])]
    : selected.value.filter((p) => p !== name)
}

function setVisible(checked: boolean): void {
  const visible = new Set(groups.value.flatMap((g) => g.items.map((i) => i.name)))
  selected.value = checked
    ? [...new Set([...selected.value, ...visible])]
    : selected.value.filter((p) => !visible.has(p))
}
</script>

<template>
  <div class="space-y-2">
    <div class="flex flex-wrap items-center gap-2">
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Filter permissions…"
        size="sm"
        class="min-w-48 flex-1"
      />
      <template v-if="!disabled">
        <UButton size="sm" color="neutral" variant="ghost" @click="setVisible(true)">Select all</UButton>
        <UButton size="sm" color="neutral" variant="ghost" @click="setVisible(false)">Clear</UButton>
      </template>
    </div>

    <div class="max-h-96 space-y-3 overflow-y-auto rounded-lg border border-[var(--ui-border)] p-3">
      <div v-for="group in groups" :key="group.module" class="space-y-1">
        <p class="px-2 text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-dimmed)]">
          {{ group.module.replace(/_/g, ' ') }}
        </p>
        <label
          v-for="item in group.items"
          :key="item.name"
          class="flex items-start gap-3 rounded-md px-2 py-1.5 hover:bg-[var(--ui-bg-elevated)]"
        >
          <UCheckbox
            :model-value="selected.includes(item.name)"
            :disabled="disabled"
            class="mt-0.5"
            @update:model-value="(checked: boolean | 'indeterminate') => toggle(item.name, checked)"
          />
          <span class="flex flex-col gap-0.5">
            <span class="text-sm font-mono">{{ item.name }}</span>
            <span v-if="item.description" class="text-xs text-[var(--ui-text-muted)]">{{ item.description }}</span>
          </span>
        </label>
      </div>

      <p v-if="!groups.length" class="py-4 text-center text-sm text-muted">
        No permissions match the filter.
      </p>
    </div>

    <p class="text-xs text-[var(--ui-text-muted)]">
      {{ selected.length }} of {{ catalog.length }} selected
    </p>
  </div>
</template>
