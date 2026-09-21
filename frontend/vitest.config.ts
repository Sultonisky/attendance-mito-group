import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// Minimal Vitest config — intentionally does NOT reuse vite.config.ts plugins
// (tailwindcss + @nuxt/ui) so unit tests stay fast and deterministic.
// Covers pure client helpers only (utils / types / composables). It never
// asserts business rules — Laravel remains the authority (see AGENTS.md).
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    globals: false,
    css: false,
    reporters: ['default'],
  },
})
