<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import ErrorPageShell from '../../components/errors/ErrorPageShell.vue'
import {
  clearMaintenanceFlagCache,
  isMaintenanceFlagActive,
} from '../../services/maintenanceFlag'

/**
 * Poll until maintenance is cleared.
 * - Flag file: written/removed by `mito:maintenance`
 * - API health: absolute VITE_API_BASE_URL (works for local Vite + prod)
 */
const POLL_MS = 8_000
const hint = ref('Halaman akan terbuka otomatis setelah sistem siap.')
let timer: number | null = null
let cancelled = false

function apiHealthUrl(): string {
  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined)
    ?? 'http://localhost:8000/api/v1'
  return `${base.replace(/\/$/, '')}/health`
}

async function isApiHealthy(): Promise<boolean> {
  try {
    const response = await fetch(apiHealthUrl(), {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'include',
      cache: 'no-store',
    })
    return response.ok
  } catch {
    return false
  }
}

async function probe(): Promise<void> {
  if (cancelled) return

  try {
    clearMaintenanceFlagCache()
    const flagOn = await isMaintenanceFlagActive(true)
    if (flagOn) {
      schedule()
      return
    }

    if (await isApiHealthy()) {
      hint.value = 'Sistem siap. Membuka kembali…'
      window.location.replace('/')
      return
    }
  } catch {
    // Still down / network blip — keep polling.
  }

  schedule()
}

function schedule(): void {
  if (cancelled) return
  timer = window.setTimeout(() => {
    void probe()
  }, POLL_MS)
}

function onVisibility(): void {
  if (document.visibilityState !== 'visible') return
  if (timer !== null) {
    window.clearTimeout(timer)
    timer = null
  }
  void probe()
}

onMounted(() => {
  document.addEventListener('visibilitychange', onVisibility)
  schedule()
})

onBeforeUnmount(() => {
  cancelled = true
  document.removeEventListener('visibilitychange', onVisibility)
  if (timer !== null) window.clearTimeout(timer)
})
</script>

<template>
  <div>
    <ErrorPageShell
      code="503"
      title="Sedang Dalam Pemeliharaan"
      description="Sistem sedang tidak tersedia sementara karena pemeliharaan. Silakan coba lagi beberapa saat."
    />
    <p class="maintenance-hint" aria-live="polite">{{ hint }}</p>
  </div>
</template>

<style scoped>
.maintenance-hint {
  position: fixed;
  left: 50%;
  bottom: 1.75rem;
  transform: translateX(-50%);
  margin: 0;
  max-width: calc(100vw - 2rem);
  text-align: center;
  font-size: 0.8125rem;
  color: #64748b;
  z-index: 2;
  pointer-events: none;
}
</style>
