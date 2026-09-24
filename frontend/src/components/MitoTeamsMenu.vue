<script setup lang="ts">
defineProps<{
  collapsed?: boolean
}>()
</script>

<template>
  <!-- Brand identity block shown in sidebar header -->
  <div
    class="flex w-full items-center overflow-hidden"
    :class="collapsed ? 'justify-center py-1' : 'justify-start gap-3 px-0.5 py-1'"
  >
    <!-- Logo mark: keep it clean without forcing a square box around the image -->
    <div class="flex h-12 w-12 shrink-0 items-center justify-center">
      <img
        src="/images/mito-pdf.png"
        alt="MITO"
        class="h-12 w-12 object-contain"
        @error="($event.target as HTMLImageElement).style.display='none'"
      />
    </div>

    <!-- Word-mark — hidden when collapsed -->
    <div v-if="!collapsed" class="min-w-0 flex-1 leading-none">
      <div class="truncate text-[15px] font-black tracking-[-0.04em] text-[var(--ui-text-highlighted)]">
        MITO Group
      </div>
      <div class="mt-[3px] truncate text-[8.5px] font-extrabold uppercase tracking-[0.22em] text-[var(--ui-text-dimmed)]">
        Attendances Dashboard
      </div>
    </div>
  </div>
</template>
