<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import AppButton from "../../components/AppButton.vue";
import AppIcon from "../../components/AppIcon.vue";
import { ApiError } from "../../services/apiClient";
import {
  fetchOutsourceCities,
  fetchOutsourceStores,
  fetchOutsourceOutsources,
  fetchOutsourceSessionCurrent,
  // Disabled: riwayat presensi (re-enable with ENABLE_OUTSOURCE_ATTENDANCE_HISTORY)
  fetchOutsourceAttendanceHistory,
  initOutsourceSession,
  loginOutsourceSession,
  outsourceCheckIn,
  outsourceCheckOut,
  type City,
  type Store,
  type Outsource,
  type OutsourcePin,
  type OutsourceAttendanceResponse,
  // Disabled: riwayat presensi
  type OutsourceHistoryItem,
  type OutsourceSessionPayload,
} from "../../services/outsourceService";
import {
  formatAttendanceLongDate,
  formatAttendanceShortDate,
  formatAttendanceTime,
  formatAttendanceTimeWithSeconds,
} from "../../utils/attendanceDateTime";

type Step =
  | "login"
  | "greet"
  | "city"
  | "store"
  | "outsource"
  | "session"
  | "attendance_open"
  | "completed";

/** Basemap tiles for store/GPS visualization only (not live tracking). */
const CARTO_API_KEY = (import.meta.env.VITE_CARTO_API_KEY as string | undefined)?.trim();
const CARTO_TILE_URL =
  (import.meta.env.VITE_CARTO_TILE_URL as string | undefined)?.trim() ||
  "https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png";
const OSM_TILE_URL = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
const CARTO_TILE_ATTRIBUTION =
  import.meta.env.VITE_CARTO_ATTRIBUTION ??
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';
const OSM_TILE_ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

function resolveBasemap(): { url: string; attribution: string; subdomains: string } {
  // CARTO raster basemaps now watermark tiles without a free API key.
  if (CARTO_API_KEY) {
    const separator = CARTO_TILE_URL.includes("?") ? "&" : "?";
    return {
      url: `${CARTO_TILE_URL}${separator}key=${encodeURIComponent(CARTO_API_KEY)}`,
      attribution: CARTO_TILE_ATTRIBUTION,
      subdomains: "abcd",
    };
  }

  return {
    url: OSM_TILE_URL,
    attribution: OSM_TILE_ATTRIBUTION,
    subdomains: "abc",
  };
}

const step = ref<Step>("login");

function isInitiationStep(
  value: Step,
): value is Exclude<Step, "login" | "greet" | "completed"> {
  return value !== "login" && value !== "greet" && value !== "completed";
}

const expiresAt = ref<string | null>(null);
const loginCode = ref("");
const loginPassword = ref("");
const showLoginPassword = ref(false);

function onLoginPinInput(event: Event): void {
  const target = event.target as HTMLInputElement;
  loginPassword.value = target.value.replace(/\D/g, "").slice(0, 8);
}
const allowedPins = ref<OutsourcePin[]>([]);
const selectedPinId = ref<number | null>(null);
const hasServerSession = ref(false);
const mapContainer = ref<HTMLElement | null>(null);
const mapError = ref("");
const currentMapLocation = ref<{
  latitude: number;
  longitude: number;
  accuracy?: number;
} | null>(null);
let cartoMap: L.Map | null = null;
let storeMarker: L.Marker | null = null;
let userMarker: L.Marker | null = null;
let storeRadius: L.Circle | null = null;

const cities = ref<City[]>([]);
const stores = ref<Store[]>([]);
const outsources = ref<Outsource[]>([]);

const cityFallbackCoordinates: Record<string, { lat: number; lng: number }> = {
  BALIKPAPAN: { lat: -1.2379, lng: 116.8529 },
  BANDUNG: { lat: -6.9175, lng: 107.6191 },
  BANGKA: { lat: -2.1316, lng: 106.1169 },
  "BANJAR BARU": { lat: -3.442, lng: 114.845 },
  BANJARMASIN: { lat: -3.3186, lng: 114.5944 },
  BANYUMAS: { lat: -7.516, lng: 109.294 },
  BANYUWANGI: { lat: -8.219, lng: 114.369 },
  BATAM: { lat: 1.0456, lng: 104.0305 },
  BATU: { lat: -7.87, lng: 112.523 },
  BOGOR: { lat: -6.595, lng: 106.816 },
  BONTANG: { lat: 0.1333, lng: 117.5 },
  CIREBON: { lat: -6.732, lng: 108.552 },
  DEPOK: { lat: -6.4025, lng: 106.7942 },
  GARUT: { lat: -7.2167, lng: 107.9 },
  JAKARTA: { lat: -6.2088, lng: 106.8456 },
  JEMBER: { lat: -8.172, lng: 113.7 },
  KEDIRI: { lat: -7.8167, lng: 112.0167 },
  KETAPANG: { lat: -1.85, lng: 109.983 },
  KLATEN: { lat: -7.7058, lng: 110.606 },
  "KOTAWARINGIN BARAT": { lat: -2.683, lng: 111.617 },
  KUDUS: { lat: -6.8048, lng: 110.8405 },
  "LAMPUNG-METRO": { lat: -5.113, lng: 105.306 },
  LUBUKLINGGAU: { lat: -3.296, lng: 102.861 },
  MAKASSAR: { lat: -5.1477, lng: 119.4327 },
  MALANG: { lat: -7.9666, lng: 112.6326 },
  MATARAM: { lat: -8.5833, lng: 116.1167 },
  MEDAN: { lat: 3.5952, lng: 98.6722 },
  "MUARA ENIM": { lat: -3.65, lng: 103.77 },
  PADANG: { lat: -0.9471, lng: 100.4172 },
  PALEMBANG: { lat: -2.9761, lng: 104.7754 },
  PALU: { lat: -0.9003, lng: 119.8779 },
  PASER: { lat: -1.91, lng: 116.2 },
  PATI: { lat: -6.75, lng: 111.04 },
  PEKALONGAN: { lat: -6.8898, lng: 109.6753 },
  PEKANBARU: { lat: 0.5071, lng: 101.4478 },
  PONOROGO: { lat: -7.865, lng: 111.469 },
  PONTIANAK: { lat: -0.0263, lng: 109.3425 },
  SAMARINDA: { lat: -0.5022, lng: 117.1536 },
  SAMPIT: { lat: -2.533, lng: 112.95 },
  SEMARANG: { lat: -6.9667, lng: 110.4167 },
  SIDOARJO: { lat: -7.4478, lng: 112.7183 },
  SUKOHARJO: { lat: -7.683, lng: 110.84 },
  SURABAYA: { lat: -7.2575, lng: 112.7521 },
  TABALONG: { lat: -2.18, lng: 115.43 },
  "TANAH BUMBU": { lat: -3.45, lng: 115.7 },
  TANGERANG: { lat: -6.1783, lng: 106.6319 },
  TENGGARONG: { lat: -0.42, lng: 116.99 },
  TULUNGAGUNG: { lat: -8.0667, lng: 111.9 },
  YOGYAKARTA: { lat: -7.7956, lng: 110.3695 },
};

const selectedCity = ref<number | null>(null);
const selectedStore = ref<number | null>(null);
const selectedStoreName = ref<string>("");
const selectedCityName = ref<string>("");
const selectedOutsource = ref<Outsource | null>(null);

/**
 * Riwayat presensi di greeting page — DISABLED (kept for later).
 * Set true to show UI + fetch /outsource/attendance/history again.
 */
const ENABLE_OUTSOURCE_ATTENDANCE_HISTORY = false;

// Disabled history state (inactive while flag is false)
const attendanceHistory = ref<OutsourceHistoryItem[]>([]);
const isLoadingHistory = ref(false);

const selectedOutsourceLabel = computed(() => {
  if (!selectedOutsource.value) return "";

  return selectedOutsource.value.name;
});

const greetFirstName = computed(() => {
  const name = selectedOutsource.value?.name?.trim() ?? "";
  if (!name) return "Personel";
  return name.split(/\s+/)[0] ?? name;
});

const greetStatusLabel = computed(() =>
  checkInAt.value ? "Sedang bertugas" : "Siap clock in",
);

const greetHint = computed(() => {
  if (checkInAt.value) {
    return "Sesi masih terbuka. Lanjutkan clock out di pin point yang sesuai saat Anda selesai.";
  }
  return "Lanjut ke langkah berikutnya untuk memilih pin point, cek jarak GPS, lalu clock in.";
});

const greetAllowedPinsLabel = computed(() => {
  const count = allowedPins.value.length;
  if (count === 0) return "Semua pin cabang";
  if (count === 1) return allowedPins.value[0]?.name || "1 pin point";
  return `${count} pin point`;
});

const greetPinsSectionTitle = computed(() => {
  const count = allowedPins.value.length;
  if (count > 1) return `${count} pin point tersedia`;
  if (count === 1) return "Pin point penugasan";
  return "Lokasi kerja yang diizinkan";
});

const greetPinsSectionHint = computed(() => {
  if (allowedPins.value.length > 1) {
    return "Pin aktif dipilih nanti saat clock in / clock out — tidak dikunci di halaman ini.";
  }
  if (allowedPins.value.length === 1) {
    return "Satu pin point terhubung ke penugasan Anda.";
  }
  return "Semua pin aktif di cabang dapat dipilih saat absensi.";
});

function formatHistoryStatus(status: string): string {
  // Disabled helper — used only when ENABLE_OUTSOURCE_ATTENDANCE_HISTORY is true
  const normalized = status.trim().toLowerCase();
  if (normalized === "present" || normalized === "completed") return "Selesai";
  if (normalized === "incomplete" || normalized === "open") return "Belum clock out";
  if (normalized === "absent") return "Tidak hadir";
  return status || "—";
}

function formatHistoryDuration(minutes: number | null): string {
  // Disabled helper — used only when ENABLE_OUTSOURCE_ATTENDANCE_HISTORY is true
  if (minutes === null || !Number.isFinite(minutes) || minutes <= 0) return "—";
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;
  if (hours <= 0) return `${mins} m`;
  if (mins <= 0) return `${hours} j`;
  return `${hours} j ${mins} m`;
}

function formatSelectLabel(name: string, code?: string | null): string {
  const label = (name || "").trim();
  if (!label) return "";

  const normalizedCode = (code || "").trim();
  if (!normalizedCode) return label;

  // Generated codes often look like "BANDUNG-6d69a7" / "ADELINA-ASTUTI-c6a64b3e"
  // — showing those next to the same name feels duplicated for users.
  const nameSlug = label
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
  const codeSlug = normalizedCode.toUpperCase();
  if (
    codeSlug === nameSlug ||
    codeSlug.startsWith(`${nameSlug}-`) ||
    codeSlug.includes(nameSlug)
  ) {
    return label;
  }

  return `${label} (${normalizedCode})`;
}

const attendanceId = ref<number | null>(null);
const attendanceStatus = ref<string | null>(null);
const attendanceDate = ref<string | null>(null);
const checkInAt = ref<string | null>(null);
const checkOutAt = ref<string | null>(null);
const durationMinutes = ref<number | null>(null);

const isLoading = ref(false);
const isSubmitting = ref(false);
const error = ref("");
const message = ref("");

const locationAccuracy = ref<number | null>(null);
const locationStatus = ref<"idle" | "locating" | "ready" | "error">("idle");
/** Accuracy gate only — store geofence radius stays fixed at 150 m.
 *  Laptop/Wi‑Fi often reports ±50–100 m (no GPS chip). Phones outdoors can do better.
 */
const maxGpsAccuracyMeters = Number(
  import.meta.env.VITE_GPS_MAX_ACCURACY_METERS ?? 100,
);
/** Hard stop for GPS settle so UI never stays on "Menstabilkan" forever. */
const GPS_SETTLE_TIMEOUT_MS = 25_000;
/** First-fix budget; Safari/iOS needs longer than Chrome desktop. */
const GPS_QUICK_TIMEOUT_MS = 15_000;
/** Cached reading window — Safari often fails with maximumAge:0 before a fix exists. */
const GPS_SAFARI_MAX_AGE_MS = 60_000;
/** Safari settle: poll getCurrentPosition (do NOT use watchPosition — known WebKit conflict). */
const GPS_SAFARI_POLL_MS = 2_500;

function isSafariOrIOS(): boolean {
  if (typeof navigator === "undefined") return false;
  const ua = navigator.userAgent;
  const iOS = /iPad|iPhone|iPod/.test(ua)
    || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  const safariDesktop = /Safari/i.test(ua)
    && !/Chrome|Chromium|CriOS|Edg|EdgiOS|Firefox|FxiOS|OPR|Opera/i.test(ua);
  return iOS || safariDesktop;
}

function geolocationBlockReason(): string | null {
  if (typeof window !== "undefined" && window.isSecureContext === false) {
    return "Lokasi hanya bisa dipakai lewat HTTPS. Buka ulang halaman dengan alamat https:// (bukan http://).";
  }
  if (typeof navigator === "undefined" || !navigator.geolocation) {
    return "Browser ini tidak mendukung layanan lokasi.";
  }
  return null;
}

function buildGeoOptions(highAccuracy: boolean): PositionOptions {
  const safari = isSafariOrIOS();
  return {
    enableHighAccuracy: highAccuracy,
    timeout: safari ? 30_000 : GPS_QUICK_TIMEOUT_MS,
    // MDN: maximumAge 0 forces a fresh fix; Safari frequently fails before GPS warms up.
    maximumAge: safari
      ? (highAccuracy ? GPS_SAFARI_MAX_AGE_MS / 2 : GPS_SAFARI_MAX_AGE_MS)
      : highAccuracy
        ? 0
        : 5_000,
  };
}


const isGpsAccuracyAcceptable = computed(
  () =>
    locationStatus.value === "ready" &&
    locationAccuracy.value !== null &&
    locationAccuracy.value <= maxGpsAccuracyMeters,
);

const now = ref(new Date());
/** Option B: off by default — user opts into continuous distance updates. */
const followDistance = ref(false);
const isRefreshingDistance = ref(false);
let clockTimer: number | null = null;
let gpsWatchId: number | null = null;
/** Separate from settle watch — only used when "Ikuti jarak" is on. */
let proximityWatchId: number | null = null;
let proximityLastUiAt = 0;
let gpsSettleTimer: number | null = null;
/** Safari-only: interval id for getCurrentPosition polling (avoid watchPosition). */
let safariPollTimer: number | null = null;
let gpsRequestId = 0;
let activeGpsResolve:
  | ((
      value: {
        latitude: number;
        longitude: number;
        accuracy?: number;
      } | null,
    ) => void)
  | null = null;

/** Throttle proximity UI updates to reduce GPS jitter. */
const PROXIMITY_UI_THROTTLE_MS = 2_500;

const attendanceDurationSeconds = computed(() => {
  if (!isAttendanceOpen.value || !checkInAt.value) {
    return null;
  }

  const checkInTime = new Date(checkInAt.value).getTime();
  if (!Number.isFinite(checkInTime)) {
    return null;
  }

  const elapsedMs = now.value.getTime() - checkInTime;
  return Math.max(0, Math.floor(elapsedMs / 1000));
});

const attendanceDurationLabel = computed(() => {
  if (step.value === "completed" && durationMinutes.value !== null) {
    const totalSeconds = Math.max(0, Math.round(durationMinutes.value * 60));
    return formatDurationSeconds(totalSeconds);
  }

  if (step.value === "attendance_open" && checkInAt.value) {
    return formatDurationSeconds(attendanceDurationSeconds.value);
  }

  return "00:00:00";
});

const isSessionActive = computed(
  () => step.value === "session" || step.value === "attendance_open",
);
const isAttendanceOpen = computed(() => step.value === "attendance_open");

const STORE_GEOFENCE_RADIUS_METERS = 150;

