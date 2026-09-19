<script setup lang="ts">
import { computed } from 'vue'
import { RouterView } from 'vue-router'
import { useHead } from '@unhead/vue'
import { useColorMode } from '@vueuse/core'

const colorMode = useColorMode()
const themeColor = computed(() =>
  colorMode.value === 'dark' ? '#0d1526' : '#ffffff',
)

useHead({
  meta: [{ name: 'theme-color', content: themeColor }],
})
</script>

<template>
  <UApp>
    <RouterView />
  </UApp>
</template>
