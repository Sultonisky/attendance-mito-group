import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
import ui from "@nuxt/ui/vite";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import type { Plugin } from "vite";
import { defineConfig } from "vite";

const frontendRoot = path.dirname(fileURLToPath(import.meta.url));

const DISABLED_MAINTENANCE_JSON =
  JSON.stringify(
    {
      enabled: false,
      retry_after: 60,
      message: "",
    },
    null,
    2,
  ) + "\n";

/**
 * Serve /maintenance.json without 404 when the gitignored flag file is absent.
 * If `php artisan mito:maintenance down` wrote the file, serve that instead.
 */
function maintenanceFlagPlugin(): Plugin {
  const flagPath = path.join(frontendRoot, "public", "maintenance.json");

  return {
    name: "mito-maintenance-flag",
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const url = req.url?.split("?")[0] ?? "";
        if (url !== "/maintenance.json") {
          next();
          return;
        }

        const body = fs.existsSync(flagPath)
          ? fs.readFileSync(flagPath, "utf8")
          : DISABLED_MAINTENANCE_JSON;

        res.statusCode = 200;
        res.setHeader("Content-Type", "application/json; charset=utf-8");
        res.setHeader("Cache-Control", "no-store");
        res.end(body);
      });
    },
  };
}

// Asset URLs stay at site root (/assets/..., /images/...).
// Vue routes also stay root (/outsource, /dashboard) — no /frontend/ prefix.
export default defineConfig({
  base: "/",
  plugins: [
    maintenanceFlagPlugin(),
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