/** Active pin geofence radius (pin override, else default 150 m). */
const activeGeofenceRadiusMeters = computed(() => {
  const pin =
    selectedPin.value ??
    (allowedPins.value.length === 1 ? allowedPins.value[0] : null);
  if (pin?.radius_meters != null && Number(pin.radius_meters) > 0) {
    return Number(pin.radius_meters);
  }
  return STORE_GEOFENCE_RADIUS_METERS;
});

const activeGeofenceRadiusLabel = computed(() => {
  const meters = activeGeofenceRadiusMeters.value;
  if (meters >= 1000) {
    const km = meters / 1000;
    return Number.isInteger(km) ? `${km} km` : `${km.toFixed(1)} km`;
  }
  return `${Math.round(meters)} m`;
});

/** Straight-line distance (meters) from current GPS to selected store. */
const distanceToStoreMeters = computed((): number | null => {
  if (!selectedStoreLocation.value || !currentMapLocation.value) return null;

  const earthRadius = 6371000;
  const dLat =
    ((currentMapLocation.value.latitude - selectedStoreLocation.value.lat) *
      Math.PI) /
    180;
  const dLng =
    ((currentMapLocation.value.longitude - selectedStoreLocation.value.lng) *
      Math.PI) /
    180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((selectedStoreLocation.value.lat * Math.PI) / 180) *
      Math.cos((currentMapLocation.value.latitude * Math.PI) / 180) *
      Math.sin(dLng / 2) ** 2;

  return 2 * earthRadius * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
});

const isInsideStoreRadius = computed(() => {
  const distance = distanceToStoreMeters.value;
  return distance !== null && distance <= activeGeofenceRadiusMeters.value;
});

/** Big hero number for Live Tracking. */
const distanceHeroValue = computed(() => {
  const distance = distanceToStoreMeters.value;
  if (distance === null) return "—";
  if (distance < 1000) return `${Math.round(distance)}`;
  return (distance / 1000).toFixed(1);
});

const distanceHeroUnit = computed(() => {
  const distance = distanceToStoreMeters.value;
  if (distance === null) return "m";
  return distance < 1000 ? "m" : "km";
});

const distanceHeroCaption = computed(() => {
  if (allowedPins.value.length > 1 && !selectedPin.value) {
    return "Pilih pin point dulu untuk mengukur jarak";
  }
  if (!selectedStoreLocation.value) {
    return "Koordinat pin point belum tersedia";
  }
  if (locationStatus.value === "locating") {
    return "Mengukur jarak ke pin point…";
  }
  if (locationStatus.value === "error" || distanceToStoreMeters.value === null) {
    return "Aktifkan GPS untuk mengukur jarak";
  }
  if (isInsideStoreRadius.value) {
    return "Sudah dalam radius — siap absen";
  }
  const remaining = Math.max(
    0,
    Math.round((distanceToStoreMeters.value ?? 0) - activeGeofenceRadiusMeters.value),
  );
  return `${remaining} m lagi ke radius absensi`;
});

const remainingToRadiusMeters = computed((): number | null => {
  const distance = distanceToStoreMeters.value;
  if (distance === null) return null;
  return Math.max(0, Math.round(distance - activeGeofenceRadiusMeters.value));
});

const selectedPinLabel = computed(() => selectedPin.value?.name ?? null);

/** Assignment context only — never implies a specific active pin. */
const assignmentLocationLabel = computed(() => {
  const parts = [selectedCityName.value.trim(), selectedStoreName.value.trim()].filter(
    (part) => part.length > 0,
  );
  return parts.length > 0 ? parts.join(" · ") : "Belum dipilih";
});

/**
 * Active pin for stats: selected pin, single-pin auto case, or multi-pin pending.
 * Does not silently treat the first of many pins as “aktif”.
 */
const activePinStatsLabel = computed(() => {
  if (selectedPin.value?.name) {
    return selectedPin.value.name;
  }
  const count = allowedPins.value.length;
  if (count === 1) {
    return allowedPins.value[0]?.name || "1 pin point";
  }
  if (count > 1) {
    return `Belum dipilih · ${count} pin`;
  }
  return "Belum ada pin";
});

const activePinStatsDetail = computed(() => {
  if (selectedPin.value) {
    return (
      selectedPin.value.address?.trim() ||
      `Radius validasi ${activeGeofenceRadiusLabel.value}`
    );
  }
  const count = allowedPins.value.length;
  if (count > 1) {
    return "Pilih pin point di kartu absensi sebelum clock in/out";
  }
  if (count === 1) {
    return (
      allowedPins.value[0]?.address?.trim() ||
      `Radius validasi ${activeGeofenceRadiusLabel.value}`
    );
  }
  return "Tidak ada pin aktif untuk penugasan ini";
});

const mapLocationMetaLabel = computed(() => {
  if (selectedPin.value) return "Pin aktif";
  if (allowedPins.value.length > 1) return "Preview pin";
  return "Cabang";
});

const mapLocationMetaValue = computed(() => {
  if (selectedPinLabel.value) return selectedPinLabel.value;
  if (allowedPins.value.length > 1) {
    return `${allowedPins.value.length} opsi — pilih dulu`;
  }
  return selectedStoreName.value || "Cabang";
});

const heroDistanceStatusClass = computed(() => {
  if (locationStatus.value === "locating") return "hero-distance--pending";
  if (locationStatus.value === "error" || distanceToStoreMeters.value === null) {
    return "hero-distance--pending";
  }
  if (isInsideStoreRadius.value) return "hero-distance--inside";
  return "hero-distance--outside";
});

const distanceBadgeLabel = computed(() => {
  if (locationStatus.value === "locating") return "Mengukur…";
  if (locationStatus.value === "error") return "GPS error";
  if (distanceToStoreMeters.value === null) return "Belum ada";
  if (isInsideStoreRadius.value) return "Dalam radius";
  return "Di luar radius";
});

const distanceBadgeClass = computed(() => {
  if (locationStatus.value === "locating") return "badge-locating";
  if (locationStatus.value === "error" || distanceToStoreMeters.value === null)
    return "badge-error";
  if (isInsideStoreRadius.value) return "badge-ready";
  return "badge-outside";
});

const statusTitle = computed(() => {
  if (step.value === "completed") return "Presensi Selesai";
  if (isAttendanceOpen.value) return "Sedang Bertugas";
  if (isSessionActive.value) return "Siap Presensi Masuk";
  return "Inisiasi Sesi";
});

function toMapCoords(
  rawLatitude: unknown,
  rawLongitude: unknown,
): { lat: number; lng: number } | null {
  if (
    rawLatitude === null ||
    rawLatitude === undefined ||
    rawLongitude === null ||
    rawLongitude === undefined ||
    rawLatitude === "" ||
    rawLongitude === ""
  ) {
    return null;
  }

  const latitude = Number(rawLatitude);
  const longitude = Number(rawLongitude);

  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
  if (latitude < -90 || latitude > 90) return null;
  if (longitude < -180 || longitude > 180) return null;
  if (latitude === 0 && longitude === 0) return null;

  return { lat: latitude, lng: longitude };
}

/** Normalize select v-model (can arrive as string) to a numeric pin id. */
function resolveSelectedPinId(): number | null {
  const raw = selectedPinId.value as number | string | null;
  if (raw === null || raw === undefined || raw === "") return null;
  const id = typeof raw === "number" ? raw : Number(raw);
  return Number.isFinite(id) ? id : null;
}

const selectedPin = computed(() => {
  const id = resolveSelectedPinId();
  if (id === null) return null;
  return allowedPins.value.find((pin) => pin.id === id) ?? null;
});

/**
 * Geofence / distance target.
 * Multi-pin: only the user-selected pin (never silently pick the first of many).
 * Single pin: that pin's coordinates.
 * Fallback: store coordinates when no pins exist.
 */
const selectedStoreLocation = computed(() => {
  const pin = selectedPin.value;
  if (pin) {
    return toMapCoords(pin.latitude, pin.longitude);
  }

  if (allowedPins.value.length === 1) {
    return toMapCoords(
      allowedPins.value[0]?.latitude,
      allowedPins.value[0]?.longitude,
    );
  }

  // Multiple pins, none selected — wait for explicit choice for accurate distance.
  if (allowedPins.value.length > 1) {
    return null;
  }

  if (selectedStore.value !== null) {
    const store = stores.value.find((item) => item.id === selectedStore.value);
    if (store) {
      return toMapCoords(store.latitude, store.longitude);
    }
  }

  return null;
});

const selectedCityLocation = computed(() => {
  if (selectedCity.value === null) return null;

  const city = cities.value.find((item) => item.id === selectedCity.value);
  if (!city) return null;

  return cityFallbackCoordinates[city.name.trim().toUpperCase()] ?? null;
});

/** Map preview center — may show first allowed pin only as preview when none selected. */
const selectedMapLocation = computed(() => {
  if (selectedStoreLocation.value) {
    return selectedStoreLocation.value;
  }

  if (allowedPins.value.length > 1) {
    for (const candidate of allowedPins.value) {
      const pinCoords = toMapCoords(candidate.latitude, candidate.longitude);
      if (pinCoords) return pinCoords;
    }
  }

  if (selectedStore.value !== null) {
    const store = stores.value.find((item) => item.id === selectedStore.value);
    if (store) {
      const storeCoords = toMapCoords(store.latitude, store.longitude);
      if (storeCoords) return storeCoords;
    }
  }

  return selectedCityLocation.value;
});

const isUsingCityFallback = computed(
  () =>
    !selectedStoreLocation.value &&
    !allowedPins.value.some((pin) => toMapCoords(pin.latitude, pin.longitude)) &&
    Boolean(selectedCityLocation.value),
);

const showStoreMap = computed(() => {
  if (selectedStore.value === null || !selectedMapLocation.value) return false;
  return (
    step.value === "store" ||
    step.value === "outsource" ||
    step.value === "session" ||
    step.value === "attendance_open"
  );
});

const mapDistanceLabel = computed(() => {
  const distance = distanceToStoreMeters.value;
  if (distance === null) return null;

  if (distance < 1000) {
    return `${Math.round(distance)} m dari pin point`;
  }

  return `${(distance / 1000).toFixed(1)} km dari pin point`;
});

const actionButtonLabel = computed(() => {
  if (isSubmitting.value) return "Memproses Presensi...";
  if (isAttendanceOpen.value) return "Clock Out Sekarang";
  return "Clock In Sekarang";
});

function formatCurrentDate(): string {
  return formatAttendanceLongDate(now.value)
}

function formatCurrentTime(): string {
  return formatAttendanceTimeWithSeconds(now.value)
}

function formatDurationSeconds(totalSeconds: number | null): string {
  if (totalSeconds === null || !Number.isFinite(totalSeconds)) {
    return "--:--:--";
  }

  const safeSeconds = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(safeSeconds / 3600);
  const minutes = Math.floor((safeSeconds % 3600) / 60);
  const seconds = safeSeconds % 60;

  return [hours, minutes, seconds]
    .map((value) => value.toString().padStart(2, "0"))
    .join(":");
}

function formatTime(iso: string | null): string {
  return formatAttendanceTime(iso, "--:--");
}

function formatDate(iso: string | null): string {
  return formatAttendanceShortDate(iso, "--");
}

function createDivIcon(
  className: string,
  size: number,
  options: { rotate?: boolean; glow?: boolean } = {},
): L.DivIcon {
  const rotate = options.rotate
    ? "border-radius:50% 50% 50% 0;transform:rotate(-45deg);"
    : "border-radius:50%;";
  const glow = options.glow
    ? "box-shadow:0 0 0 6px rgba(37,99,235,0.18);"
    : "box-shadow:0 1px 4px rgba(15,23,42,0.25);";
  const background = className === "store" ? "#eb1c24" : "#2563eb";

  return L.divIcon({
    className: "carto-map-marker",
    html: `<span style="display:block;width:${size}px;height:${size}px;border:3px solid #fff;background:${background};${rotate}${glow}"></span>`,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
  });
}

function destroyCartoMap(): void {
  if (cartoMap) {
    cartoMap.remove();
    cartoMap = null;
  }
  storeMarker = null;
  userMarker = null;
  storeRadius = null;
}

function updateCartoMap(options?: { recenterOnTarget?: boolean }): void {
  if (!selectedMapLocation.value || !mapContainer.value) {
    return;
  }

  if (cartoMap && cartoMap.getContainer() !== mapContainer.value) {
    destroyCartoMap();
  }

  const center: L.LatLngExpression = [
    selectedMapLocation.value.lat,
    selectedMapLocation.value.lng,
  ];
  const isNewMap = !cartoMap;
  const shouldRecenter = isNewMap || options?.recenterOnTarget === true;

  if (!cartoMap) {
    cartoMap = L.map(mapContainer.value, {
      zoomControl: true,
      attributionControl: true,
    }).setView(center, 16);

    const basemap = resolveBasemap();
    L.tileLayer(basemap.url, {
      attribution: basemap.attribution,
      subdomains: basemap.subdomains,
      maxZoom: 19,
    }).addTo(cartoMap);

    // Leaflet needs a layout pass after the container becomes visible.
    window.setTimeout(() => cartoMap?.invalidateSize(), 0);
  } else if (shouldRecenter) {
    if (selectedStoreLocation.value && currentMapLocation.value) {
      const bounds = L.latLngBounds([
        [selectedStoreLocation.value.lat, selectedStoreLocation.value.lng],
        [currentMapLocation.value.latitude, currentMapLocation.value.longitude],
      ]);
      cartoMap.fitBounds(bounds, { padding: [48, 48], maxZoom: 17, animate: true });
    } else {
      cartoMap.setView(center, Math.max(cartoMap.getZoom(), 16), { animate: true });
    }
  }

  const targetTitle = isUsingCityFallback.value
    ? "Perkiraan pusat kota"
    : selectedPinLabel.value || selectedStoreName.value || "Pin point";

  if (!storeMarker) {
    storeMarker = L.marker(center, {
      icon: createDivIcon("store", 18, { rotate: true }),
      title: targetTitle,
      zIndexOffset: 200,
    }).addTo(cartoMap);
  } else {
    storeMarker.setLatLng(center);
    storeMarker.options.title = targetTitle;
  }

  if (!selectedStoreLocation.value) {
    if (storeRadius) {
      storeRadius.remove();
      storeRadius = null;
    }
  } else {
    const radiusCenter: L.LatLngExpression = [
      selectedStoreLocation.value.lat,
      selectedStoreLocation.value.lng,
    ];
    if (!storeRadius) {
      storeRadius = L.circle(radiusCenter, {
        radius: activeGeofenceRadiusMeters.value,
        color: "#f59e0b",
        weight: 2,
        opacity: 0.8,
        fillColor: "#f97316",
        fillOpacity: 0.18,
      }).addTo(cartoMap);
    } else {
      storeRadius.setLatLng(radiusCenter);
      storeRadius.setRadius(activeGeofenceRadiusMeters.value);
    }
  }

  if (currentMapLocation.value) {
    const userPosition: L.LatLngExpression = [
      currentMapLocation.value.latitude,
      currentMapLocation.value.longitude,
    ];

    if (!userMarker) {
      userMarker = L.marker(userPosition, {
        icon: createDivIcon("user", 16, { glow: true }),
        title: "Lokasi saat ini",
        zIndexOffset: 300,
      }).addTo(cartoMap);
    } else {
      userMarker.setLatLng(userPosition);
    }
  } else if (userMarker) {
    userMarker.remove();
    userMarker = null;
  }
}

async function ensureCartoMapReady(options?: { recenterOnTarget?: boolean }): Promise<void> {
  if (!selectedMapLocation.value) {
    return;
  }

  try {
    await nextTick();
    if (!isUsingCityFallback.value) {
      mapError.value = "";
    }
    updateCartoMap({ recenterOnTarget: options?.recenterOnTarget ?? true });
  } catch (error) {
    console.error("CARTO map initialization failed.", error);
    mapError.value =
      error instanceof Error
        ? `Peta gagal dimuat: ${error.message}`
        : "Peta gagal dimuat. Silakan coba lagi nanti.";
  }
}

