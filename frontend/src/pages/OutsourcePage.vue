<script setup lang="ts">
declare global {
  interface Window {
    google?: any;
  }
}

import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import AppButton from "../components/AppButton.vue";
import AppIcon from "../components/AppIcon.vue";
import { ApiError } from "../services/apiClient";
import {
  fetchOutsourceCities,
  fetchOutsourceStores,
  fetchOutsourceOutsources,
  initOutsourceSession,
  outsourceCheckIn,
  outsourceCheckOut,
  type City,
  type Store,
  type Outsource,
  type OutsourceAttendanceResponse,
} from "../services/outsourceService";

type Step =
  | "city"
  | "store"
  | "outsource"
  | "session"
  | "attendance_open"
  | "completed";

const step = ref<Step>("city");
const sessionToken = ref<string | null>(null);
const expiresAt = ref<string | null>(null);
const mapContainer = ref<HTMLElement | null>(null);
const mapError = ref("");
const currentMapLocation = ref<{
  latitude: number;
  longitude: number;
  accuracy?: number;
} | null>(null);
let googleMap: any = null;
let storeMarker: any = null;
let userMarker: any = null;
let storeRadius: any = null;
let googleMapsLoadPromise: Promise<void> | null = null;
let advancedMarkerElementClass: any = null;

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
const selectedOutsource = ref<Outsource | null>(null);

const selectedOutsourceLabel = computed(() => {
  if (!selectedOutsource.value) return "";

  return `${selectedOutsource.value.name} (${selectedOutsource.value.outsource_code})`;
});

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
const maxGpsAccuracyMeters = Number(
  import.meta.env.VITE_GPS_MAX_ACCURACY_METERS ?? 100,
);

const isGpsAccuracyAcceptable = computed(
  () =>
    locationStatus.value === "ready" &&
    locationAccuracy.value !== null &&
    locationAccuracy.value <= maxGpsAccuracyMeters,
);

const now = ref(new Date());
let clockTimer: number | null = null;

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

const trackingStatusLabel = computed(() => {
  if (locationStatus.value === "locating") return "Mengambil lokasi";
  if (locationStatus.value === "ready" && !isGpsAccuracyAcceptable.value)
    return "GPS kurang akurat";
  if (locationStatus.value === "ready") return "Lokasi valid";
  if (locationStatus.value === "error") return "GPS error";
  return "Menunggu lokasi";
});

const trackerHint = computed(() => {
  if (locationStatus.value === "ready") return "Wilayah kerja terdeteksi aman.";
  if (locationStatus.value === "locating")
    return "Sedang membaca sinyal GPS Anda.";
  if (locationStatus.value === "error")
    return "Periksa izin GPS dan sinyal Anda.";
  return "Sinyal GPS belum aktif untuk validasi.";
});

const statusTitle = computed(() => {
  if (step.value === "completed") return "Presensi Selesai";
  if (isAttendanceOpen.value) return "Sedang Bertugas";
  if (isSessionActive.value) return "Siap Presensi Masuk";
  return "Inisiasi Sesi";
});

const selectedStoreLocation = computed(() => {
  if (selectedStore.value === null) return null;

  const store = stores.value.find((item) => item.id === selectedStore.value);
  if (!store) return null;

  const rawLatitude: unknown = store.latitude;
  const rawLongitude: unknown = store.longitude;

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
});

const selectedCityLocation = computed(() => {
  if (selectedCity.value === null) return null;

  const city = cities.value.find((item) => item.id === selectedCity.value);
  if (!city) return null;

  return cityFallbackCoordinates[city.name.trim().toUpperCase()] ?? null;
});

const selectedMapLocation = computed(
  () => selectedStoreLocation.value ?? selectedCityLocation.value,
);

const isUsingCityFallback = computed(
  () => !selectedStoreLocation.value && Boolean(selectedCityLocation.value),
);

const showStoreMap = computed(
  () => selectedStore.value !== null && step.value !== "completed",
);

