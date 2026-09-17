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
  const variantClass = `app-button--${props.variant}`
  const sizeClass = `app-button--${props.size}`
  const widthClass = props.fullWidth ? 'app-button--full' : ''
  return ['app-button', variantClass, sizeClass, widthClass].filter(Boolean).join(' ')
})
</script>

<template>
  <button :class="classes" :disabled="disabled" v-bind="$attrs">
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
  </button>
</template>

<style scoped>
.app-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 9px;
  border: 1px solid transparent;
  font-weight: 700;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}

.app-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.app-button:hover:not(:disabled) {
  transform: translateY(-1px);
}

.app-button:focus-visible {
  outline: 3px solid rgba(235, 28, 36, 0.18);
  outline-offset: 2px;
}

.app-button--primary {
  background: linear-gradient(180deg, #eb1c24 0%, #c5151d 100%);
  color: #fff;
  box-shadow: 0 6px 18px rgba(235, 28, 36, 0.24);
}

.app-button--secondary {
  background: #fff;
  border-color: var(--border);
  color: var(--text-h);
}

.app-button--ghost {
  background: transparent;
  color: var(--accent);
}

.app-button--md {
  min-height: 2.8rem;
  padding: 0.75rem 1.1rem;
  font-size: 0.92rem;
}

.app-button--sm {
  min-height: 2.3rem;
  padding: 0.55rem 0.8rem;
  font-size: 0.8rem;
}

.app-button--full {
  width: 100%;
}

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