async function onPinSelected(): Promise<void> {
  const normalized = resolveSelectedPinId();
  selectedPinId.value = normalized;

  if (normalized === null) {
    return;
  }

  const pin = allowedPins.value.find((item) => item.id === normalized);
  if (!pin || !toMapCoords(pin.latitude, pin.longitude)) {
    error.value =
      "Pin point yang dipilih belum punya koordinat. Pilih pin lain atau hubungi admin.";
    return;
  }

  error.value = "";
  await nextTick();
  if (showStoreMap.value) {
    await ensureCartoMapReady({ recenterOnTarget: true });
  }
}

function stopGpsWatch(): void {
  if (gpsWatchId !== null && navigator.geolocation) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
  }
  if (safariPollTimer !== null) {
    window.clearInterval(safariPollTimer);
    safariPollTimer = null;
  }
  if (gpsSettleTimer !== null) {
    window.clearTimeout(gpsSettleTimer);
    gpsSettleTimer = null;
  }
}

function applyProximityReading(position: GeolocationPosition): void {
  const accuracy = Number(position.coords.accuracy);
  if (!Number.isFinite(accuracy) || accuracy < 0) {
    return;
  }

  const nowMs = Date.now();
  if (nowMs - proximityLastUiAt < PROXIMITY_UI_THROTTLE_MS) {
    return;
  }
  proximityLastUiAt = nowMs;

  locationAccuracy.value = Math.round(accuracy);
  currentMapLocation.value = {
    latitude: position.coords.latitude,
    longitude: position.coords.longitude,
    accuracy,
  };
  updateCartoMap();
}

function startProximityWatch(): void {
  if (!navigator.geolocation) {
    followDistance.value = false;
    error.value = "Browser ini tidak mendukung layanan lokasi.";
    return;
  }
  if (!isSessionActive.value || !followDistance.value) {
    return;
  }
  if (document.hidden) {
    return;
  }
  // Don't stack with settle watch — settle owns GPS until ready.
  if (locationStatus.value === "locating" || gpsWatchId !== null || safariPollTimer !== null) {
    return;
  }
  if (proximityWatchId !== null) {
    return;
  }

  // Safari/iOS: avoid watchPosition (conflicts with later getCurrentPosition).
  if (isSafariOrIOS()) {
    proximityWatchId = window.setInterval(() => {
      if (!followDistance.value || !isSessionActive.value) {
        stopProximityWatch();
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (position) => applyProximityReading(position),
        (geoErr) => {
          if (geoErr.code === geoErr.PERMISSION_DENIED) {
            followDistance.value = false;
            stopProximityWatch();
            locationStatus.value = "error";
            error.value = geoErrorMessage(geoErr);
          }
        },
        buildGeoOptions(false),
      );
    }, 5_000) as unknown as number;
    // Seed one reading immediately.
    navigator.geolocation.getCurrentPosition(
      (position) => applyProximityReading(position),
      () => {},
      buildGeoOptions(false),
    );
    return;
  }

  proximityWatchId = navigator.geolocation.watchPosition(
    (position) => {
      if (!followDistance.value || !isSessionActive.value) {
        stopProximityWatch();
        return;
      }
      applyProximityReading(position);
    },
    (geoErr) => {
      if (geoErr.code === geoErr.PERMISSION_DENIED) {
        followDistance.value = false;
        stopProximityWatch();
        locationStatus.value = "error";
        error.value = geoErrorMessage(geoErr);
      }
    },
    {
      ...buildGeoOptions(true),
      maximumAge: 2_000,
    },
  );
}

function stopProximityWatch(): void {
  if (proximityWatchId !== null) {
    if (isSafariOrIOS()) {
      window.clearInterval(proximityWatchId);
    } else if (navigator.geolocation) {
      navigator.geolocation.clearWatch(proximityWatchId);
    }
    proximityWatchId = null;
  }
  proximityLastUiAt = 0;
}

function syncProximityWatch(): void {
  if (followDistance.value && isSessionActive.value && !document.hidden) {
    startProximityWatch();
  } else {
    stopProximityWatch();
  }
}

async function refreshDistance(): Promise<void> {
  if (isRefreshingDistance.value || locationStatus.value === "locating") {
    return;
  }

  isRefreshingDistance.value = true;
  // Pause continuous watch while we do a deliberate settle reading.
  stopProximityWatch();
  try {
    await requestLocation();
  } finally {
    isRefreshingDistance.value = false;
    syncProximityWatch();
  }
}

function onFollowDistanceToggle(): void {
  if (!followDistance.value) {
    stopProximityWatch();
    return;
  }
  // Kick one settle first so user gets a solid reading, then follow.
  void refreshDistance();
}

function onVisibilityChange(): void {
  if (document.hidden) {
    stopProximityWatch();
    return;
  }
  syncProximityWatch();
}

function cancelActiveGpsRequest(): void {
  stopGpsWatch();
  if (!activeGpsResolve) {
    return;
  }

  const resolve = activeGpsResolve;
  activeGpsResolve = null;
  gpsRequestId += 1;
  resolve(null);
}

function geoErrorMessage(geoErr: GeolocationPositionError): string {
  if (geoErr.code === geoErr.PERMISSION_DENIED) {
    if (isSafariOrIOS()) {
      return [
        "Safari masih menolak lokasi untuk situs ini (bukan hanya setting HP).",
        "Dengan tab situs terbuka: ketuk ikon aA (kiri address bar) → Website Settings → Location → Allow,",
        "lalu ketuk Aktifkan lokasi lagi.",
        "Pastikan juga Settings → Privacy & Security → Location Services → Safari Websites = While Using.",
      ].join(" ");
    }
    return "Izin lokasi ditolak. Izinkan akses lokasi pada browser lalu ketuk Aktifkan lokasi.";
  }
  if (geoErr.code === geoErr.POSITION_UNAVAILABLE) {
    return "Lokasi tidak tersedia. Pastikan GPS/lokasi perangkat aktif lalu coba lagi.";
  }
  if (geoErr.code === geoErr.TIMEOUT) {
    return "Pengambilan lokasi terlalu lama. Pastikan GPS/lokasi aktif, berada di area terbuka, lalu ketuk Aktifkan lokasi lagi.";
  }
  return "Gagal mendeteksi lokasi GPS. Pastikan GPS aktif dan berada di area terbuka.";
}

/**
 * Start GPS from a direct user tap. Must remain synchronous until geolocation
 * is invoked (Safari user-gesture / MDN secure-context requirement).
 */
function activateLocation(): void {
  if (locationStatus.value === "locating" || isRefreshingDistance.value) {
    return;
  }
  stopProximityWatch();
  // Invoke requestLocation in this same turn (no await before geolocation starts).
  void requestLocation().finally(() => {
    syncProximityWatch();
  });
}

async function requestLocation(): Promise<{
  latitude: number;
  longitude: number;
  accuracy?: number;
} | null> {
  const blocked = geolocationBlockReason();
  if (blocked) {
    error.value = blocked;
    locationStatus.value = "error";
    return null;
  }

  // End any previous settle immediately so retries cannot stack forever.
  cancelActiveGpsRequest();
  stopProximityWatch();

  const requestId = ++gpsRequestId;
  locationStatus.value = "locating";
  error.value = "";

  return new Promise((resolve) => {
    activeGpsResolve = resolve;

    let best: {
      latitude: number;
      longitude: number;
      accuracy: number;
    } | null = null;
    let denied = false;

    const isCurrent = () =>
      requestId === gpsRequestId && activeGpsResolve === resolve;

    const finish = (
      result: {
        latitude: number;
        longitude: number;
        accuracy?: number;
      } | null,
      status: "ready" | "error",
      messageText?: string,
    ) => {
      if (!isCurrent()) return;

      stopGpsWatch();
      activeGpsResolve = null;
      locationStatus.value = status;

      if (result) {
        locationAccuracy.value = Math.round(result.accuracy ?? 0);
        currentMapLocation.value = {
          latitude: result.latitude,
          longitude: result.longitude,
          accuracy: result.accuracy,
        };
        updateCartoMap();
      }

      if (messageText) {
        error.value = messageText;
      } else if (status === "ready") {
        error.value = "";
      }

      resolve(result);
    };

    const acceptReading = (position: GeolocationPosition): boolean => {
      if (!isCurrent()) return false;

      const accuracy = Number(position.coords.accuracy);
      if (!Number.isFinite(accuracy) || accuracy < 0) {
        return false;
      }

      const reading = {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy,
      };

      if (!best || reading.accuracy < best.accuracy) {
        best = reading;
        locationAccuracy.value = Math.round(reading.accuracy);
        currentMapLocation.value = {
          latitude: reading.latitude,
          longitude: reading.longitude,
          accuracy: reading.accuracy,
        };
        updateCartoMap();
      }

      if (best.accuracy <= maxGpsAccuracyMeters) {
        finish(best, "ready");
        return true;
      }

      return false;
    };

    const completeWithBestOrError = (fallbackMessage: string) => {
      if (!isCurrent()) return;

      if (best) {
        const accuracyNote =
          best.accuracy > maxGpsAccuracyMeters
            ? `Akurasi GPS ±${Math.round(best.accuracy)} m masih kurang. Pindah ke area terbuka lalu tekan Aktifkan lokasi (maksimal ±${maxGpsAccuracyMeters} m).`
            : undefined;
        finish(best, "ready", accuracyNote);
        return;
      }

      finish(null, "error", fallbackMessage);
    };

    const startWatchImprove = (highAccuracy: boolean) => {
      if (!isCurrent() || gpsWatchId !== null) return;

      gpsWatchId = navigator.geolocation.watchPosition(
        (position) => {
          acceptReading(position);
        },
        (geoErr) => {
          if (!isCurrent()) return;
          if (geoErr.code === geoErr.PERMISSION_DENIED) {
            finish(null, "error", geoErrorMessage(geoErr));
          }
        },
        buildGeoOptions(highAccuracy),
      );
    };

    /**
     * Safari/iOS WebKit: prefer repeated getCurrentPosition only.
     * Mixing watchPosition often yields PERMISSION_DENIED / stuck fixes
     * even when Settings already allow location (MDN + WebKit reports).
     */
    const startSafariPoll = () => {
      if (!isCurrent() || safariPollTimer !== null) return;

      let highAccuracy = false;

      const pollOnce = () => {
        if (!isCurrent() || denied) return;

        navigator.geolocation.getCurrentPosition(
          (position) => {
            if (!isCurrent()) return;
            const done = acceptReading(position);
            // After a coarse fix, tighten accuracy on subsequent polls.
            if (!done && !highAccuracy) {
              highAccuracy = true;
            }
          },
          (geoErr) => {
            if (!isCurrent()) return;
            if (geoErr.code === geoErr.PERMISSION_DENIED) {
              denied = true;
              finish(null, "error", geoErrorMessage(geoErr));
              return;
            }
            // TIMEOUT / UNAVAILABLE: keep polling until settle timeout.
            if (!highAccuracy) {
              highAccuracy = true;
            }
          },
          buildGeoOptions(highAccuracy),
        );
      };

      // First call MUST be sync in the user-gesture turn.
      pollOnce();
      safariPollTimer = window.setInterval(pollOnce, GPS_SAFARI_POLL_MS);
    };

    gpsSettleTimer = window.setTimeout(() => {
      completeWithBestOrError(
        denied
          ? geoErrorMessage({
              code: 1,
              PERMISSION_DENIED: 1,
              POSITION_UNAVAILABLE: 2,
              TIMEOUT: 3,
              message: "User denied Geolocation",
            } as GeolocationPositionError)
          : "GPS belum mendapatkan sinyal akurat. Pastikan lokasi aktif, berada di area terbuka, lalu ketuk Aktifkan lokasi lagi.",
      );
    }, GPS_SETTLE_TIMEOUT_MS);

    if (isSafariOrIOS()) {
      startSafariPoll();
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (position) => {
        if (!isCurrent()) return;
        const done = acceptReading(position);
        if (!done) {
          startWatchImprove(true);
        }
      },
      (geoErr) => {
        if (!isCurrent()) return;
        if (geoErr.code === geoErr.PERMISSION_DENIED) {
          finish(null, "error", geoErrorMessage(geoErr));
          return;
        }
        startWatchImprove(true);
      },
      buildGeoOptions(true),
    );
  });
}

async function refreshMapLocation(): Promise<void> {
  if (locationStatus.value === "locating" || isRefreshingDistance.value) {
    return;
  }

  // Keep this path tap-driven: start GPS in the same user-gesture turn.
  stopProximityWatch();
  try {
    await requestLocation();
  } finally {
    syncProximityWatch();
  }
}

async function loadCities(): Promise<void> {
  isLoading.value = true;
  error.value = "";
  try {
    cities.value = await fetchOutsourceCities();
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      error.value = err.message;
    } else {
      error.value = "Gagal memuat daftar kota. Silakan coba lagi.";
    }
  } finally {
    isLoading.value = false;
  }
}

function invalidateSessionState(): void {
  cancelActiveGpsRequest();
  stopProximityWatch();
  followDistance.value = false;
  isRefreshingDistance.value = false;
  hasServerSession.value = false;
  expiresAt.value = null;
  attendanceId.value = null;
  attendanceStatus.value = null;
  attendanceDate.value = null;
  checkInAt.value = null;
  checkOutAt.value = null;
  durationMinutes.value = null;
  locationAccuracy.value = null;
  locationStatus.value = "idle";
  error.value = "";
  message.value = "";
}

async function onCitySelected(): Promise<void> {
  if (selectedCity.value === null) return;

  invalidateSessionState();
  isLoading.value = true;
  stores.value = [];
  selectedStore.value = null;
  selectedStoreName.value = "";
  outsources.value = [];
  selectedOutsource.value = null;

  try {
    stores.value = await fetchOutsourceStores(selectedCity.value);
    step.value = "store";
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      error.value = err.message;
    } else {
      error.value = "Gagal memuat daftar cabang. Silakan coba lagi.";
    }
  } finally {
    isLoading.value = false;
  }
}

async function onStoreSelected(): Promise<void> {
  if (selectedStore.value === null) return;

  invalidateSessionState();
  currentMapLocation.value = null;

  const foundStore = Array.isArray(stores.value)
    ? stores.value.find((s) => s.id === selectedStore.value)
    : undefined;
  if (foundStore) {
    selectedStoreName.value = foundStore.name;
  }

  isLoading.value = true;
  outsources.value = [];
  selectedOutsource.value = null;

  try {
    outsources.value = await fetchOutsourceOutsources(selectedStore.value);
    step.value = "outsource";
    await nextTick();
    if (!selectedStoreLocation.value) {
      mapError.value = isUsingCityFallback.value
        ? "Menampilkan perkiraan pusat kota. Ini bukan lokasi pin; pilih pin point untuk validasi radius."
        : "Lokasi cabang/pin dan koordinat kota belum tersedia. Silakan hubungi admin.";
    } else {
      mapError.value = "";
    }

    // Map only — GPS starts after the employee profile is selected
    // so the user is not asked for location twice on this step.
    if (selectedMapLocation.value) {
      await ensureCartoMapReady();
    }
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      error.value = err.message;
    } else {
      error.value =
        "Gagal memuat daftar personel outsource. Silakan coba lagi.";
    }
  } finally {
    isLoading.value = false;
  }
}

function goToStep(target: Step): void {
  step.value = target;

  if (target === "city") {
    cancelActiveGpsRequest();
    stopProximityWatch();
    followDistance.value = false;
    isRefreshingDistance.value = false;
    locationAccuracy.value = null;
    locationStatus.value = "idle";
    currentMapLocation.value = null;
    selectedStore.value = null;
    selectedStoreName.value = "";
    stores.value = [];
    selectedOutsource.value = null;
    outsources.value = [];
    hasServerSession.value = false;
    expiresAt.value = null;
    attendanceId.value = null;
    attendanceStatus.value = null;
    attendanceDate.value = null;
    checkInAt.value = null;
    checkOutAt.value = null;
    durationMinutes.value = null;
    mapError.value = "";
  }

  if (target === "store") {
    cancelActiveGpsRequest();
    stopProximityWatch();
    followDistance.value = false;
    isRefreshingDistance.value = false;
    locationAccuracy.value = null;
    locationStatus.value = "idle";
    currentMapLocation.value = null;
    selectedOutsource.value = null;
    outsources.value = [];
    hasServerSession.value = false;
    expiresAt.value = null;
    attendanceId.value = null;
    attendanceStatus.value = null;
    attendanceDate.value = null;
    checkInAt.value = null;
    checkOutAt.value = null;
    durationMinutes.value = null;
    mapError.value = "";
  }
}

