/**
 * useTheme — single shared color-mode instance for the whole app.
 *
 * The app is LIGHT-ONLY: employee-facing pages use hard-coded light
 * surfaces, so dark mode (especially OS "auto") produces white/slate text
 * on light backgrounds. The mode is therefore pinned to "light" and the
 * OS preference is never read. Keep using this composable instead of
 * calling useColorMode() directly so every consumer stays in sync.
 */
import { computed } from 'vue'
import { createGlobalState, useColorMode } from '@vueuse/core'

export const useTheme = createGlobalState(() => {
  const colorMode = useColorMode({
    attribute: 'class',
    modes: { light: 'light', dark: 'dark' },
    storageKey: 'mito-theme',
    selector: 'html',
    initialValue: 'light',
    // Never follow the OS ("auto") and never persist a dark choice.
    storage: createLightOnlyStorage(),
  })

  const isDark = computed(() => colorMode.value === 'dark')

  function toggleTheme(): void {
    // Intentionally a no-op: the app is light-only.
    colorMode.value = 'light'
  }

  return { isDark, toggleTheme, colorMode }
})

/**
 * A localStorage-like wrapper that always reports "light" so a stale
 * "dark" value saved by earlier versions cannot re-enable dark mode.
 */
function createLightOnlyStorage() {
  return {
    getItem: (_key: string) => 'light' as const,
    setItem: (_key: string, _value: string) => {},
    removeItem: (_key: string) => {},
  }
}
