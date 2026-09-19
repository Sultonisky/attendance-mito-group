<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  label: string
  value: number
  loading?: boolean
  icon?: string          // i-lucide-* string (Nuxt UI icon format)
  tone?: 'positive' | 'warning' | 'neutral' | 'accent'
  caption?: string
  variation?: number     // % change vs previous period, optional
}>()

const variationColor = computed(() => {
  if (props.variation === undefined) return ''
  return props.variation > 0
    ? 'success'
    : props.variation < 0
      ? 'error'
      : 'neutral'
})

const variationLabel = computed(() => {
  if (props.variation === undefined) return ''
  const sign = props.variation > 0 ? '+' : ''
  return `${sign}${props.variation}%`
})
</script>

<template>
  <UPageCard
    :icon="icon"
    :title="label"
    variant="subtle"
    :ui="{
      container: 'gap-y-1.5',
      wrapper: 'items-start',
      leading: 'p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25',
      title: 'font-normal text-muted text-xs uppercase tracking-wide',
    }"
    class="hover:z-1"
  >
    <div class="flex items-center gap-2.5">
      <!-- Value -->
      <span v-if="loading" class="kpi-skeleton" aria-hidden="true" />
      <span v-else class="text-2xl font-semibold text-highlighted">
        {{ value }}
      </span>

      <!-- Variation badge -->
      <UBadge
        v-if="variation !== undefined && !loading"
        :color="variationColor"
        variant="subtle"
        class="text-xs tabular-nums"
      >
        {{ variationLabel }}
      </UBadge>
    </div>

    <p v-if="caption" class="text-xs text-muted">{{ caption }}</p>
  </UPageCard>
</template>

<style scoped>
.kpi-skeleton {
  display: inline-block;
  width: 3rem;
  height: 1.75rem;
  border-radius: 6px;
  background: var(--ui-bg-elevated);
  animation: kpi-pulse 1.6s ease-in-out infinite;
}

@keyframes kpi-pulse {
  0%, 100% { opacity: 1;    }
  50%       { opacity: 0.35; }
}
</style>