async function onOutsourceSelected(): Promise<void> {
  invalidateSessionState();

  if (!selectedOutsource.value) {
    return;
  }

  step.value = "outsource";

  // Do NOT auto-request GPS here. Safari/iOS requires a direct user tap
  // (Aktifkan lokasi) — select/change events are not a reliable gesture.
  error.value = "";
  locationStatus.value = "idle";
  locationAccuracy.value = null;
  currentMapLocation.value = null;
}

async function submitLogin(): Promise<void> {
  const pin = loginPassword.value.replace(/\D/g, "").slice(0, 8);
  loginPassword.value = pin;
  if (!loginCode.value.trim() || !pin) {
    error.value = "Masukkan kode outsource dan PIN.";
    return;
  }
  if (!/^\d{4,8}$/.test(pin)) {
    error.value = "PIN harus 4–8 digit angka.";
    return;
  }

  isSubmitting.value = true;
  error.value = "";
  message.value = "";

  try {
    const response = await loginOutsourceSession(
      loginCode.value.trim(),
      pin,
    );
    hasServerSession.value = true;
    expiresAt.value = response.data.expires_at;
    applySessionPayload(response.data);
    // Disabled: riwayat presensi
    // await loadAttendanceHistory();
    message.value =
      response.data.status === "ACTIVE"
        ? `Selamat datang kembali, ${response.data.outsource?.name ?? ""}. Lanjutkan clock-out bila sudah selesai.`
        : `Selamat datang, ${response.data.outsource?.name ?? ""}. Pilih Clock In untuk mulai.`;
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      if (err.status === 409) {
        error.value =
          err.message ||
          "Perangkat ini masih dipakai absensi personel lain. Clock-out dulu sebelum ganti orang.";
      } else if (err.status === 422) {
        error.value = err.message || "Kode atau password tidak valid.";
      } else if (err.status === 429) {
        error.value = "Terlalu banyak percobaan login. Mohon tunggu sebentar.";
      } else {
        error.value = err.message ?? "Gagal masuk.";
      }
    } else {
      error.value = "Koneksi bermasalah. Periksa jaringan internet Anda.";
    }
  } finally {
    isSubmitting.value = false;
  }
}

function startClockInFromGreet(): void {
  void enterAttendanceStep("session");
}

function startClockOutFromGreet(): void {
  void enterAttendanceStep("attendance_open");
}

async function enterAttendanceStep(
  target: "session" | "attendance_open",
): Promise<void> {
  error.value = "";
  step.value = target;
  await nextTick();
  if (selectedMapLocation.value) {
    await ensureCartoMapReady();
  }
  if (locationStatus.value === "idle" || locationStatus.value === "error") {
    activateLocation();
  }
}

async function startSession(): Promise<void> {
  if (!selectedStoreLocation.value) {
    error.value =
      "Koordinat pin belum tersedia. Presensi tidak dapat dilanjutkan sebelum admin mengatur pin cabang.";
    return;
  }

  if (locationStatus.value !== "ready") {
    error.value =
      "Lokasi wajib diaktifkan untuk presensi. Ketuk Aktifkan lokasi, izinkan akses, lalu lanjutkan.";
    return;
  }

  if (!isGpsAccuracyAcceptable.value) {
    error.value = `Akurasi GPS ±${locationAccuracy.value ?? 0} m terlalu rendah. Tunggu sinyal GPS lebih akurat (maksimal ±${maxGpsAccuracyMeters} m).`;
    return;
  }

  if (
    selectedCity.value === null ||
    selectedStore.value === null ||
    selectedOutsource.value === null
  ) {
    error.value = "Harap lengkapi semua pilihan data penugasan.";
    return;
  }

  isSubmitting.value = true;
  error.value = "";
  message.value = "";

  try {
    const response = await initOutsourceSession(
      selectedCity.value,
      selectedStore.value,
      selectedOutsource.value.id,
    );

    hasServerSession.value = true;
    expiresAt.value = response.data.expires_at;
    applySessionPayload(response.data);
    message.value =
      response.data.status === "ACTIVE"
        ? `Sesi aktif dipulihkan untuk ${response.data.outsource?.name ?? "personel"}. Lanjutkan clock-out bila sudah selesai.`
        : `Sesi individu aktif untuk ${response.data.outsource?.name ?? "personel"}.`;
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      if (err.status === 409) {
        error.value =
          err.message ||
          "Perangkat ini masih dipakai absensi personel lain. Clock-out dulu sebelum ganti orang.";
      } else if (err.status === 422) {
        error.value =
          "Pilihan penugasan tidak valid atau tidak aktif. Silakan pilih kembali.";
      } else if (err.status === 429) {
        error.value =
          "Terlalu banyak permintaan sesi. Mohon tunggu beberapa saat.";
      } else if (err.status === 503) {
        error.value =
          "Layanan sesi sedang tidak tersedia. Silakan coba beberapa saat lagi.";
      } else {
        error.value = err.message ?? "Gagal memulai sesi presensi.";
      }
    } else {
      error.value = "Koneksi bermasalah. Periksa jaringan internet Anda.";
    }
  } finally {
    isSubmitting.value = false;
  }
}

function applySessionPayload(payload: OutsourceSessionPayload): void {
  if (!payload.outsource || !payload.store) {
    return;
  }

  selectedOutsource.value = payload.outsource;
  selectedStore.value = payload.store.id;
  selectedStoreName.value = payload.store.name;
  if (payload.store.city_id != null) {
    selectedCity.value = payload.store.city_id;
  }

  selectedCityName.value =
    payload.city?.name?.trim() ||
    payload.store.city_name?.trim() ||
    cities.value.find((item) => item.id === payload.store?.city_id)?.name ||
    "";

  // Hydrate store coords so map/distance work after login (wizard stores list skipped).
  const hydratedStore: Store = {
    id: payload.store.id,
    name: payload.store.name,
    city_id: payload.store.city_id ?? 0,
    latitude: payload.store.latitude ?? null,
    longitude: payload.store.longitude ?? null,
  };
  const storeIndex = stores.value.findIndex((item) => item.id === hydratedStore.id);
  if (storeIndex >= 0) {
    stores.value[storeIndex] = {
      ...stores.value[storeIndex],
      ...hydratedStore,
      latitude: hydratedStore.latitude ?? stores.value[storeIndex].latitude,
      longitude: hydratedStore.longitude ?? stores.value[storeIndex].longitude,
    };
  } else {
    stores.value = [hydratedStore];
  }

  allowedPins.value = Array.isArray(payload.pins) ? payload.pins : [];
  if (allowedPins.value.length === 1) {
    selectedPinId.value = allowedPins.value[0].id;
  } else if (
    selectedPinId.value !== null &&
    !allowedPins.value.some((pin) => pin.id === selectedPinId.value)
  ) {
    selectedPinId.value = null;
  }

  hasServerSession.value = true;
  expiresAt.value = payload.expires_at;

  const attendance = payload.attendance;
  if (payload.status === "ACTIVE" && attendance?.check_in_at) {
    attendanceId.value = attendance.attendance_id;
    attendanceStatus.value = attendance.status;
    attendanceDate.value = attendance.attendance_date;
    checkInAt.value = attendance.check_in_at;
    checkOutAt.value = attendance.check_out_at;
    durationMinutes.value = attendance.duration_minutes;
    step.value = "greet";
    return;
  }

  attendanceId.value = null;
  attendanceStatus.value = null;
  attendanceDate.value = null;
  checkInAt.value = null;
  checkOutAt.value = null;
  durationMinutes.value = null;
  step.value = "greet";
}

async function loadAttendanceHistory(): Promise<void> {
  // Feature disabled — keep implementation for later re-enable.
  if (!ENABLE_OUTSOURCE_ATTENDANCE_HISTORY) {
    attendanceHistory.value = [];
    isLoadingHistory.value = false;
    return;
  }

  if (!hasServerSession.value) {
    attendanceHistory.value = [];
    return;
  }

  isLoadingHistory.value = true;
  try {
    attendanceHistory.value = await fetchOutsourceAttendanceHistory(14);
  } catch {
    attendanceHistory.value = [];
  } finally {
    isLoadingHistory.value = false;
  }
}

async function restoreSessionFromServer(): Promise<void> {
  try {
    const response = await fetchOutsourceSessionCurrent();
    if (!response?.data || response.data.status === "NONE") {
      return;
    }

    applySessionPayload(response.data);

    if (selectedCity.value !== null) {
      try {
        stores.value = await fetchOutsourceStores(selectedCity.value);
      } catch {
        // Selection restore is enough for active session UI.
      }
    }

    if (selectedStore.value !== null) {
      try {
        outsources.value = await fetchOutsourceOutsources(selectedStore.value);
        const matched = outsources.value.find(
          (item) => item.id === selectedOutsource.value?.id,
        );
        if (matched) {
          selectedOutsource.value = matched;
        }
      } catch {
        // Keep payload outsource identity if list fetch fails.
      }
    }

    if (selectedMapLocation.value) {
      await nextTick();
      await ensureCartoMapReady();
      // Safari: wait for an explicit "Aktifkan lokasi" tap — do not auto-request.
      locationStatus.value = "idle";
    }

    // Disabled: riwayat presensi
    // await loadAttendanceHistory();

    message.value =
      response.data.status === "ACTIVE"
        ? `Sesi clock-in dipulihkan untuk ${response.data.outsource?.name ?? "personel"}.`
        : `Sesi siap presensi dipulihkan untuk ${response.data.outsource?.name ?? "personel"}.`;
  } catch {
    // No cookie / network — stay on wizard.
  }
}

function isValidCoordinate(latitude: number, longitude: number): boolean {
  return (
    Number.isFinite(latitude) &&
    Number.isFinite(longitude) &&
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180
  );
}

async function submitAttendance(): Promise<void> {
  if (!hasServerSession.value) {
    error.value = "Sesi telah kedaluwarsa. Silakan login kembali.";
    step.value = "login";
    return;
  }

  if (resolveSelectedPinId() === null) {
    error.value = "Pilih pin point / alamat absensi terlebih dahulu.";
    return;
  }

  if (isSubmitting.value) {
    return;
  }

  isSubmitting.value = true;
  error.value = "";
  message.value = "";

  let locationData: {
    latitude: number;
    longitude: number;
    accuracy?: number;
  } | null = null;

  try {
    locationData = await requestLocation();
    if (!locationData) {
      return;
    }

    if (!isValidCoordinate(locationData.latitude, locationData.longitude)) {
      error.value =
        "Koordinat lokasi tidak valid. Pastikan GPS memberikan koordinat yang benar.";
      locationStatus.value = "error";
      return;
    }

    if (!isGpsAccuracyAcceptable.value) {
      error.value = `Akurasi GPS ±${locationAccuracy.value ?? 0} m terlalu rendah. Presensi membutuhkan akurasi maksimal ±${maxGpsAccuracyMeters} m.`;
      return;
    }

    const pinId = resolveSelectedPinId();
    if (pinId === null) {
      error.value = "Pilih pin point / alamat absensi terlebih dahulu.";
      return;
    }
    const response: OutsourceAttendanceResponse = await (isAttendanceOpen.value
      ? outsourceCheckOut(
          locationData.latitude,
          locationData.longitude,
          pinId,
          locationData.accuracy,
        )
      : outsourceCheckIn(
          locationData.latitude,
          locationData.longitude,
          pinId,
          locationData.accuracy,
        ));

    if (response.success) {
      attendanceId.value = response.data.attendance_id;
      attendanceStatus.value = response.data.status;
      attendanceDate.value = response.data.attendance_date;
      checkInAt.value = response.data.check_in_at;
      checkOutAt.value = response.data.check_out_at;
      durationMinutes.value = response.data.duration_minutes;

      if (isAttendanceOpen.value) {
        step.value = "completed";
        message.value =
          "Presensi Clock-Out berhasil dicatat. Tugas hari ini selesai!";
        hasServerSession.value = false;
        expiresAt.value = null;
      } else {
        step.value = "attendance_open";
        message.value = "Presensi Clock-In berhasil dicatat! Selamat bertugas.";
      }
    }
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      switch (err.status) {
        case 401:
          error.value =
            "Sesi presensi telah berakhir. Silakan pilih kembali penugasan Anda.";
          if (step.value !== "completed") {
            resetSelection();
          }
          break;
        case 403:
          error.value = "Permintaan tidak diizinkan.";
          break;
        case 404:
          error.value =
            "Data yang dipilih tidak ditemukan atau sudah tidak tersedia.";
          break;
        case 409:
          error.value =
            "Status absensi sudah berubah. Silakan periksa kembali.";
          break;
        case 422:
          error.value =
            err.message ??
            "Presensi gagal. Pastikan Anda berada di dalam radius pin yang dipilih.";
          break;
        case 429:
          error.value = "Terlalu banyak percobaan. Harap tunggu beberapa saat.";
          break;
        default:
          error.value = "Terjadi gangguan pada server. Silakan coba lagi.";
      }
    } else {
      error.value = "Koneksi bermasalah. Periksa internet Anda lalu coba lagi.";
    }
  } finally {
    isSubmitting.value = false;
    syncProximityWatch();
  }
}

function resetSelection(): void {
  invalidateSessionState();
  currentMapLocation.value = null;
  mapError.value = "";
  destroyCartoMap();
  selectedCity.value = null;
  selectedStore.value = null;
  selectedStoreName.value = "";
  selectedCityName.value = "";
  selectedOutsource.value = null;
  attendanceHistory.value = [];
  stores.value = [];
  outsources.value = [];
  step.value = "city";
  loadCities();
}

onMounted(() => {
  clockTimer = window.setInterval(() => {
    now.value = new Date();
  }, 1000);

  document.addEventListener("visibilitychange", onVisibilityChange);
  void (async () => {
    await loadCities();
    await restoreSessionFromServer();
  })();
});

watch(isSessionActive, (active) => {
  if (!active) {
    followDistance.value = false;
    stopProximityWatch();
  }
});

watch(selectedPinId, async (nextId, prevId) => {
  if (nextId === prevId) return;
  await onPinSelected();
});

watch(showStoreMap, async (visible) => {
  if (!visible || !selectedMapLocation.value) return;
  await nextTick();
  await ensureCartoMapReady({ recenterOnTarget: true });
});

onUnmounted(() => {
  if (clockTimer !== null) {
    clearInterval(clockTimer);
  }
  document.removeEventListener("visibilitychange", onVisibilityChange);
  cancelActiveGpsRequest();
  stopProximityWatch();
  destroyCartoMap();
});
</script>

