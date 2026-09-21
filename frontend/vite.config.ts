import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import ui from '@nuxt/ui/vite'
import { defineConfig } from 'vite'

export default defineConfig({
base: '/frontend/',
  plugins: [
    vue(),
    tailwindcss(),
    ui({
      ui: {
        colors: {
          // MITO brand: red primary, slate neutral
          primary: 'red',
          neutral: 'slate',
        },
      },
      theme: {
        colors: ['primary', 'secondary', 'success', 'info', 'warning', 'error'],
        defaultVariants: {
          color: 'primary',
        },
      },
    }),
  ],
  optimizeDeps: {
    exclude: ['maplibre-gl'],
  },
  build: {
    chunkSizeWarningLimit: 1100,
    rolldownOptions: {
      external: [
        // Native binaries cannot be bundled by rolldown
        /\.node$/,
      ],
      output: {
        codeSplitting: {
          groups: [
            { name: 'maplibre', test: /[\\/]node_modules[\\/]maplibre-gl[\\/]/ },
          ],
        },
      },
    },
  },
})
