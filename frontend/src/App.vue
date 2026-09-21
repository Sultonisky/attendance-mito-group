<script setup lang="ts">
import { RouterView } from 'vue-router'
import { useHead } from '@unhead/vue'

// Light-only app: the employee-facing pages (e.g. OutsourcePage) use
// hard-coded light surfaces, so an OS-level dark preference used to flip
// every text token to white/slate while backgrounds stayed light
// (invisible text). Pin the color mode to "light" and never read the OS
// preference. See useTheme.ts for the single shared instance.
const themeColor = '#ffffff'

useHead({
  meta: [{ name: 'theme-color', content: themeColor }],
})
</script>

<template>
  <UApp>
    <RouterView />
  </UApp>
</template>