<template>
  <main class="outsource-app" :class="{ 'outsource-app--login': step === 'login' }">
    <template v-if="step === 'login'">
      <section class="login-visual" aria-label="MITO Outsource App">
        <div class="visual-content">
          <div class="logo-frame">
            <img class="brand-logo" src="/images/mito.png" alt="MITO electronic" />
          </div>
          <p class="visual-kicker">OUTSOURCE APP</p>
          <h1>Assigned sites,<br /><strong>one login.</strong></h1>
          <p class="visual-copy">
            Sign in with your outsource code, choose an allowed pin, then clock in or out with GPS.
          </p>
        </div>
      </section>

      <section class="login-panel">
        <div class="login-heading">
          <p class="eyebrow">OUTSOURCE ACCESS</p>
          <h2>Sign in to outsource app</h2>
          <p class="login-intro">Use your outsource code and numeric PIN to continue.</p>
        </div>

        <form novalidate @submit.prevent="submitLogin">
          <label class="field-label">
            <span>Outsource code</span>
            <span class="field-control">
              <AppIcon name="UserRound" class-name="field-icon" :size="16" :stroke-width="2" aria-hidden="true" />
              <input
                v-model="loginCode"
                type="text"
                name="outsource-code"
                autocomplete="username"
                placeholder="Contoh: 001"
                :disabled="isSubmitting"
              />
            </span>
          </label>

          <label class="field-label">
            <span>PIN</span>
            <span class="field-control">
              <AppIcon name="LockKeyhole" class-name="field-icon" :size="16" :stroke-width="2" aria-hidden="true" />
              <input
                v-model="loginPassword"
                :type="showLoginPassword ? 'text' : 'password'"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="8"
                name="password"
                autocomplete="current-password"
                placeholder="6-digit PIN"
                :disabled="isSubmitting"
                @input="onLoginPinInput"
              />
              <button
                type="button"
                class="password-toggle"
                :aria-label="showLoginPassword ? 'Hide PIN' : 'Show PIN'"
                @click="showLoginPassword = !showLoginPassword"
              >
                <AppIcon v-if="!showLoginPassword" name="Eye" :size="16" :stroke-width="2" />
                <AppIcon v-else name="EyeOff" :size="16" :stroke-width="2" />
              </button>
            </span>
          </label>

          <p v-if="error" class="error error-banner" role="alert">{{ error }}</p>

          <p v-if="message" class="success success-banner" role="status">{{ message }}</p>

          <AppButton type="submit" :disabled="isSubmitting" icon="ArrowRight">
            {{ isSubmitting ? "Signing in…" : "Sign in" }}
          </AppButton>
        </form>

        <p class="login-note">
          <AppIcon name="ShieldCheck" class-name="secure-mark" :size="14" :stroke-width="2.5" />
          Your connection is protected and secure.
        </p>
      </section>
    </template>

    <template v-else>
    <header class="app-header">
      <div class="brand-lockup">
        <img class="brand-logo" src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p class="brand-eyebrow">MITO GROUP</p>
          <strong>Outsource Attendance</strong>
        </div>
      </div>

      <div class="header-meta">
        <div
          v-if="isSessionActive && selectedOutsource"
          class="user-badge"
          :title="selectedOutsource.name"
        >
          <span>{{ selectedOutsource.name.charAt(0).toUpperCase() }}</span>
        </div>
        <div v-else class="kiosk-badge" title="Sesi Individu">
          <AppIcon
            name="Building2"
            class="badge-icon"
            :size="18"
            :stroke-width="2"
            aria-hidden="true"
          />
        </div>
      </div>
    </header>

    <section class="welcome-block">
      <div class="date-row">
        <p class="welcome-kicker">{{ formatCurrentDate() }}</p>
        <span class="clock-live">{{ formatCurrentTime() }}</span>
      </div>
      <h1 v-if="step === 'greet' && selectedOutsource">
        Halo, {{ greetFirstName }}
      </h1>
      <h1 v-else-if="isSessionActive && selectedOutsource">
        Hi, {{ selectedOutsource.name.split(" ")[0] }}
      </h1>
      <h1 v-else>Presensi Outsource</h1>
      <p v-if="step === 'greet'">
        <template v-if="checkInAt">
          Sesi masih terbuka. Lanjutkan clock out di pin point yang sesuai bila sudah selesai.
        </template>
        <template v-else>
          Pilih aksi di bawah untuk melanjutkan. Pin point dipilih saat clock in.
        </template>
      </p>
      <p v-else-if="isSessionActive">
        Sesi individu aktif di
        <strong>{{ selectedStoreName || "Cabang Penugasan" }}</strong
        >.
      </p>
      <p v-else>
        Pilih penugasan individu Anda dan validasi lokasi kerja Anda secara
        otomatis.
      </p>
    </section>

    <Teleport to="body">
      <div
        v-if="error || message"
        class="outsource-toast-stack"
        aria-live="polite"
      >
        <section
          v-if="error"
          class="outsource-toast outsource-toast--error"
          role="alert"
        >
          <div class="outsource-toast__content">
            <AppIcon
              name="TriangleAlert"
              class="outsource-toast__icon"
              :size="18"
              :stroke-width="2.2"
              aria-hidden="true"
            />
            <p>{{ error }}</p>
          </div>
          <button
            type="button"
            class="outsource-toast__dismiss"
            aria-label="Tutup peringatan"
            @click="error = ''"
          >
            <AppIcon
              name="X"
              :size="14"
              :stroke-width="2.5"
              aria-hidden="true"
            />
          </button>
        </section>

        <section
          v-if="message"
          class="outsource-toast outsource-toast--success"
          role="status"
        >
          <div class="outsource-toast__content">
            <AppIcon
              name="CircleCheckBig"
              class="outsource-toast__icon"
              :size="18"
              :stroke-width="2.2"
              aria-hidden="true"
            />
            <p>{{ message }}</p>
          </div>
          <button
            type="button"
            class="outsource-toast__dismiss"
            aria-label="Tutup pesan"
            @click="message = ''"
          >
            <AppIcon
              name="X"
              :size="14"
              :stroke-width="2.5"
              aria-hidden="true"
            />
          </button>
        </section>
      </div>
    </Teleport>

    <template v-if="step === 'greet' && selectedOutsource">
      <section class="greet-card" aria-label="Ringkasan penugasan">
        <div class="greet-card__top">
          <div class="greet-card__identity">
            <div class="greet-card__avatar" aria-hidden="true">
              {{ selectedOutsource.name.charAt(0).toUpperCase() }}
            </div>
            <div class="greet-card__who">
              <p class="greet-card__eyebrow">
                {{ checkInAt ? "Sesi masih terbuka" : "Akun outsource" }}
              </p>
              <h2>{{ selectedOutsource.name }}</h2>
              <p class="greet-card__code">
                Kode
                <strong>{{ selectedOutsource.outsource_code || "—" }}</strong>
              </p>
            </div>
          </div>
          <span
            class="greet-card__status"
            :class="checkInAt ? 'greet-card__status--active' : 'greet-card__status--ready'"
          >
            {{ greetStatusLabel }}
          </span>
        </div>

        <div class="greet-card__facts">
          <div class="greet-fact">
            <span>Kota</span>
            <strong>{{ selectedCityName || "—" }}</strong>
          </div>
          <div class="greet-fact">
            <span>Cabang</span>
            <strong>{{ selectedStoreName || "—" }}</strong>
          </div>
          <div class="greet-fact">
            <span>{{ allowedPins.length > 1 ? "Pin tersedia" : "Pin point" }}</span>
            <strong>{{ greetAllowedPinsLabel }}</strong>
          </div>
          <div v-if="checkInAt" class="greet-fact">
            <span>Clock in</span>
            <strong>{{ formatTime(checkInAt) }}</strong>
          </div>
        </div>

        <div class="greet-card__pins">
          <div class="greet-card__pins-head">
            <span>{{ greetPinsSectionTitle }}</span>
          </div>

          <ul v-if="allowedPins.length" class="greet-pin-list">
            <li v-for="pin in allowedPins" :key="pin.id">
              <span class="greet-pin-list__icon" aria-hidden="true">
                <AppIcon name="MapPinned" :size="15" :stroke-width="2.2" />
              </span>
              <div>
                <strong>{{ pin.name }}</strong>
                <small v-if="pin.address">{{ pin.address }}</small>
              </div>
            </li>
          </ul>
          <p v-else class="greet-card__empty">
            Belum ada subset pin khusus — semua pin aktif di cabang dapat dipilih saat absensi.
          </p>
          <p v-if="allowedPins.length > 0" class="greet-card__pins-note">
            {{ greetPinsSectionHint }}
          </p>
        </div>

        <div class="greet-card__action">
          <p class="greet-card__hint">{{ greetHint }}</p>

          <AppButton
            v-if="!checkInAt"
            type="button"
            class="hero-action-button greet-card__cta"
            variant="primary"
            icon="ArrowRight"
            @click="startClockInFromGreet"
          >
            Lanjut Clock In
          </AppButton>
          <AppButton
            v-else
            type="button"
            class="hero-action-button greet-card__cta"
            variant="primary"
            icon="LogOut"
            @click="startClockOutFromGreet"
          >
            Lanjut Clock Out
          </AppButton>
        </div>
      </section>

      <!-- Disabled: riwayat presensi (set ENABLE_OUTSOURCE_ATTENDANCE_HISTORY = true to show) -->
      <section
        v-if="ENABLE_OUTSOURCE_ATTENDANCE_HISTORY"
        class="history-card"
        aria-label="Riwayat presensi"
      >
        <div class="history-card__head">
          <div>
            <h2>Riwayat presensi</h2>
            <p>14 hari terakhir untuk akun ini</p>
          </div>
          <button
            type="button"
            class="history-card__refresh"
            :disabled="isLoadingHistory"
            @click="loadAttendanceHistory"
          >
            <AppIcon name="RefreshCw" :size="14" :stroke-width="2.3" />
            {{ isLoadingHistory ? "Memuat…" : "Muat ulang" }}
          </button>
        </div>

        <p v-if="isLoadingHistory && attendanceHistory.length === 0" class="history-card__empty">
          Memuat riwayat…
        </p>
        <p v-else-if="attendanceHistory.length === 0" class="history-card__empty">
          Belum ada riwayat presensi.
        </p>
        <ul v-else class="history-list">
          <li v-for="item in attendanceHistory" :key="item.attendance_id">
            <div class="history-list__main">
              <strong>{{ formatDate(item.attendance_date) }}</strong>
              <span
                class="history-list__status"
                :class="{
                  'history-list__status--ok':
                    item.status === 'present' || item.status === 'completed',
                  'history-list__status--open':
                    item.status === 'incomplete' || !item.check_out_at,
                }"
              >
                {{ formatHistoryStatus(item.status) }}
              </span>
            </div>
            <div class="history-list__meta">
              <span>In {{ formatTime(item.check_in_at) }}</span>
              <span>Out {{ formatTime(item.check_out_at) }}</span>
              <span>{{ formatHistoryDuration(item.duration_minutes) }}</span>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <template v-else-if="!isSessionActive && step !== 'completed' && step !== 'greet'">
      <div class="wizard-stepper" aria-label="Langkah Inisiasi Sesi">
        <div
          class="step-item"
          :class="{
            active: step === 'city',
            done: step === 'store' || step === 'outsource',
          }"
        >
          <span class="step-num">1</span>
          <span class="step-text">Kota</span>
        </div>
        <div class="step-divider" />
        <div
          class="step-item"
          :class="{ active: step === 'store', done: step === 'outsource' }"
        >
          <span class="step-num">2</span>
          <span class="step-text">Cabang</span>
        </div>
        <div class="step-divider" />
        <div class="step-item" :class="{ active: step === 'outsource' }">
          <span class="step-num">3</span>
          <span class="step-text">Profil</span>
        </div>
      </div>

      <section v-if="step === 'city'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="MapPinned" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Pilih Kota Penempatan</h2>
            <p class="card-sub">
              Tentukan wilayah operasional kerja Anda hari ini.
            </p>
          </div>
        </div>

        <div class="field-block">
          <label for="city-select">Wilayah Kota</label>
          <div class="select-wrapper">
            <select
              id="city-select"
              v-model="selectedCity"
              :disabled="isLoading"
              @change="onCitySelected"
            >
              <option :value="null" disabled>
                -- Pilih kota penempatan --
              </option>
              <option v-for="city in cities" :key="city.id" :value="city.id">
                {{ formatSelectLabel(city.name, city.code) }}
              </option>
            </select>
            <AppIcon
              name="ChevronDown"
              class="select-chevron"
              :size="16"
              :stroke-width="2.3"
              aria-hidden="true"
            />
          </div>
        </div>

        <AppButton
          type="button"
          class="btn-primary"
          variant="primary"
          icon="ArrowRight"
          :disabled="selectedCity === null || isLoading"
          @click="onCitySelected"
        >
          {{ isLoading ? "Memuat Cabang..." : "Lanjutkan ke Pilih Cabang" }}
        </AppButton>
      </section>

      <section v-if="step === 'store'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="Building2" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Pilih Cabang / Lokasi Kerja</h2>
            <p class="card-sub">
              Pilih cabang tempat Anda bertugas di kota yang dipilih.
            </p>
          </div>
        </div>

        <div class="field-block">
          <label for="store-select">Cabang</label>
          <div class="select-wrapper">
            <select
              id="store-select"
              v-model="selectedStore"
              :disabled="isLoading"
              @change="onStoreSelected"
            >
              <option :value="null" disabled>-- Pilih cabang --</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">
                {{ store.name }}
              </option>
            </select>
            <AppIcon
              name="ChevronDown"
              class="select-chevron"
              :size="16"
              :stroke-width="2.3"
              aria-hidden="true"
            />
          </div>
        </div>

        <div class="button-group">
          <AppButton
            type="button"
            class="btn-secondary"
            variant="secondary"
            icon="ArrowLeft"
            icon-position="left"
            @click="goToStep('city')"
          >
            Kembali
          </AppButton>
          <AppButton
            type="button"
            class="btn-primary"
            variant="primary"
            icon="ArrowRight"
            :disabled="selectedStore === null || isLoading"
            @click="onStoreSelected"
          >
            {{ isLoading ? "Memuat Personel..." : "Lanjutkan ke Profil" }}
          </AppButton>
        </div>
      </section>

      <section v-if="step === 'outsource'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="UserRound" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Pilih Profil Personel</h2>
            <p class="card-sub">
              Pilih nama Anda yang terdaftar pada penugasan cabang ini.
            </p>
          </div>
        </div>

        <div class="profile-summary" v-if="outsources.length > 0">
          <span class="profile-summary__label">Profil tersedia</span>
          <strong>{{ outsources.length }} orang</strong>
        </div>

        <div class="field-block">
          <label for="outsource-select">Nama Personel Outsource</label>
          <div class="select-wrapper">
            <select
              id="outsource-select"
              v-model="selectedOutsource"
              :disabled="isLoading || outsources.length === 0"
              @change="onOutsourceSelected"
            >
              <option :value="null" disabled>-- Pilih nama Anda --</option>
              <option
                v-for="outsource in outsources"
                :key="outsource.id"
                :value="outsource"
              >
                {{
                  formatSelectLabel(outsource.name, outsource.outsource_code)
                }}
              </option>
            </select>
            <AppIcon
              name="ChevronDown"
              class="select-chevron"
              :size="16"
              :stroke-width="2.3"
              aria-hidden="true"
            />
          </div>
          <p v-if="outsources.length === 0 && !isLoading" class="hint-empty">
            Belum ada data pekerja outsource yang ditugaskan di cabang ini.
          </p>
        </div>

        <div
          class="location-requirement"
          :class="{
            'location-requirement--ready': isGpsAccuracyAcceptable,
            'location-requirement--error': locationStatus === 'error',
          }"
          role="status"
        >
          <AppIcon
            :name="locationStatus === 'ready' ? 'CircleCheckBig' : 'MapPinned'"
            :size="18"
            :stroke-width="2.2"
            aria-hidden="true"
          />
          <div>
            <strong>
              {{
                isGpsAccuracyAcceptable
                  ? "Lokasi aktif"
                  : locationStatus === "locating"
                    ? "Menstabilkan GPS..."
                    : locationStatus === "ready"
                      ? "GPS kurang akurat"
                      : locationStatus === "error"
                        ? "Lokasi wajib diaktifkan"
                        : selectedOutsource
                          ? "Lokasi wajib diaktifkan"
                          : "Menunggu pilihan nama"
              }}
            </strong>
            <span>
              {{
                isGpsAccuracyAcceptable
                  ? `Akurasi GPS ±${locationAccuracy ?? 0} m`
                  : locationStatus === "locating"
                    ? locationAccuracy !== null
                      ? `Saat ini ±${locationAccuracy} m — target maksimal ±${maxGpsAccuracyMeters} m`
                      : `Menunggu sinyal akurat (maksimal ±${maxGpsAccuracyMeters} m)`
                    : locationStatus === "ready"
                      ? `Akurasi ±${locationAccuracy ?? 0} m (maksimal ±${maxGpsAccuracyMeters} m). Radius pin: ${activeGeofenceRadiusLabel}.`
                      : locationStatus === "error"
                        ? "Ketuk Aktifkan lokasi setelah mengizinkan akses di browser/Safari."
                        : selectedOutsource
                          ? "Ketuk Aktifkan lokasi agar Safari/Chrome meminta izin GPS."
                          : "Pilih nama personel terlebih dahulu untuk mengaktifkan lokasi."
              }}
            </span>
          </div>
          <button
            v-if="selectedOutsource && !isGpsAccuracyAcceptable"
            type="button"
            class="location-retry-button"
            :disabled="locationStatus === 'locating'"
            @click="activateLocation"
          >
            {{
              locationStatus === "locating"
                ? "Menstabilkan..."
                : locationStatus === "ready"
                  ? "Perbaiki akurasi"
                  : "Aktifkan lokasi"
            }}
          </button>
        </div>

        <div class="button-group">
          <AppButton
            type="button"
            class="btn-secondary"
            variant="secondary"
            icon="ArrowLeft"
            icon-position="left"
            @click="goToStep('store')"
          >
            Kembali
          </AppButton>
          <AppButton
            type="button"
            class="btn-primary"
            variant="primary"
            icon="ArrowRight"
            :disabled="
              !selectedOutsource || isSubmitting || !isGpsAccuracyAcceptable
            "
            @click="startSession"
          >
            {{
              isSubmitting
                ? "Menginisiasi Sesi..."
                : !selectedOutsource
                  ? "Pilih Nama untuk Lanjut"
                  : isGpsAccuracyAcceptable
                    ? "Mulai Sesi Presensi"
                    : locationStatus === "locating"
                      ? "Menstabilkan GPS..."
                      : locationStatus === "ready"
                        ? "Tunggu GPS lebih akurat"
                        : "Aktifkan Lokasi untuk Lanjut"
            }}
          </AppButton>
        </div>
      </section>
    </template>

    <template v-if="isSessionActive">
      <section class="hero-attendance-card" aria-live="polite">
        <div class="card-header-line">
          <div>
            <p class="hero-kicker">STATUS HARI INI</p>
            <h2>{{ statusTitle }}</h2>
          </div>
          <span
            class="status-pulse-dot"
            :class="{ active: isAttendanceOpen }"
            :title="isAttendanceOpen ? 'Sesi Terbuka' : 'Sesi Aktif'"
          />
        </div>

        <div class="time-grid time-grid--three">
          <div class="time-item">
            <span>Check In</span>
            <strong>{{ formatTime(checkInAt) }}</strong>
          </div>
          <div class="time-item">
            <span>Durasi</span>
            <strong>{{ attendanceDurationLabel }}</strong>
          </div>
          <div class="time-item">
            <span>Check Out</span>
            <strong>{{ formatTime(checkOutAt) }}</strong>
          </div>
        </div>

        <div class="field-block hero-field">
          <label for="pin-select">Pin Point / Alamat</label>
          <div class="select-wrapper">
            <select
              id="pin-select"
              v-model="selectedPinId"
              :disabled="isSubmitting || allowedPins.length === 0"
              @change="onPinSelected"
            >
              <option :value="null" disabled>-- Pilih alamat absensi --</option>
              <option v-for="pin in allowedPins" :key="pin.id" :value="pin.id">
                {{ pin.name }}{{ pin.address ? ` — ${pin.address}` : "" }}
              </option>
            </select>
            <AppIcon
              name="ChevronDown"
              class="select-chevron"
              :size="16"
              :stroke-width="2.3"
              aria-hidden="true"
            />
          </div>
        </div>

        <div class="hero-distance" :class="heroDistanceStatusClass">
          <div class="hero-distance__main">
            <p class="hero-distance__label">Jarak ke pin</p>
            <div class="hero-distance__value-row">
              <span class="hero-distance__value">{{ distanceHeroValue }}</span>
              <span class="hero-distance__unit">{{ distanceHeroUnit }}</span>
            </div>
            <p class="hero-distance__pin">
              {{ selectedPinLabel || selectedStoreName || "Pilih pin point" }}
            </p>
          </div>

          <div class="hero-distance__aside">
            <span class="hero-distance__badge" :class="distanceBadgeClass">
              {{ distanceBadgeLabel }}
            </span>
            <p class="hero-distance__caption">{{ distanceHeroCaption }}</p>
            <p
              v-if="remainingToRadiusMeters !== null && !isInsideStoreRadius"
              class="hero-distance__remain"
            >
              Sisa <strong>{{ remainingToRadiusMeters }} m</strong> ke radius
              {{ activeGeofenceRadiusLabel }}
            </p>
            <p
              v-else-if="isInsideStoreRadius && isGpsAccuracyAcceptable"
              class="hero-distance__remain hero-distance__remain--ok"
            >
              GPS ±{{ locationAccuracy ?? 0 }} m — siap clock
              {{ isAttendanceOpen ? "out" : "in" }}
            </p>
            <p
              v-else-if="isInsideStoreRadius"
              class="hero-distance__remain"
            >
              Dalam radius · tunggu GPS ≤ ±{{ maxGpsAccuracyMeters }} m
            </p>
            <div class="hero-distance__actions">
              <button
                type="button"
                class="hero-distance__refresh"
                :disabled="
                  isRefreshingDistance ||
                  locationStatus === 'locating' ||
                  isSubmitting
                "
                @click="refreshDistance"
              >
                <AppIcon name="RefreshCw" :size="14" :stroke-width="2.3" />
                {{
                  isRefreshingDistance || locationStatus === "locating"
                    ? "Mengukur…"
                    : "Perbarui"
                }}
              </button>
              <label class="hero-distance__follow">
                <input
                  v-model="followDistance"
                  type="checkbox"
                  :disabled="isSubmitting"
                  @change="onFollowDistanceToggle"
                />
                <span>Ikuti</span>
              </label>
            </div>
          </div>
        </div>

        <AppButton
          type="button"
          class="hero-action-button"
          variant="primary"
          icon="ArrowRight"
          :disabled="isSubmitting || selectedPinId === null"
          @click="submitAttendance"
        >
          {{ actionButtonLabel }}
        </AppButton>
      </section>

      <section v-if="showStoreMap" class="pwa-card map-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="MapPinned" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>{{ selectedPinLabel ? "Lokasi Pin & Radius" : "Lokasi & Radius" }}</h2>
            <p class="card-sub">
              {{
                selectedPinLabel
                  ? `Radius ${activeGeofenceRadiusLabel} mengikuti pin point yang dipilih.`
                  : allowedPins.length > 1
                    ? "Pilih pin point dulu agar jarak & radius akurat."
                    : `Visualisasi lokasi absensi dan area validasi ${activeGeofenceRadiusLabel}.`
              }}
            </p>
          </div>
        </div>

        <div v-if="mapError && !isUsingCityFallback" class="map-warning">
          {{ mapError }}
        </div>

        <div v-if="isUsingCityFallback" class="map-warning map-warning--notice">
          {{ mapError }}
        </div>

        <div
          v-if="!mapError || isUsingCityFallback"
          ref="mapContainer"
          class="carto-map"
          aria-label="Peta lokasi cabang/pin dan GPS pengguna"
        />

        <div class="map-meta-row">
          <div class="map-meta-pill">
            <span>{{ mapLocationMetaLabel }}</span>
            <strong>{{ mapLocationMetaValue }}</strong>
          </div>
          <div class="map-meta-pill">
            <span>Radius</span>
            <strong>{{
              isUsingCityFallback ? "Belum tersedia" : activeGeofenceRadiusLabel
            }}</strong>
          </div>
          <div class="map-meta-pill">
            <span>GPS</span>
            <strong>{{
              currentMapLocation
                ? `±${Math.round(currentMapLocation.accuracy ?? 0)} m`
                : "Belum ada"
            }}</strong>
          </div>
        </div>

        <div class="map-detail-row">
          <span>{{ mapDistanceLabel ?? "Lokasi Anda belum diukur" }}</span>
        </div>
      </section>

      <section class="mini-summary-grid">
        <div class="mini-summary-item">
          <span>Cabang</span>
          <strong>{{ assignmentLocationLabel }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Pin aktif</span>
          <strong>{{ activePinStatsLabel }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Waktu</span>
          <strong>{{ formatCurrentTime() }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Durasi</span>
          <strong>{{ attendanceDurationLabel }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>GPS</span>
          <strong>{{
            locationAccuracy !== null ? `±${locationAccuracy} m` : "Belum ada"
          }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Status</span>
          <strong>{{
            isAttendanceOpen ? "Clock In aktif" : "Siap hadir"
          }}</strong>
        </div>
      </section>

      <section class="info-grid">
        <div class="info-card">
          <span class="info-title">Cabang</span>
          <strong>{{ selectedStoreName || "—" }}</strong>
          <small>{{ selectedCityName || "Penugasan cabang" }}</small>
        </div>
        <div class="info-card">
          <span class="info-title">Pin aktif</span>
          <strong>{{ activePinStatsLabel }}</strong>
          <small>{{ activePinStatsDetail }}</small>
        </div>
        <div class="info-card">
          <span class="info-title">Personel</span>
          <strong>{{ selectedOutsourceLabel || "—" }}</strong>
          <small>
            {{
              allowedPins.length > 1
                ? `${allowedPins.length} pin point diizinkan`
                : "Penugasan hari ini"
            }}
          </small>
        </div>
      </section>
    </template>

    <!-- Wizard-only map (session map lives under the red attendance card). -->
    <section v-if="showStoreMap && !isSessionActive" class="pwa-card map-card">
      <div class="card-title-row">
        <span class="card-icon-pill" aria-hidden="true">
          <AppIcon name="MapPinned" :size="18" :stroke-width="2" />
        </span>
        <div>
          <h2>{{ selectedPinLabel ? "Lokasi Pin & Radius" : "Lokasi & Radius" }}</h2>
          <p class="card-sub">
            {{
              selectedPinLabel
                ? `Radius ${activeGeofenceRadiusLabel} mengikuti pin point yang dipilih.`
                : allowedPins.length > 1
                  ? "Pilih pin point dulu agar jarak & radius akurat."
                  : `Visualisasi lokasi absensi dan area validasi ${activeGeofenceRadiusLabel}.`
            }}
          </p>
        </div>
      </div>

      <div v-if="mapError && !isUsingCityFallback" class="map-warning">
        {{ mapError }}
      </div>

      <div v-if="isUsingCityFallback" class="map-warning map-warning--notice">
        {{ mapError }}
      </div>

      <div
        v-if="!mapError || isUsingCityFallback"
        ref="mapContainer"
        class="carto-map"
        aria-label="Peta lokasi cabang/pin dan GPS pengguna"
      />

      <div class="map-meta-row">
        <div class="map-meta-pill">
          <span>{{ mapLocationMetaLabel }}</span>
          <strong>{{ mapLocationMetaValue }}</strong>
        </div>
        <div class="map-meta-pill">
          <span>Radius</span>
          <strong>{{
            isUsingCityFallback ? "Belum tersedia" : activeGeofenceRadiusLabel
          }}</strong>
        </div>
        <div class="map-meta-pill">
          <span>GPS</span>
          <strong>{{
            currentMapLocation
              ? `±${Math.round(currentMapLocation.accuracy ?? 0)} m`
              : "Belum ada"
          }}</strong>
        </div>
      </div>

      <div class="map-detail-row">
        <span>{{ mapDistanceLabel ?? "Lokasi Anda belum diukur" }}</span>
      </div>
    </section>

    <template v-if="step === 'completed'">
      <section class="pwa-card completed-box">
        <div class="completed-icon" aria-hidden="true">
          <AppIcon name="CircleCheckBig" :size="40" :stroke-width="2.3" />
        </div>
        <h2>Presensi Hari Ini Selesai</h2>
        <p class="completed-sub">
          Terima kasih atas kerja keras Anda hari ini!
        </p>

        <div class="summary-grid">
          <div class="summary-item">
            <span>Tanggal</span>
            <strong>{{ formatDate(attendanceDate ?? checkInAt) }}</strong>
          </div>
          <div class="summary-item">
            <span>Personel</span>
            <strong>{{ selectedOutsourceLabel }}</strong>
          </div>
          <div class="summary-item">
            <span>Jam Masuk</span>
            <strong>{{ formatTime(checkInAt) }}</strong>
          </div>
          <div class="summary-item">
            <span>Jam Keluar</span>
            <strong>{{ formatTime(checkOutAt) }}</strong>
          </div>
          <div v-if="durationMinutes !== null" class="summary-item full-width">
            <span>Total Durasi Bekerja</span>
            <strong class="highlight-duration">
              {{ Math.floor(durationMinutes / 60) }} Jam
              {{ durationMinutes % 60 }} Menit
            </strong>
          </div>
        </div>

        <AppButton
          type="button"
          class="btn-primary"
          variant="primary"
          icon="CircleCheckBig"
          @click="resetSelection"
        >
          Selesai & Tutup Sesi
        </AppButton>
      </section>
    </template>
    </template><!-- /v-else non-login -->
  </main>
</template>

<style scoped>
/* ── Login mode — same split-screen UI style as Employee login ────────── */
/* Must beat `.outsource-app { width: min(100%, 32rem) }` which comes later. */
.outsource-app.outsource-app--login {
  --accent: #eb1c24;
  --accent-dark: #c9151c;
  --accent-ring: rgba(235, 28, 36, 0.12);
  --surface: #fff;
  --login-text: #52525b;
  --login-text-h: #18181b;
  box-sizing: border-box;
  width: 100%;
  max-width: none;
  min-height: 100svh;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  background: #f8f8f8;
  color: var(--login-text-h);
  text-align: left;
}
.outsource-app--login .login-visual {
  position: relative;
  box-sizing: border-box;
  min-width: 0;
  min-height: 100svh;
  padding: clamp(2rem, 5vw, 5rem);
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  overflow: hidden;
  background: var(--accent);
  color: #fff;
}
.outsource-app--login .login-visual::after {
  content: '';
  position: absolute;
  right: -14rem;
  bottom: -15rem;
  width: 32rem;
  height: 32rem;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 50%;
  box-shadow: 0 0 0 3rem rgba(255, 255, 255, 0.04), 0 0 0 6rem rgba(255, 255, 255, 0.04);
}
.outsource-app--login .visual-content {
  position: relative;
  z-index: 1;
  box-sizing: border-box;
  width: min(100%, 30rem);
  max-width: 100%;
  margin-inline: auto;
  padding-inline: 0.25rem;
  text-align: center;
  transform: translateY(-2vh);
  animation: outsource-login-rise 0.7s ease both;
}
.outsource-app--login .logo-frame {
  display: inline-flex;
  padding: 0.45rem;
  margin-bottom: 2rem;
  border-radius: 13px;
  background: #fff;
  box-shadow: 0 12px 30px rgba(90, 0, 5, 0.2);
}
.outsource-app--login .login-visual .brand-logo {
  display: block;
  width: clamp(104px, 10vw, 146px);
  height: clamp(104px, 10vw, 146px);
  border-radius: 9px;
  object-fit: cover;
  box-shadow: none;
}
.outsource-app--login .visual-kicker {
  margin: 0;
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}
.outsource-app--login .visual-content h1 {
  margin: 0.8rem 0 1.1rem;
  color: #fff;
  font-size: clamp(2.4rem, 4.2vw, 4.6rem);
  line-height: 1.02;
  letter-spacing: -0.05em;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.outsource-app--login .visual-content h1 strong {
  font-weight: 700;
}
.outsource-app--login .visual-copy {
  max-width: min(23rem, 100%);
  margin-inline: auto;
  color: rgba(255, 255, 255, 0.78);
  font-size: 1rem;
  line-height: 1.6;
}
.outsource-app--login .login-panel {
  box-sizing: border-box;
  min-width: 0;
  width: min(100%, 32rem);
  margin: auto;
  padding: clamp(1.5rem, 4vw, 3rem);
  animation: outsource-login-rise 0.7s 0.1s ease both;
}
.outsource-app--login .login-heading { margin-bottom: 1.75rem; }
.outsource-app--login .eyebrow {
  margin: 0;
  color: var(--accent);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}
.outsource-app--login .login-heading h2 {
  margin: 0.55rem 0 0.45rem;
  color: var(--login-text-h);
  font-size: clamp(1.7rem, 3vw, 2.25rem);
  font-weight: 700;
  letter-spacing: -0.04em;
}
.outsource-app--login .login-intro {
  margin: 0;
  color: var(--login-text);
  line-height: 1.55;
}

/* ── Login form fields (mirror EmployeeLoginPage) ── */
.outsource-app--login form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.outsource-app--login .login-panel label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  color: var(--login-text-h);
  font-size: 0.78rem;
  font-weight: 600;
}
.outsource-app--login .field-label { gap: 0.45rem; }
.outsource-app--login .field-control {
  position: relative;
  display: flex;
  align-items: center;
}
.outsource-app--login .field-icon {
  position: absolute;
  left: 0.9rem;
  z-index: 1;
  color: #a1a1a8;
  pointer-events: none;
}
.outsource-app--login .login-panel input {
  box-sizing: border-box;
  width: 100%;
  padding: 0.9rem 2.5rem;
  border: 1px solid #dedee2;
  border-radius: 7px;
  color: var(--login-text-h);
  background: var(--surface);
  font-size: 0.875rem;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.outsource-app--login .login-panel input::placeholder { color: #aaaab1; }
.outsource-app--login .login-panel input:focus {
  border-color: var(--accent);
  outline: none;
  box-shadow: 0 0 0 4px var(--accent-ring);
}
.outsource-app--login .login-panel input:-webkit-autofill,
.outsource-app--login .login-panel input:autofill {
  -webkit-text-fill-color: var(--login-text-h);
  caret-color: var(--login-text-h);
  -webkit-box-shadow: 0 0 0 1000px var(--surface) inset;
  box-shadow: 0 0 0 1000px var(--surface) inset;
  transition: background-color 99999s ease-in-out 0s;
}
.outsource-app--login .password-toggle {
  position: absolute;
  right: 0.65rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: auto;
  margin: 0;
  padding: 0.25rem;
  border: 0;
  background: transparent;
  color: var(--accent);
  cursor: pointer;
  box-shadow: none;
}
.outsource-app--login .password-toggle:hover { color: var(--accent-dark); }
.outsource-app--login .login-panel :deep(.app-button) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  margin-top: 0.35rem;
  min-height: 0;
  padding: 0.9rem 1rem 0.9rem 1.1rem;
  border: 0;
  border-radius: 7px;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
  box-shadow: none;
  cursor: pointer;
  transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}
.outsource-app--login .login-panel :deep(.app-button):hover:not(:disabled) {
  background: var(--accent-dark);
  transform: translateY(-1px);
  box-shadow: 0 8px 18px rgba(235, 28, 36, 0.2);
}
.outsource-app--login .login-panel :deep(.app-button):disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
.outsource-app--login .error {
  margin: 0;
  color: #b91c1c;
  font-size: 0.8rem;
}
.outsource-app--login .error-banner {
  padding: 0.75rem;
  border-radius: 6px;
  background: #fff1f2;
  font-size: 0.85rem;
}
.outsource-app--login .success {
  margin: 0;
  color: #15803d;
  font-size: 0.8rem;
}
.outsource-app--login .success-banner {
  padding: 0.75rem;
  border-radius: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  font-size: 0.85rem;
}
.outsource-app--login .login-note {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 1.25rem;
  margin-bottom: 0;
  color: #85858d;
  font-size: 0.78rem;
}
.outsource-app--login .secure-mark {
  display: inline-grid;
  width: 1rem;
  height: 1rem;
  place-items: center;
  border-radius: 50%;
  background: #e8f7ee;
  color: #25834a;
  flex-shrink: 0;
}
@keyframes outsource-login-rise {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 760px) {
  .outsource-app.outsource-app--login { display: block; background: #f8f8f8; }
  .outsource-app--login .login-visual { min-height: 300px; padding: 1.5rem; }
  .outsource-app--login .visual-content { margin-top: 0; transform: none; }
  .outsource-app--login .visual-content h1 { font-size: 2.35rem; }
  .outsource-app--login .visual-copy { display: none; }
  .outsource-app--login .logo-frame { margin-bottom: 1rem; }
  .outsource-app--login .login-visual .brand-logo { width: 78px; height: 78px; }
  .outsource-app--login .login-panel { width: 100%; padding: 2rem 1.25rem 2.5rem; }
}

/* ── Non-login outsource shell ── */
.outsource-app {
  box-sizing: border-box;
  width: min(100%, 32rem);
  min-height: 100svh;
  margin: 0 auto;
  padding: 1rem 1rem 4rem;
  background: #f8f8f8;
  color: var(--text-h);
  text-align: left;
}

/* App Header */
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.45rem 0 1.25rem;
}

.brand-lockup {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.brand-logo {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 10px;
  object-fit: cover;
  box-shadow: 0 6px 18px rgba(17, 17, 17, 0.1);
}

.brand-eyebrow,
.hero-kicker,
.welcome-kicker {
  margin: 0;
  color: var(--accent);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.brand-lockup strong {
  font-size: 0.95rem;
  color: var(--text-h);
}

.header-meta {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.user-badge {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #eb1c24, #b5171d);
  color: #fff;
  font-weight: 700;
  font-size: 0.95rem;
  box-shadow: 0 8px 18px rgba(235, 28, 36, 0.22);
}

.kiosk-badge {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #fff, #f4f4f5);
  color: var(--accent);
  font-size: 1rem;
  border: 1px solid rgba(235, 28, 36, 0.1);
}

/* Welcome Block */
.welcome-block {
  padding: 0.5rem 0 1.1rem;
}

.date-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.25rem;
}

.clock-live {
  font-family: var(--mono);
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
  background: var(--code-bg);
  padding: 0.15rem 0.5rem;
  border-radius: 6px;
}

.welcome-block h1 {
  margin: 0.35rem 0 0.25rem;
  font-size: clamp(1.6rem, 3vw, 2rem);
  font-weight: 700;
  letter-spacing: -0.04em;
  color: var(--text-h);
}

.welcome-block p {
  color: var(--text);
  font-size: 0.9rem;
  line-height: 1.5;
}

/* Floating toasts — fixed to viewport, manual dismiss only (no auto-hide) */
.outsource-toast-stack {
  position: fixed;
  top: max(0.85rem, env(safe-area-inset-top, 0px));
  left: 50%;
  z-index: 1200;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  width: min(26rem, calc(100vw - 1.5rem));
  transform: translateX(-50%);
  pointer-events: none;
}

.outsource-toast {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.8rem 0.9rem;
  border-radius: 12px;
  font-size: 0.85rem;
  line-height: 1.45;
  box-shadow:
    0 12px 28px rgba(15, 23, 42, 0.14),
    0 2px 8px rgba(15, 23, 42, 0.08);
  pointer-events: auto;
  animation: outsource-toast-in 0.22s ease-out;
}

@keyframes outsource-toast-in {
  from {
    opacity: 0;
    transform: translateY(-0.45rem);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.map-card {
  margin-top: 1rem;
}

.carto-map {
  width: 100%;
  height: 220px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  background: linear-gradient(135deg, #e2e8f0, #dbeafe);
  overflow: hidden;
  z-index: 0;
}

.carto-map-marker {
  background: transparent;
  border: none;
}

.map-warning {
  display: flex;
  align-items: center;
  min-height: 220px;
  padding: 1rem;
  border-radius: 14px;
  background: #fff7ed;
  border: 1px solid #fdba74;
  color: #9a5b00;
  line-height: 1.5;
}

.map-warning--notice {
  min-height: 0;
  margin-bottom: 0.75rem;
  padding: 0.75rem 0.85rem;
  font-size: 0.78rem;
}

.map-meta-row {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
  margin-top: 0.9rem;
}

.map-meta-pill {
  display: grid;
  grid-template-columns: minmax(4rem, auto) minmax(0, 1fr);
  align-items: center;
  gap: 0.2rem;
  min-width: 0;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: #f8fafc;
  border: 1px solid var(--border);
}

.map-meta-pill span {
  font-size: 0.66rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.map-meta-pill strong {
  font-size: 0.8rem;
  color: var(--text-h);
  overflow-wrap: anywhere;
}

.map-detail-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 0.9rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--border);
  font-size: 0.82rem;
  color: var(--text);
}

.outsource-toast__content {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  flex: 1;
  min-width: 0;
}

.outsource-toast__content p {
  margin: 0;
  overflow-wrap: anywhere;
}

.outsource-toast__icon {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.outsource-toast--error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
}

.outsource-toast--success {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #15803d;
}

.outsource-toast__dismiss {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  margin: -0.15rem -0.2rem 0 0;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  cursor: pointer;
}

.outsource-toast__dismiss:hover {
  background: rgba(15, 23, 42, 0.06);
}

/* Wizard Stepper */
.wizard-stepper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.25rem;
  padding: 0.8rem 1rem;
  background: rgba(255, 255, 255, 0.9);
  border-radius: 14px;
  border: 1px solid var(--border);
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.02);
}

.step-item {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  color: #a1a1aa;
}

.step-item.active {
  color: var(--accent);
  font-weight: 700;
}

.step-item.done {
  color: #15803d;
}

.step-num {
  display: grid;
  width: 1.5rem;
  height: 1.5rem;
  place-items: center;
  border-radius: 50%;
  font-size: 0.72rem;
  font-weight: 700;
  background: #f4f4f5;
  color: inherit;
}

.step-item.active .step-num {
  background: var(--accent);
  color: #fff;
}

.step-item.done .step-num {
  background: #dcfce7;
  color: #15803d;
}

.step-text {
  font-size: 0.78rem;
}

.step-divider {
  flex: 1;
  height: 1px;
  background: #e4e4e7;
  margin: 0 0.5rem;
}

/* Standard PWA Card */
.pwa-card {
  padding: 1.35rem;
  border-radius: 14px;
  background: #fff;
  border: 1px solid var(--border);
  box-shadow: 0 4px 16px rgba(24, 24, 28, 0.04);
  margin-bottom: 1.25rem;
}

.pin-allowlist {
  margin: 0;
  padding-left: 1.1rem;
  color: var(--text);
  font-size: 0.86rem;
  line-height: 1.5;
}

.greet-card {
  padding: 1.2rem 1.15rem 0;
  border-radius: 16px;
  background: #fff;
  border: 1px solid var(--border);
  box-shadow: 0 8px 22px rgba(24, 24, 28, 0.05);
  margin-bottom: 1.25rem;
  overflow: hidden;
}

.greet-card__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.greet-card__identity {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  min-width: 0;
}

.greet-card__avatar {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 12px;
  background: linear-gradient(145deg, #eb1c24, #c5151d);
  color: #fff;
  font-size: 1.05rem;
  font-weight: 700;
}

.greet-card__who {
  min-width: 0;
}

.greet-card__eyebrow {
  margin: 0;
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text);
}

.greet-card__who h2 {
  margin: 0.15rem 0 0.2rem;
  font-size: 1.12rem;
  font-weight: 700;
  color: var(--text-h);
  line-height: 1.25;
  word-break: break-word;
}

.greet-card__code {
  margin: 0;
  font-size: 0.78rem;
  color: var(--text);
}

.greet-card__code strong {
  color: var(--text-h);
  font-family: var(--mono);
  font-weight: 600;
}

.greet-card__status {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  min-height: 1.55rem;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  white-space: nowrap;
}

.greet-card__status--ready {
  background: #ecfdf5;
  color: #15803d;
}

.greet-card__status--active {
  background: #fff7ed;
  color: #c2410c;
}

.greet-card__facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
  margin: 1rem 0 0.9rem;
}

.greet-fact {
  display: grid;
  gap: 0.18rem;
  padding: 0.65rem 0.7rem;
  border-radius: 10px;
  background: #f7f7f8;
  border: 1px solid #ececf0;
}

.greet-fact span {
  font-size: 0.66rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text);
}

.greet-fact strong {
  font-size: 0.86rem;
  color: var(--text-h);
  line-height: 1.3;
  word-break: break-word;
}

.greet-card__pins {
  padding: 0.75rem 0.8rem 0.7rem;
  border-radius: 12px;
  border: 1px solid #ececf0;
  background: #fafafa;
}

.greet-card__pins-head {
  margin-bottom: 0.55rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text);
}

.greet-pin-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.greet-pin-list li {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.55rem 0.6rem;
  border-radius: 9px;
  background: #fff;
  border: 1px solid #ececf0;
}

.greet-pin-list__icon {
  display: grid;
  place-items: center;
  width: 1.7rem;
  height: 1.7rem;
  border-radius: 8px;
  background: rgba(235, 28, 36, 0.08);
  color: var(--accent);
  flex-shrink: 0;
}

.greet-pin-list li div {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.greet-pin-list strong {
  font-size: 0.86rem;
  color: var(--text-h);
}

.greet-pin-list small {
  font-size: 0.74rem;
  color: var(--text);
  line-height: 1.35;
}

.greet-card__empty {
  margin: 0;
  font-size: 0.8rem;
  line-height: 1.45;
  color: var(--text);
}

.greet-card__pins-note {
  margin: 0.65rem 0 0;
  font-size: 0.74rem;
  line-height: 1.4;
  color: var(--text);
}

.greet-card__hint {
  margin: 0 0 0.85rem;
  font-size: 0.8rem;
  line-height: 1.45;
  color: rgba(255, 255, 255, 0.88);
}

.greet-card__action {
  /* Bleed to outer card edges (cancel .greet-card horizontal padding). */
  margin: 1rem -1.15rem 0;
  padding: 1rem 1.15rem 1.15rem;
  background: linear-gradient(135deg, #eb1c24 0%, #c5151d 100%);
  border-radius: 0 0 15px 15px;
}

.greet-card__cta {
  width: 100%;
}

.greet-card__action :deep(.hero-action-button),
.hero-attendance-card :deep(.hero-action-button) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 3.1rem;
  padding: 0.9rem 1.15rem;
  border-radius: 11px;
  border: none;
  background: #fff !important;
  color: #eb1c24 !important;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.08);
  transition:
    transform 0.15s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
}

.greet-card__action :deep(.hero-action-button .app-button__icon),
.greet-card__action :deep(.hero-action-button svg),
.hero-attendance-card :deep(.hero-action-button .app-button__icon),
.hero-attendance-card :deep(.hero-action-button svg) {
  color: #eb1c24 !important;
  stroke: #eb1c24 !important;
}

.greet-card__action :deep(.hero-action-button:hover:not(:disabled)),
.hero-attendance-card :deep(.hero-action-button:hover:not(:disabled)) {
  transform: translateY(-1px);
  box-shadow: 0 12px 22px rgba(17, 17, 17, 0.12);
}

.greet-card__action :deep(.hero-action-button:active:not(:disabled)),
.hero-attendance-card :deep(.hero-action-button:active:not(:disabled)) {
  transform: scale(0.985);
}

.greet-card__action :deep(.hero-action-button:disabled),
.hero-attendance-card :deep(.hero-action-button:disabled) {
  opacity: 0.7;
  cursor: not-allowed;
}

.history-card {
  padding: 1.1rem 1.05rem 1rem;
  border-radius: 16px;
  background: #fff;
  border: 1px solid var(--border);
  box-shadow: 0 8px 22px rgba(24, 24, 28, 0.04);
  margin-bottom: 1.25rem;
}

.history-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}

.history-card__head h2 {
  margin: 0;
  font-size: 1rem;
  color: var(--text-h);
}

.history-card__head p {
  margin: 0.2rem 0 0;
  font-size: 0.76rem;
  color: var(--text);
}

.history-card__refresh {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 1.9rem;
  padding: 0.3rem 0.65rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: #fafafa;
  color: var(--text-h);
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
}

.history-card__refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.history-card__empty {
  margin: 0;
  padding: 0.85rem 0.2rem 0.4rem;
  font-size: 0.82rem;
  color: var(--text);
  text-align: center;
}

.history-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}

.history-list li {
  padding: 0.7rem 0.75rem;
  border-radius: 11px;
  border: 1px solid #ececf0;
  background: #fafafa;
}

.history-list__main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
}

.history-list__main strong {
  font-size: 0.88rem;
  color: var(--text-h);
}

.history-list__status {
  display: inline-flex;
  align-items: center;
  min-height: 1.4rem;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 700;
  background: #f4f4f5;
  color: #52525b;
  white-space: nowrap;
}

.history-list__status--ok {
  background: #ecfdf5;
  color: #15803d;
}

.history-list__status--open {
  background: #fff7ed;
  color: #c2410c;
}

.history-list__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.85rem;
  margin-top: 0.4rem;
  font-size: 0.74rem;
  color: var(--text);
}

@media (max-width: 420px) {
  .greet-card__facts {
    grid-template-columns: 1fr;
  }

  .greet-card__top {
    flex-direction: column;
  }
}

.action-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-top: 0.85rem;
}

.profile-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin: -0.2rem 0 1rem;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: rgba(235, 28, 36, 0.04);
  border: 1px solid rgba(235, 28, 36, 0.12);
}

