<script setup lang="ts">
defineProps<{
  collapsed?: boolean
}>()
</script>

<template>
  <!-- Brand identity block shown in sidebar header -->
  <div
    class="flex items-center gap-3 px-1 py-1"
    :class="collapsed ? 'justify-center' : ''"
  >
    <!-- Logo mark: red gradient square -->
    <div
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl shadow-[0_4px_12px_rgba(235,28,36,0.30)]"
      style="background: linear-gradient(135deg, #eb1c24 0%, #ae0e15 100%);"
    >
      <img
        src="/images/mito.png"
        alt="MITO"
        class="h-6 w-6 rounded-lg object-cover"
        @error="($event.target as HTMLImageElement).style.display='none'"
      />
    </div>

    <!-- Word-mark — hidden when collapsed -->
    <div v-if="!collapsed" class="min-w-0">
      <div class="truncate text-[15px] font-black tracking-tight text-[var(--ui-text-highlighted)]">
        MITO
      </div>
      <div class="truncate text-[10px] font-semibold uppercase tracking-[0.2em] text-[var(--ui-text-dimmed)]">
        People Operations
      </div>
    </div>
  </div>
</template>
