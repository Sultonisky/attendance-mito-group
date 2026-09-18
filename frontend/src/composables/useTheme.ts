/**
 * useTheme — thin wrapper di atas @vueuse/core useColorMode
 * Backward-compatible dengan kode yang sudah pakai { isDark, toggleTheme }
 */
import { computed } from 'vue'
import { useColorMode } from '@vueuse/core'

export function useTheme() {
  const colorMode = useColorMode({
    attribute: 'class',
    modes: { light: 'light', dark: 'dark' },
    storageKey: 'mito-theme',
  })

  const isDark = computed(() => colorMode.value === 'dark')

  function toggleTheme(): void {
    colorMode.value = isDark.value ? 'light' : 'dark'
  }

  return { isDark, toggleTheme, colorMode }
}