.profile-summary__label {
  font-size: 0.72rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text);
}

.profile-summary strong {
  font-size: 0.82rem;
  color: var(--text-h);
}

.card-title-row {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}

.card-icon-pill {
  display: grid;
  width: 2.4rem;
  height: 2.4rem;
  place-items: center;
  border-radius: 10px;
  background: var(--accent-bg);
  font-size: 1.2rem;
  flex-shrink: 0;
}

.card-title-row h2 {
  margin: 0 0 0.2rem;
  font-size: 1.08rem;
  font-weight: 700;
  color: var(--text-h);
}

.card-sub {
  color: var(--text);
  font-size: 0.8rem;
}

.field-block {
  margin-bottom: 1.25rem;
}

.field-block label {
  display: block;
  margin-bottom: 0.45rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
}

.select-wrapper {
  position: relative;
}

.select-wrapper select {
  width: 100%;
  min-height: 3rem;
  appearance: none;
  padding: 0.75rem 2.2rem 0.75rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 9px;
  background: #fff;
  color: var(--text-h);
  font-size: 0.92rem;
  outline: none;
  transition:
    border-color 0.2s,
    box-shadow 0.2s ease;
}

.select-wrapper select:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 4px rgba(235, 28, 36, 0.08);
}

.select-wrapper select:hover {
  border-color: rgba(235, 28, 36, 0.35);
}

