<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import type { LatLng } from '../utils/geo'

const props = defineProps<{
  target: LatLng
  radiusMeters: number
  user?: LatLng | null
  targetTitle?: string
}>()

const CARTO_API_KEY = (import.meta.env.VITE_CARTO_API_KEY as string | undefined)?.trim()
const CARTO_TILE_URL =
  (import.meta.env.VITE_CARTO_TILE_URL as string | undefined)?.trim() ||
  'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png'
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'

function resolveBasemap(): { url: string; attribution: string; subdomains: string } {
  // CARTO raster basemaps watermark tiles without an API key.
  if (CARTO_API_KEY) {
    const separator = CARTO_TILE_URL.includes('?') ? '&' : '?'
    return {
      url: `${CARTO_TILE_URL}${separator}key=${encodeURIComponent(CARTO_API_KEY)}`,
      attribution:
        import.meta.env.VITE_CARTO_ATTRIBUTION ??
        `${OSM_ATTRIBUTION} &copy; <a href="https://carto.com/attributions">CARTO</a>`,
      subdomains: 'abcd',
    }
  }

  return {
    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: OSM_ATTRIBUTION,
    subdomains: 'abc',
  }
}

function markerIcon(kind: 'target' | 'user'): L.DivIcon {
  const size = kind === 'target' ? 18 : 16
  const shape = kind === 'target'
    ? 'border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:#eb1c24;box-shadow:0 1px 4px rgba(15,23,42,0.25);'
    : 'border-radius:50%;background:#2563eb;box-shadow:0 0 0 6px rgba(37,99,235,0.18);'

  return L.divIcon({
    className: 'geofence-map-marker',
    html: `<span style="display:block;width:${size}px;height:${size}px;border:3px solid #fff;${shape}"></span>`,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
  })
}

const container = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let targetMarker: L.Marker | null = null
let radiusCircle: L.Circle | null = null
let userMarker: L.Marker | null = null

function render(recenter: boolean): void {
  if (!container.value) return

  const center: L.LatLngExpression = [props.target.lat, props.target.lng]

  if (!map) {
    map = L.map(container.value, { zoomControl: true, attributionControl: true }).setView(center, 16)
    const basemap = resolveBasemap()
    L.tileLayer(basemap.url, {
      attribution: basemap.attribution,
      subdomains: basemap.subdomains,
      maxZoom: 19,
    }).addTo(map)
    window.setTimeout(() => map?.invalidateSize(), 0)
  }

  if (!targetMarker) {
    targetMarker = L.marker(center, { icon: markerIcon('target'), title: props.targetTitle, zIndexOffset: 200 }).addTo(map)
  } else {
    targetMarker.setLatLng(center)
  }

  if (!radiusCircle) {
    radiusCircle = L.circle(center, {
      radius: props.radiusMeters,
      color: '#f59e0b',
      weight: 2,
      opacity: 0.8,
      fillColor: '#f97316',
      fillOpacity: 0.18,
    }).addTo(map)
  } else {
    radiusCircle.setLatLng(center)
    radiusCircle.setRadius(props.radiusMeters)
  }

  if (props.user) {
    const position: L.LatLngExpression = [props.user.lat, props.user.lng]
    if (!userMarker) {
      userMarker = L.marker(position, { icon: markerIcon('user'), title: 'Lokasi saat ini', zIndexOffset: 300 }).addTo(map)
    } else {
      userMarker.setLatLng(position)
    }
  } else if (userMarker) {
    userMarker.remove()
    userMarker = null
  }

  if (recenter) {
    if (props.user) {
      map.fitBounds(L.latLngBounds([center, [props.user.lat, props.user.lng]]), { padding: [40, 40], maxZoom: 17 })
    } else {
      map.setView(center, 16)
    }
  }
}

onMounted(async () => {
  await nextTick()
  render(true)
})

watch(
  () => [props.target.lat, props.target.lng, props.radiusMeters, props.user?.lat, props.user?.lng],
  () => render(true),
)

onBeforeUnmount(() => {
  map?.remove()
  map = null
  targetMarker = null
  radiusCircle = null
  userMarker = null
})
</script>

<template>
  <div ref="container" class="geofence-map" role="img" :aria-label="`Peta ${targetTitle ?? 'lokasi kerja'}`" />
</template>

<style scoped>
.geofence-map {
  width: 100%;
  height: 12rem;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid var(--border);
  z-index: 0;
}
</style>
