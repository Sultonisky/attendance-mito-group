import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
import ui from "@nuxt/ui/vite";
import { defineConfig } from "vite";

// Asset URLs stay at site root (/assets/..., /images/...).
// Vue routes also stay root (/outsource, /dashboard) — no /frontend/ prefix.
export default defineConfig({
  base: "/",
  plugins: [
    vue(),
    tailwindcss(),
    ui({
      ui: {
        colors: {
          // MITO brand: red primary, slate neutral
          primary: "red",
          neutral: "slate",
        },
        // Default Nuxt UI Modal is max-w-lg (~512px) — too tight for admin forms.
        modal: {
          variants: {
            fullscreen: {
              false: {
                content:
                  "w-[calc(100vw-2rem)] max-w-3xl rounded-lg shadow-lg ring ring-default",
              },
            },
          },
        },
        // Nuxt UI Button only sets disabled:cursor-not-allowed — add pointer for actions.
        button: {
          slots: {
            base: "cursor-pointer",
          },
        },
        dropdownMenu: {
          slots: {
            item: "cursor-pointer",
          },
        },
        select: {
          slots: {
            base: "cursor-pointer",
          },
        },
        selectMenu: {
          slots: {
            base: "cursor-pointer",
            item: "cursor-pointer",
          },
        },
        checkbox: {
          slots: {
            base: "cursor-pointer",
            label: "cursor-pointer",
          },
        },
        switch: {
          slots: {
            base: "cursor-pointer",
            label: "cursor-pointer",
          },
        },
        tabs: {
          slots: {
            trigger: "cursor-pointer",
          },
        },
        pagination: {
          slots: {
            item: "cursor-pointer",
          },
        },
        navigationMenu: {
          slots: {
            link: "cursor-pointer",
          },
        },
      },
      theme: {
        colors: ["primary", "secondary", "success", "info", "warning", "error"],
        defaultVariants: {
          color: "primary",
        },
      },
    }),
  ],
  optimizeDeps: {
    exclude: ["maplibre-gl"],
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
            {
              name: "maplibre",
              test: /[\\/]node_modules[\\/]maplibre-gl[\\/]/,
            },
          ],
        },
      },
    },
  },
});