.select-chevron {
  position: absolute;
  right: 0.85rem;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: #71717a;
  font-size: 0.8rem;
}

.hint-empty {
  margin-top: 0.45rem;
  color: #b91c1c;
  font-size: 0.78rem;
}

.location-requirement {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.65rem;
  margin: -0.25rem 0 1.25rem;
  padding: 0.8rem;
  border: 1px solid #fdba74;
  border-radius: 10px;
  background: #fff7ed;
  color: #9a3412;
}

.location-requirement--ready {
  border-color: #bbf7d0;
  background: #f0fdf4;
  color: #166534;
}

.location-requirement > div {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.location-requirement strong,
.location-requirement span {
  overflow-wrap: anywhere;
}

.location-requirement strong {
  font-size: 0.8rem;
}

.location-requirement span {
  font-size: 0.72rem;
  line-height: 1.35;
}

.location-retry-button {
  min-height: 2rem;
  padding: 0.35rem 0.65rem;
  border: 1px solid currentColor;
  border-radius: 7px;
  background: transparent;
  color: inherit;
  font-size: 0.72rem;
  font-weight: 700;
  cursor: pointer;
}

.location-retry-button:disabled {
  opacity: 0.55;
  cursor: wait;
}

/* Button System */
.button-group {
  display: grid;
  grid-template-columns: minmax(112px, 0.9fr) minmax(170px, 1.6fr);
  gap: 0.75rem;
  align-items: stretch;
}

.button-group > * {
  width: 100%;
}

.button-group .app-button {
  min-height: 3rem;
  font-size: 0.92rem;
}

.button-group .btn-secondary {
  background: #f8f8f9;
}

.btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.85rem 1.25rem;
  border-radius: 9px;
  border: none;
  background: linear-gradient(180deg, #eb1c24 0%, #c5151d 100%);
  color: #fff;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 6px 18px rgba(235, 28, 36, 0.24);
  transition:
    transform 0.15s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(235, 28, 36, 0.3);
}

.btn-primary:focus-visible,
.btn-secondary:focus-visible,
.outsource-toast__dismiss:focus-visible,
.btn-link-reset:focus-visible,
.hero-action-button:focus-visible {
  outline: 3px solid rgba(235, 28, 36, 0.18);
  outline-offset: 2px;
}

.btn-primary:active:not(:disabled) {
  transform: scale(0.985);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  box-shadow: none;
}

.btn-secondary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.85rem 1rem;
  border-radius: 9px;
  border: 1px solid var(--border);
  background: #fff;
  color: var(--text-h);
  font-size: 0.92rem;
  font-weight: 600;
  cursor: pointer;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    transform 0.15s ease;
}

