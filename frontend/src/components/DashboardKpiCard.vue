<script setup lang="ts">
defineProps<{
  label: string
  value: number
  loading?: boolean
}>()
</script>

<template>
  <div class="kpi-card">
    <p class="kpi-label">{{ label }}</p>
    <p class="kpi-value">
      <span v-if="loading" class="kpi-skeleton" aria-hidden="true" />
      <span v-else>{{ value }}</span>
    </p>
  </div>
</template>

<style scoped>
.kpi-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--surface);
  text-align: left;
  box-shadow: var(--shadow);
  border-top: 3px solid var(--accent);
}

.kpi-label {
  margin: 0 0 0.5rem;
  font-size: 0.875rem;
  color: var(--text);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.kpi-value {
  margin: 0;
  font-size: 2rem;
  font-weight: 600;
  color: var(--accent);
  line-height: 1.2;
}

.kpi-skeleton {
  display: inline-block;
  width: 3rem;
  height: 2rem;
  border-radius: 4px;
  background: var(--code-bg);
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.4;
  }
}
</style>
