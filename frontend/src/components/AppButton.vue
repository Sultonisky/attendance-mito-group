<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import type { AppIconName } from './iconNames'

type Variant = 'primary' | 'secondary' | 'ghost'
type Size = 'sm' | 'md'

type Props = {
  variant?: Variant
  size?: Size
  fullWidth?: boolean
  icon?: AppIconName
  iconPosition?: 'left' | 'right'
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'md',
  fullWidth: false,
  icon: undefined,
  iconPosition: 'right',
  disabled: false,
})

const classes = computed(() => {
  return props.variant === 'primary'
    ? { color: 'primary' as const, variant: 'solid' as const }
    : props.variant === 'secondary'
      ? { color: 'neutral' as const, variant: 'outline' as const }
      : { color: 'primary' as const, variant: 'ghost' as const }
})
</script>

<template>
  <UButton
    v-bind="$attrs"
    class="app-button"
    :color="classes.color"
    :variant="classes.variant"
    :size="size"
    :block="fullWidth"
    :disabled="disabled"
  >
    <AppIcon
      v-if="icon && iconPosition === 'left'"
      :name="icon"
      :size="props.size === 'sm' ? 14 : 16"
      :stroke-width="2.2"
      class="app-button__icon app-button__icon--left"
    />

    <span class="app-button__label">
      <slot />
    </span>

    <AppIcon
      v-if="icon && iconPosition === 'right'"
      :name="icon"
      :size="props.size === 'sm' ? 14 : 16"
      :stroke-width="2.2"
      class="app-button__icon app-button__icon--right"
    />
  </UButton>
</template>

<style scoped>
.app-button__label {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.app-button__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
</style>