.btn-secondary:hover {
  background: #f4f4f5;
  border-color: rgba(235, 28, 36, 0.2);
  transform: translateY(-1px);
}

/* HERO ATTENDANCE CARD (Employee PWA Concept) */
.hero-attendance-card {
  padding: 1.35rem 1.2rem 1.15rem;
  border-radius: 16px;
  background: linear-gradient(135deg, #eb1c24 0%, #c5151d 100%);
  color: #fff;
  box-shadow: 0 18px 34px rgba(235, 28, 36, 0.24);
  margin-bottom: 1.25rem;
}

.card-header-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.hero-kicker {
  color: rgba(255, 255, 255, 0.75);
}

.card-header-line h2 {
  margin: 0.25rem 0 0;
  color: #fff;
  font-size: 1.35rem;
  font-weight: 700;
}

.status-pulse-dot {
  width: 0.8rem;
  height: 0.8rem;
  border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.5);
  transition: all 0.3s;
  box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.08);
}

.status-pulse-dot.active {
  background: #4ade80;
  box-shadow: 0 0 12px #4ade80;
  border-color: #fff;
}

.time-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin: 1.2rem 0 1rem;
  padding-top: 0.9rem;
  border-top: 1px solid rgba(255, 255, 255, 0.22);
}

.time-grid--three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.time-item {
  display: grid;
  gap: 0.25rem;
}

.time-item span {
  color: rgba(255, 255, 255, 0.76);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.time-item strong {
  font-size: 1.05rem;
  font-family: var(--mono);
}

.hero-field {
  margin-bottom: 0.85rem;
}

.hero-field label {
  color: rgba(255, 255, 255, 0.82);
}

.hero-distance {
  display: grid;
  grid-template-columns: minmax(6.5rem, 0.9fr) minmax(0, 1.2fr);
  gap: 0.85rem;
  margin: 0 0 1rem;
  padding: 0.9rem 0.95rem;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.18);
  border: 1px solid rgba(255, 255, 255, 0.16);
}

.hero-distance--inside {
  background: rgba(22, 163, 74, 0.28);
  border-color: rgba(134, 239, 172, 0.35);
}

.hero-distance--outside {
  background: rgba(0, 0, 0, 0.22);
}

.hero-distance--pending {
  background: rgba(0, 0, 0, 0.16);
}

.hero-distance__label,
.hero-distance__pin,
.hero-distance__caption,
.hero-distance__remain {
  margin: 0;
}

.hero-distance__label {
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.72);
}

.hero-distance__value-row {
  display: flex;
  align-items: baseline;
  gap: 0.3rem;
  margin: 0.2rem 0 0.25rem;
}

.hero-distance__value {
  font-size: 2rem;
  font-weight: 700;
  font-family: var(--mono);
  line-height: 1;
  color: #fff;
}

.hero-distance--inside .hero-distance__value {
  color: #bbf7d0;
}

.hero-distance--outside .hero-distance__value {
  color: #fecaca;
}

.hero-distance__unit {
  font-size: 0.85rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.8);
}

.hero-distance__pin {
  font-size: 0.78rem;
  color: rgba(255, 255, 255, 0.78);
  line-height: 1.25;
}

.hero-distance__aside {
  display: grid;
  gap: 0.35rem;
  align-content: start;
}

.hero-distance__badge {
  display: inline-flex;
  width: fit-content;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
}

.hero-distance__badge.badge-ready {
  background: rgba(74, 222, 128, 0.28);
  color: #dcfce7;
}

.hero-distance__badge.badge-outside {
  background: rgba(248, 113, 113, 0.28);
  color: #fee2e2;
}

.hero-distance__badge.badge-locating,
.hero-distance__badge.badge-error {
  background: rgba(255, 255, 255, 0.14);
  color: rgba(255, 255, 255, 0.9);
}

.hero-distance__caption {
  font-size: 0.8rem;
  font-weight: 600;
  color: #fff;
  line-height: 1.3;
}

.hero-distance__remain {
  font-size: 0.72rem;
  color: rgba(255, 255, 255, 0.78);
  line-height: 1.3;
}

.hero-distance__remain strong {
  color: #fff;
  font-weight: 700;
}

.hero-distance__remain--ok {
  color: #bbf7d0;
}

.hero-distance__actions {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  margin-top: 0.15rem;
  flex-wrap: wrap;
}

.hero-distance__refresh {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 1.85rem;
  padding: 0.3rem 0.65rem;
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
}

.hero-distance__refresh:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.hero-distance__follow {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.72rem;
  color: rgba(255, 255, 255, 0.85);
  cursor: pointer;
}

.hero-distance__follow input {
  accent-color: #fff;
}

.hero-action-button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 3.1rem;
  padding: 0.9rem 1.15rem;
  border-radius: 11px;
  border: none;
  background: #fff;
  color: var(--accent);
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 10px 20px rgba(17, 17, 17, 0.08);
  transition:
    transform 0.15s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
}

.hero-action-button:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 12px 22px rgba(17, 17, 17, 0.12);
}

.hero-action-button:active:not(:disabled) {
  transform: scale(0.985);
}

.hero-action-button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

@media (max-width: 420px) {
  .hero-distance {
    grid-template-columns: 1fr;
  }

  .time-item strong {
    font-size: 0.95rem;
  }
}

/* Quick Status / GPS Card */
.quick-status-card {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.9rem 1rem;
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--border);
  margin-bottom: 1rem;
  box-shadow: 0 10px 18px rgba(17, 17, 17, 0.02);
}

.quick-icon-pill {
  display: grid;
  width: 2.2rem;
  height: 2.2rem;
  place-items: center;
  border-radius: 8px;
  background: #f4f4f5;
  font-size: 1.15rem;
}

.quick-details {
  flex: 1;
  display: grid;
  gap: 0.15rem;
}

.quick-details strong {
  font-size: 0.85rem;
  color: var(--text-h);
}

.quick-details small {
  font-size: 0.75rem;
  color: var(--text);
}

.mini-summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.7rem;
  margin: 0 0 1rem;
}

.mini-summary-item {
  display: grid;
  gap: 0.18rem;
  padding: 0.7rem 0.75rem;
  border-radius: 12px;
  background: #fff;
  border: 1px solid var(--border);
}

.mini-summary-item span {
  color: var(--text);
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.mini-summary-item strong {
  color: var(--text-h);
  font-size: 0.8rem;
  line-height: 1.4;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.8rem;
  margin: 0 0 1rem;
}

.info-grid .info-card:last-child:nth-child(odd) {
  grid-column: 1 / -1;
}

.info-card {
  display: grid;
  gap: 0.2rem;
  padding: 0.8rem 0.75rem;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.8);
}

.info-title {
  color: var(--text);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.info-card strong {
  color: var(--text-h);
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}

.info-card small {
  color: var(--text);
  font-size: 0.72rem;
}

/* Session Details Card */
.session-details-card {
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--border);
  padding: 1rem;
  box-shadow: 0 8px 18px rgba(17, 17, 17, 0.02);
}

.detail-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.detail-header-row h3 {
  margin: 0;
  font-size: 0.95rem;
  color: var(--text-h);
}

.session-person {
  margin: 0 0 0.35rem;
  color: var(--text-h);
  font-size: 0.84rem;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.detail-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  background: rgba(23, 183, 92, 0.12);
  color: #15803d;
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.session-row {
  display: grid;
  grid-template-columns: minmax(5.5rem, 0.42fr) minmax(0, 1fr);
  align-items: center;
  column-gap: 0.75rem;
  padding: 0.45rem 0;
  font-size: 0.82rem;
  border-bottom: 1px solid #f4f4f5;
}

.session-row:last-of-type {
  border-bottom: none;
}

.session-row .label {
  color: var(--text);
}

.session-row .value {
  min-width: 0;
  font-weight: 600;
  color: var(--text-h);
  overflow-wrap: anywhere;
  text-align: right;
}

.btn-link-reset {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.75rem;
  border: none;
  background: transparent;
  color: #71717a;
  font-size: 0.78rem;
  cursor: pointer;
  padding: 0;
}

.btn-link-reset:hover {
  color: var(--accent);
}

.session-reset-row {
  margin-top: 0.25rem;
  display: flex;
  justify-content: center;
}

.session-reset-row .btn-link-reset {
  margin-top: 0;
}

/* Completed Screen */
.completed-box {
  text-align: center;
  padding: 2rem 1.5rem;
}

.summary-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.7rem;
  margin: 1rem 0 1rem;
}

.summary-pill {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(3px);
}

.mini-label {
  font-size: 0.66rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.72);
}

.summary-pill strong {
  color: #fff;
  font-size: 0.84rem;
}

.completed-icon {
  display: grid;
  width: 3.85rem;
  height: 3.85rem;
  margin: 0 auto 1rem;
  place-items: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  color: #15803d;
  font-size: 1.8rem;
  font-weight: 700;
  box-shadow: 0 10px 24px rgba(34, 197, 94, 0.18);
}

.completed-box h2 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
}

.completed-sub {
  color: var(--text);
  font-size: 0.85rem;
  margin-bottom: 1.5rem;
}

.summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem;
  text-align: left;
  background: #fafafa;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1rem;
  margin-bottom: 1.5rem;
}

.summary-item {
  display: grid;
  gap: 0.2rem;
}

.summary-item.full-width {
  grid-column: span 2;
  padding-top: 0.6rem;
  border-top: 1px solid var(--border);
}

.summary-item span {
  font-size: 0.7rem;
  color: var(--text);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.summary-item strong {
  font-size: 0.93rem;
  color: var(--text-h);
}

.highlight-duration {
  color: var(--accent);
  font-size: 1.1rem !important;
  font-weight: 700;
}

/* Responsive Container on Larger Displays (Desktop/Tablet) */
@media (min-width: 700px) {
  .outsource-app {
    margin-top: 2rem;
    border: 1px solid var(--border);
    border-radius: 16px;
    min-height: calc(100svh - 4rem);
    box-shadow: var(--shadow);
  }
}

@media (max-width: 420px) {
  .outsource-app {
    padding-inline: 0.75rem;
  }

  .info-grid {
    grid-template-columns: 1fr;
    gap: 0.65rem;
  }

  .session-details-card {
    padding: 0.9rem;
  }

  .session-row {
    grid-template-columns: 1fr;
    row-gap: 0.2rem;
    padding: 0.65rem 0;
  }

  .session-row .value {
    text-align: left;
  }
}
</style>
