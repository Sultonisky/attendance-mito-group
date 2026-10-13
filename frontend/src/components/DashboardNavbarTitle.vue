<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps<{
  title?: string
  section?: string
}>()

const route = useRoute()

const resolvedTitle = computed(() => props.title ?? (typeof route.meta.title === 'string' ? route.meta.title : ''))
const resolvedSection = computed(() => props.section ?? (typeof route.meta.section === 'string' ? route.meta.section : ''))
</script>

<template>
  <span class="flex min-w-0 items-center gap-1.5">
    <template v-if="resolvedSection">
      <span class="truncate text-muted font-medium">{{ resolvedSection }}</span>
      <UIcon name="i-lucide-chevron-right" class="size-4 shrink-0 text-dimmed" />
    </template>
    <span class="truncate">{{ resolvedTitle }}</span>
  </span>
</template>
