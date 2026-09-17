<script setup lang="ts">
import { computed } from 'vue'
import type { Component } from 'vue'
import { ArrowRight } from '@lucide/vue'
import { iconMap, type AppIconName } from './iconNames'

const props = withDefaults(
  defineProps<{
    name: AppIconName
    size?: number
    strokeWidth?: number
    color?: string
    className?: string
  }>(),
  {
    size: 18,
    strokeWidth: 2.2,
    color: 'currentColor',
    className: '',
  },
)

const iconComponent = computed<Component>(() => iconMap[props.name] ?? ArrowRight)
</script>

<template>
  <component
    :is="iconComponent"
    :size="size"
    :stroke-width="strokeWidth"
    :color="color"
    :class="className"
    v-bind="$attrs"
  />
</template>