const mapDistanceLabel = computed(() => {
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
  const distanceMeters =
    2 * earthRadius * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

  if (distanceMeters < 1000) {
    return `${Math.round(distanceMeters)} m dari toko`;
  }

  return `${(distanceMeters / 1000).toFixed(1)} km dari toko`;
});

const actionButtonLabel = computed(() => {
  if (isSubmitting.value) return "Memproses Presensi...";
  if (isAttendanceOpen.value) return "Clock Out Sekarang";
  return "Clock In Sekarang";
});

function formatCurrentDate(): string {
  return now.value.toLocaleDateString("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

function formatCurrentTime(): string {
  return now.value.toLocaleTimeString("id-ID", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
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
  if (!iso) return "--:--";
  const date = new Date(iso);
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function formatDate(iso: string | null): string {
  if (!iso) return "--";
  const date = new Date(iso);
  return date.toLocaleDateString("id-ID", {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function loadGoogleMapsScript(apiKey: string): Promise<void> {
  if (advancedMarkerElementClass) {
    return Promise.resolve();
  }

  if (googleMapsLoadPromise) {
    return googleMapsLoadPromise;
  }

  googleMapsLoadPromise = new Promise((resolve, reject) => {
    let loadingFinished = false;

    const finishLoading = async () => {
      if (loadingFinished) return;

      try {
        if (!window.google?.maps) {
          throw new Error("Google Maps API is unavailable.");
        }

        for (let attempt = 0; attempt < 20; attempt += 1) {
          if (window.google.maps.importLibrary) {
            const markerLibrary =
              await window.google.maps.importLibrary("marker");
            advancedMarkerElementClass = markerLibrary?.AdvancedMarkerElement;
          }

          advancedMarkerElementClass ??=
            window.google.maps.marker?.AdvancedMarkerElement;

          if (advancedMarkerElementClass) {
            loadingFinished = true;
            resolve();
            return;
          }

          await new Promise((wait) => window.setTimeout(wait, 50));
        }

        throw new Error("Google Maps marker library is unavailable.");
      } catch (error) {
        reject(error);
      }
    };

    const existingScript = document.querySelector(
      "script[data-google-maps-sdk]",
    ) as HTMLScriptElement | null;

    if (existingScript) {
      existingScript.addEventListener("load", finishLoading, { once: true });
      existingScript.addEventListener(
        "error",
        () => reject(new Error("Google Maps failed to load.")),
        { once: true },
      );
      if (window.google?.maps) {
        void finishLoading();
      }
      return;
    }

    const script = document.createElement("script");
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&loading=async&libraries=marker`;
    script.async = true;
    script.defer = true;
    script.dataset.googleMapsSdk = "true";
    script.onload = finishLoading;
    script.onerror = () => reject(new Error("Google Maps failed to load."));
    document.head.appendChild(script);
  });

  return googleMapsLoadPromise;
}

function updateGoogleMap(): void {
  if (
    !selectedMapLocation.value ||
    !mapContainer.value ||
    !window.google?.maps
  ) {
    return;
  }

  if (!googleMap) {
    googleMap = new window.google.maps.Map(mapContainer.value, {
      center: selectedMapLocation.value,
      zoom: 15,
      mapId: "DEMO_MAP_ID",
      disableDefaultUI: true,
      gestureHandling: "greedy",
      zoomControl: true,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
    });
  }

  googleMap.setCenter(selectedMapLocation.value);
  googleMap.panTo(selectedMapLocation.value);

  if (!storeMarker) {
    const storePin = document.createElement("div");
    storePin.style.width = "18px";
    storePin.style.height = "18px";
    storePin.style.border = "3px solid #fff";
    storePin.style.borderRadius = "50% 50% 50% 0";
    storePin.style.background = "#eb1c24";
    storePin.style.transform = "rotate(-45deg)";
    storeMarker = new advancedMarkerElementClass({
      map: googleMap,
      position: selectedMapLocation.value,
      title: isUsingCityFallback.value
        ? "Perkiraan pusat kota"
        : selectedStoreName.value || "Store",
      content: storePin,
    });
  } else {
    storeMarker.position = selectedMapLocation.value;
    storeMarker.title = isUsingCityFallback.value
      ? "Perkiraan pusat kota"
      : selectedStoreName.value || "Store";
  }

  if (!selectedStoreLocation.value) {
    if (storeRadius) {
      storeRadius.setMap(null);
      storeRadius = null;
    }
  } else if (!storeRadius) {
    storeRadius = new window.google.maps.Circle({
      map: googleMap,
      center: selectedStoreLocation.value,
      radius: 150,
      fillColor: "#f97316",
      fillOpacity: 0.18,
      strokeColor: "#f59e0b",
      strokeOpacity: 0.8,
      strokeWeight: 2,
    });
  } else {
    storeRadius.setCenter(selectedStoreLocation.value);
  }

  if (currentMapLocation.value) {
    const userPosition = {
      lat: currentMapLocation.value.latitude,
      lng: currentMapLocation.value.longitude,
    };

    if (!userMarker) {
      const userPin = document.createElement("div");
      userPin.style.width = "16px";
      userPin.style.height = "16px";
      userPin.style.border = "3px solid #fff";
      userPin.style.borderRadius = "50%";
      userPin.style.background = "#2563eb";
      userPin.style.boxShadow = "0 0 0 6px rgba(37, 99, 235, 0.18)";
      userMarker = new advancedMarkerElementClass({
        map: googleMap,
        position: userPosition,
        title: "Current location",
        content: userPin,
      });
    } else {
      userMarker.position = userPosition;
    }
  } else if (userMarker) {
    userMarker.setMap(null);
    userMarker = null;
  }
}

async function ensureGoogleMapsReady(): Promise<void> {
  if (!selectedMapLocation.value) {
    return;
  }

  const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;

  if (!apiKey) {
    mapError.value =
      "Google Maps is not configured yet. Add VITE_GOOGLE_MAPS_API_KEY to the frontend environment.";
    return;
  }

  try {
    await loadGoogleMapsScript(apiKey);
    if (!isUsingCityFallback.value) {
      mapError.value = "";
    }
    updateGoogleMap();
  } catch (error) {
    console.error("Google Maps initialization failed.", error);
    mapError.value =
      error instanceof Error
        ? `Google Maps failed to load: ${error.message}`
        : "Google Maps failed to load. Please try again later.";
  }
}

async function refreshMapLocation(): Promise<void> {
  const location = await requestLocation();
  if (!location) return;

  currentMapLocation.value = {
    latitude: location.latitude,
    longitude: location.longitude,
    accuracy: location.accuracy ?? undefined,
  };

  updateGoogleMap();
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
  sessionToken.value = null;
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
      error.value = "Gagal memuat daftar toko. Silakan coba lagi.";
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
        ? "Menampilkan perkiraan pusat kota. Ini bukan lokasi asli toko; presensi tetap menunggu koordinat toko."
        : "Lokasi toko dan koordinat kota belum tersedia. Silakan hubungi admin.";
    } else {
      mapError.value = "";
    }

    if (selectedMapLocation.value) {
      await Promise.all([ensureGoogleMapsReady(), refreshMapLocation()]);
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
    selectedStore.value = null;
    selectedStoreName.value = "";
    stores.value = [];
    selectedOutsource.value = null;
    outsources.value = [];
    sessionToken.value = null;
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
    selectedOutsource.value = null;
    outsources.value = [];
    sessionToken.value = null;
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

function onOutsourceSelected(): void {
  invalidateSessionState();
  if (selectedOutsource.value) {
    step.value = "outsource";
  }
}

async function startSession(): Promise<void> {
  if (!selectedStoreLocation.value) {
    error.value =
      "Koordinat lokasi toko belum tersedia. Presensi tidak dapat dilanjutkan sebelum admin mengatur lokasi toko.";
    return;
  }

  if (locationStatus.value !== "ready") {
    error.value =
      "Lokasi wajib diaktifkan untuk presensi. Izinkan akses lokasi lalu tekan Coba lagi.";
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

    sessionToken.value = response.data.session_token;
    expiresAt.value = response.data.expires_at;
    step.value = "session";
    message.value = `Sesi individu aktif untuk ${response.data.outsource.name}.`;
  } catch (e: unknown) {
    const err = e as Error;
    if (err instanceof ApiError) {
      if (err.status === 422) {
        error.value =
          "Pilihan penugasan tidak valid atau tidak aktif. Silakan pilih kembali.";
      } else if (err.status === 429) {
        error.value =
          "Terlalu banyak permintaan sesi. Mohon tunggu beberapa saat.";
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

async function requestLocation(): Promise<{
  latitude: number;
  longitude: number;
  accuracy?: number;
} | null> {
  if (!navigator.geolocation) {
    error.value = "Browser ini tidak mendukung layanan lokasi.";
    locationStatus.value = "error";
    return null;
  }

  locationStatus.value = "locating";

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        locationAccuracy.value = Math.round(position.coords.accuracy);
        locationStatus.value = "ready";
        error.value = "";
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        });
      },
      (geoErr) => {
        locationStatus.value = "error";
        if (geoErr.code === geoErr.PERMISSION_DENIED) {
          error.value =
            "Izin lokasi ditolak. Izinkan akses lokasi pada browser lalu coba lagi.";
        } else if (geoErr.code === geoErr.POSITION_UNAVAILABLE) {
          error.value =
            "Lokasi tidak tersedia. Pastikan GPS/lokasi perangkat aktif lalu coba lagi.";
        } else if (geoErr.code === geoErr.TIMEOUT) {
          error.value =
            "Pengambilan lokasi terlalu lama. Pastikan GPS/lokasi aktif lalu coba lagi.";
        } else {
          error.value =
            "Gagal mendeteksi lokasi GPS. Pastikan GPS aktif dan berada di area terbuka.";
        }
        resolve(null);
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  });
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
  if (!sessionToken.value) {
    error.value = "Sesi telah kedaluwarsa. Silakan inisiasi sesi kembali.";
    step.value = "city";
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

    const response: OutsourceAttendanceResponse = await (isAttendanceOpen.value
      ? outsourceCheckOut(
          sessionToken.value,
          locationData.latitude,
          locationData.longitude,
          locationData.accuracy,
        )
      : outsourceCheckIn(
          sessionToken.value,
          locationData.latitude,
          locationData.longitude,
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
        sessionToken.value = null;
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
            "Presensi gagal. Pastikan Anda berada di dalam area radius 150m toko penugasan.";
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
  }
}

function resetSelection(): void {
  invalidateSessionState();
  currentMapLocation.value = null;
  mapError.value = "";
  if (googleMap) {
    googleMap = null;
  }
  if (storeMarker) {
    storeMarker.setMap(null);
    storeMarker = null;
  }
  if (userMarker) {
    userMarker.setMap(null);
    userMarker = null;
  }
  if (storeRadius) {
    storeRadius.setMap(null);
    storeRadius = null;
  }
  selectedCity.value = null;
  selectedStore.value = null;
  selectedStoreName.value = "";
  selectedOutsource.value = null;
  stores.value = [];
  outsources.value = [];
  step.value = "city";
  loadCities();
}

onMounted(() => {
  clockTimer = window.setInterval(() => {
    now.value = new Date();
  }, 1000);

  loadCities();
});

onUnmounted(() => {
  if (clockTimer !== null) {
    clearInterval(clockTimer);
  }
});
</script>

<template>
  <main class="outsource-app">
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
      <h1 v-if="isSessionActive && selectedOutsource">
        Hi, {{ selectedOutsource.name.split(" ")[0] }}
      </h1>
      <h1 v-else>Presensi Outsource</h1>
      <p v-if="isSessionActive">
        Sesi individu aktif di
        <strong>{{ selectedStoreName || "Toko Penugasan" }}</strong
        >.
      </p>
      <p v-else>
        Pilih penugasan individu Anda dan validasi lokasi kerja Anda secara
        otomatis.
      </p>
    </section>

    <section v-if="error" class="app-banner banner-error" role="alert">
      <div class="banner-content">
        <AppIcon
          name="TriangleAlert"
          class="banner-icon"
          :size="18"
          :stroke-width="2.2"
          aria-hidden="true"
        />
        <p>{{ error }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="error = ''">
        <AppIcon name="X" :size="14" :stroke-width="2.5" aria-hidden="true" />
      </button>
    </section>

    <section v-if="message" class="app-banner banner-success" role="status">
      <div class="banner-content">
        <AppIcon
          name="CircleCheckBig"
          class="banner-icon"
          :size="18"
          :stroke-width="2.2"
          aria-hidden="true"
        />
        <p>{{ message }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="message = ''">
        <AppIcon name="X" :size="14" :stroke-width="2.5" aria-hidden="true" />
      </button>
    </section>

    <template v-if="!isSessionActive && step !== 'completed'">
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
          <span class="step-text">Toko</span>
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
                {{ city.code ? `${city.name} (${city.code})` : city.name }}
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
          {{ isLoading ? "Memuat Toko..." : "Lanjutkan ke Pilih Toko" }}
        </AppButton>
      </section>

      <section v-if="step === 'store'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="Building2" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Pilih Toko / Lokasi Kerja</h2>
            <p class="card-sub">
              Pilih toko tempat Anda bertugas di kota yang dipilih.
            </p>
          </div>
        </div>

        <div class="field-block">
          <label for="store-select">Toko / Outlet</label>
          <div class="select-wrapper">
            <select
              id="store-select"
              v-model="selectedStore"
              :disabled="isLoading"
              @change="onStoreSelected"
            >
              <option :value="null" disabled>-- Pilih toko / outlet --</option>
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
              Pilih nama Anda yang terdaftar pada penugasan toko ini.
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
                {{ outsource.name }} ({{ outsource.outsource_code }})
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
            Belum ada data pekerja outsource yang ditugaskan di toko ini.
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
                    ? "Mengambil lokasi..."
                    : locationStatus === "ready"
                      ? "GPS kurang akurat"
                      : "Lokasi wajib diaktifkan"
              }}
            </strong>
            <span>
              {{
                isGpsAccuracyAcceptable
                  ? `Akurasi GPS ±${locationAccuracy ?? 0} m`
                  : locationStatus === "ready"
                    ? `Akurasi ±${locationAccuracy ?? 0} m (maksimal ±${maxGpsAccuracyMeters} m)`
                    : "Aktifkan izin lokasi untuk melanjutkan presensi."
              }}
            </span>
          </div>
          <button
            v-if="!isGpsAccuracyAcceptable"
            type="button"
            class="location-retry-button"
            @click="refreshMapLocation"
          >
            Coba lagi
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
                : isGpsAccuracyAcceptable
                  ? "Mulai Sesi Presensi"
                  : locationStatus === "ready"
                    ? "Tunggu GPS lebih akurat"
                    : "Aktifkan Lokasi untuk Lanjut"
            }}
          </AppButton>
        </div>
      </section>

      <section v-if="showStoreMap" class="pwa-card map-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="MapPinned" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Lokasi Toko & Radius</h2>
            <p class="card-sub">
              Visualisasi toko dan area validasi 150 meter.
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
          class="google-map"
          aria-label="Google Map for selected store"
        />

        <div class="map-meta-row">
          <div class="map-meta-pill">
            <span>Store</span>
            <strong>{{ selectedStoreName || "Toko" }}</strong>
          </div>
          <div class="map-meta-pill">
            <span>Radius</span>
            <strong>{{
              isUsingCityFallback ? "Belum tersedia" : "150 m"
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

        <div class="time-grid">
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

        <div class="summary-row">
          <div class="summary-pill">
            <span class="mini-label">Lokasi</span>
            <strong>{{ selectedStoreName || "Menunggu penugasan" }}</strong>
          </div>
          <div class="summary-pill">
            <span class="mini-label">Akurasi GPS</span>
            <strong>{{
              locationAccuracy !== null
                ? `±${locationAccuracy} m`
                : "Belum terukur"
            }}</strong>
          </div>
        </div>

        <AppButton
          type="button"
          class="hero-action-button"
          variant="primary"
          icon="ArrowRight"
          :disabled="isSubmitting"
          @click="submitAttendance"
        >
          {{ actionButtonLabel }}
        </AppButton>
      </section>

      <section class="geo-tracker-card">
        <div class="geo-map">
          <div class="map-outer-ring" />
          <div class="map-inner-ring" />
          <div class="map-pin" />
          <div class="map-safe-zone" />
          <div class="map-tag">Safe Zone 150m</div>
        </div>

        <div class="geo-content">
          <div class="geo-header-row">
            <div>
              <p class="geo-label">Live Tracking</p>
              <h3>{{ trackingStatusLabel }}</h3>
            </div>
            <span
              class="status-indicator-badge"
              :class="{
                'badge-ready': locationStatus === 'ready',
                'badge-locating': locationStatus === 'locating',
                'badge-error': locationStatus === 'error',
              }"
            >
              {{
                locationStatus === "locating"
                  ? "Mencari..."
                  : locationStatus === "ready"
                    ? "Siap"
                    : "GPS"
              }}
            </span>
          </div>

          <p class="geo-hint">{{ trackerHint }}</p>

          <div class="geo-metrics">
            <div>
              <span>Radius</span>
              <strong>150 m</strong>
            </div>
            <div>
              <span>GPS</span>
              <strong>{{
                locationAccuracy !== null
                  ? `±${locationAccuracy} m`
                  : "Belum ada"
              }}</strong>
            </div>
            <div>
              <span>Jam</span>
              <strong>{{ formatCurrentTime() }}</strong>
            </div>
          </div>
        </div>
      </section>

      <section class="mini-summary-grid">
        <div class="mini-summary-item">
          <span>Penugasan</span>
          <strong>{{ selectedStoreName || "Belum dipilih" }}</strong>
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
          <span class="info-title">Toko</span>
          <strong>{{ selectedStoreName }}</strong>
          <small>Radius validasi 150 m</small>
        </div>
        <div class="info-card">
          <span class="info-title">Sesi</span>
          <strong>{{ expiresAt ? formatDate(expiresAt) : "Aktif" }}</strong>
          <small>{{ expiresAt ? "Kedaluwarsa sesi" : "Belum diatur" }}</small>
        </div>
      </section>

      <section class="session-details-card">
        <div class="detail-header-row">
          <h3>Detail sesi</h3>
          <span class="detail-pill">{{
            isAttendanceOpen ? "Bertugas" : "Siap hadir"
          }}</span>
        </div>

        <p v-if="selectedOutsourceLabel" class="session-person">
          {{ selectedOutsourceLabel }}
        </p>

        <div class="session-row">
          <span class="label">Lokasi Toko</span>
          <span class="value">{{ selectedStoreName }}</span>
        </div>
        <div class="session-row">
          <span class="label">Status Absensi</span>
          <span class="value">{{
            isAttendanceOpen
              ? "Clock In sedang aktif"
              : "Sesi belum di-clock in"
          }}</span>
        </div>
        <div v-if="expiresAt" class="session-row">
          <span class="label">Kedaluwarsa Sesi</span>
          <span class="value text-muted">{{ formatDate(expiresAt) }}</span>
        </div>

        <AppButton
          type="button"
          class="btn-link-reset"
          variant="ghost"
          icon="ArrowRight"
          @click="resetSelection"
        >
          Ganti Personel / Akhiri Sesi
        </AppButton>
      </section>
    </template>

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
  </main>
</template>

<style scoped>
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

/* App Notification Banners */
.app-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  border-radius: 10px;
  margin-bottom: 1.25rem;
  font-size: 0.85rem;
}

.map-card {
  margin-top: 1rem;
}

.google-map {
  width: 100%;
  height: 220px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  background: linear-gradient(135deg, #e2e8f0, #dbeafe);
  overflow: hidden;
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

.banner-content {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex: 1;
}

.banner-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
}

.banner-success {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #15803d;
}

.banner-dismiss {
  border: none;
  background: transparent;
  color: inherit;
  font-size: 0.9rem;
  cursor: pointer;
  padding: 0 0.25rem;
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
.banner-dismiss:focus-visible,
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
  font-size: 1.3rem;
  font-family: var(--mono);
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

.geo-tracker-card {
  display: grid;
  grid-template-columns: 140px minmax(0, 1fr);
  gap: 1rem;
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid var(--border);
  background: linear-gradient(135deg, #fff, #f9f9fb);
  box-shadow: 0 10px 18px rgba(17, 17, 17, 0.02);
  margin-bottom: 1rem;
}

.geo-tracker-card .geo-content {
  gap: 0.7rem;
}

.geo-map {
  position: relative;
  height: 120px;
  border-radius: 18px;
  overflow: hidden;
  background: linear-gradient(180deg, #f4f4f5 0%, #e8e8ec 100%);
  border: 1px solid rgba(17, 17, 17, 0.05);
}

.map-outer-ring,
.map-inner-ring,
.map-safe-zone,
.map-pin {
  position: absolute;
  border-radius: 50%;
}

.map-outer-ring {
  inset: 18px;
  border: 1.5px solid rgba(235, 28, 36, 0.2);
}

.map-inner-ring {
  inset: 34px;
  border: 1.5px solid rgba(235, 28, 36, 0.3);
}

.map-safe-zone {
  width: 54px;
  height: 54px;
  top: 28px;
  left: 28px;
  background: rgba(23, 183, 92, 0.14);
  border: 1px solid rgba(23, 183, 92, 0.3);
}

.map-pin {
  width: 14px;
  height: 14px;
  top: 54px;
  left: 62px;
  background: var(--accent);
  border: 3px solid #fff;
  box-shadow: 0 0 0 6px rgba(235, 28, 36, 0.1);
}

.map-tag {
  position: absolute;
  right: 10px;
  bottom: 10px;
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  background: rgba(17, 17, 17, 0.7);
  color: #fff;
  font-size: 0.58rem;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.geo-content {
  display: grid;
  gap: 0.8rem;
  align-content: center;
}

.geo-header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.geo-label {
  margin: 0 0 0.2rem;
  color: var(--text);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.geo-content h3 {
  margin: 0;
  font-size: 1rem;
  color: var(--text-h);
}

.geo-hint {
  margin: 0;
  color: var(--text);
  font-size: 0.78rem;
  line-height: 1.5;
}

.geo-metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.6rem;
}

.geo-metrics div {
  display: grid;
  gap: 0.12rem;
  padding-top: 0.5rem;
  border-top: 1px solid #ececf0;
}

.geo-metrics span {
  color: var(--text);
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.geo-metrics strong {
  color: var(--text-h);
  font-size: 0.72rem;
}

.status-indicator-badge {
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  background: #f4f4f5;
  color: #71717a;
  white-space: nowrap;
}

.status-indicator-badge.badge-ready {
  background: #dcfce7;
  color: #15803d;
}

.status-indicator-badge.badge-locating {
  background: #fef9c3;
  color: #a16207;
}

.status-indicator-badge.badge-error {
  background: #fee2e2;
  color: #b91c1c;
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
