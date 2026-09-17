<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { ApiError } from '../services/apiClient'
import {
  fetchOutsourceCities,
  fetchOutsourceStores,
  fetchOutsourceOutsources,
  initOutsourceSession,
  outsourceCheckIn,
  outsourceCheckOut,
  getStoredOutsourceSession,
  saveStoredOutsourceSession,
  clearStoredOutsourceSession,
  type City,
  type Store,
  type Outsource,
  type OutsourceAttendanceResponse,
  type StoredOutsourceSession,
} from '../services/outsourceService'

type Step = 'city' | 'store' | 'outsource' | 'session' | 'attendance_open' | 'completed'

const step = ref<Step>('city')
const sessionToken = ref<string | null>(null)
const expiresAt = ref<string | null>(null)

const cities = ref<City[]>([])
const stores = ref<Store[]>([])
const outsources = ref<Outsource[]>([])

const selectedCity = ref<number | null>(null)
const selectedStore = ref<number | null>(null)
const selectedStoreName = ref<string>('')
const selectedOutsource = ref<Outsource | null>(null)

const attendanceId = ref<number | null>(null)
const attendanceStatus = ref<string | null>(null)
const attendanceDate = ref<string | null>(null)
const checkInAt = ref<string | null>(null)
const checkOutAt = ref<string | null>(null)
const durationMinutes = ref<number | null>(null)

const isLoading = ref(false)
const isSubmitting = ref(false)
const error = ref('')
const message = ref('')

const locationAccuracy = ref<number | null>(null)
const locationStatus = ref<'idle' | 'locating' | 'ready' | 'error'>('idle')

const now = ref(new Date())
let clockTimer: number | null = null

const isSessionActive = computed(() => step.value === 'session' || step.value === 'attendance_open')
const isAttendanceOpen = computed(() => step.value === 'attendance_open')

const trackingStatusLabel = computed(() => {
  if (locationStatus.value === 'locating') return 'Mengambil lokasi'
  if (locationStatus.value === 'ready') return 'Lokasi valid'
  if (locationStatus.value === 'error') return 'GPS error'
  return 'Menunggu lokasi'
})

const trackerHint = computed(() => {
  if (locationStatus.value === 'ready') return 'Wilayah kerja terdeteksi aman.'
  if (locationStatus.value === 'locating') return 'Sedang membaca sinyal GPS Anda.'
  if (locationStatus.value === 'error') return 'Periksa izin GPS dan sinyal Anda.'
  return 'Sinyal GPS belum aktif untuk validasi.'
})

const statusTitle = computed(() => {
  if (step.value === 'completed') return 'Presensi Selesai'
  if (isAttendanceOpen.value) return 'Sedang Bertugas'
  if (isSessionActive.value) return 'Siap Presensi Masuk'
  return 'Inisiasi Sesi'
})

const actionButtonLabel = computed(() => {
  if (isSubmitting.value) return 'Memproses Presensi...'
  if (isAttendanceOpen.value) return 'Clock Out Sekarang'
  return 'Clock In Sekarang'
})

function formatCurrentDate(): string {
  return now.value.toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

function formatCurrentTime(): string {
  return now.value.toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

function formatTime(iso: string | null): string {
  if (!iso) return '--:--'
  const date = new Date(iso)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatDate(iso: string | null): string {
  if (!iso) return '--'
  const date = new Date(iso)
  return date.toLocaleDateString('id-ID', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

async function loadCities(): Promise<void> {
  isLoading.value = true
  error.value = ''
  try {
    cities.value = await fetchOutsourceCities()
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'Gagal memuat daftar kota. Silakan coba lagi.'
    }
  } finally {
    isLoading.value = false
  }
}

async function onCitySelected(): Promise<void> {
  if (selectedCity.value === null) return

  isLoading.value = true
  error.value = ''
  stores.value = []
  selectedStore.value = null
  selectedStoreName.value = ''
  outsources.value = []
  selectedOutsource.value = null

  try {
    stores.value = await fetchOutsourceStores(selectedCity.value)
    step.value = 'store'
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'Gagal memuat daftar toko. Silakan coba lagi.'
    }
  } finally {
    isLoading.value = false
  }
}

async function onStoreSelected(): Promise<void> {
  if (selectedStore.value === null) return

  const foundStore = Array.isArray(stores.value)
    ? stores.value.find((s) => s.id === selectedStore.value)
    : undefined
  if (foundStore) {
    selectedStoreName.value = foundStore.name
  }

  isLoading.value = true
  error.value = ''
  outsources.value = []
  selectedOutsource.value = null

  try {
    outsources.value = await fetchOutsourceOutsources(selectedStore.value)
    step.value = 'outsource'
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'Gagal memuat daftar personel outsource. Silakan coba lagi.'
    }
  } finally {
    isLoading.value = false
  }
}

async function startSession(): Promise<void> {
  if (selectedCity.value === null || selectedStore.value === null || selectedOutsource.value === null) {
    error.value = 'Harap lengkapi semua pilihan data penugasan.'
    return
  }

  isSubmitting.value = true
  error.value = ''
  message.value = ''

  try {
    const response = await initOutsourceSession(
      selectedCity.value,
      selectedStore.value,
      selectedOutsource.value.id,
    )

    sessionToken.value = response.data.session_token
    expiresAt.value = response.data.expires_at
    step.value = 'session'

    // Persist individual session to local storage for mobile continuity
    const sessionToSave: StoredOutsourceSession = {
      session_token: response.data.session_token,
      expires_at: response.data.expires_at,
      outsource: response.data.outsource,
      store: response.data.store,
      status: 'session',
    }
    saveStoredOutsourceSession(sessionToSave)
    message.value = `Sesi individu aktif untuk ${response.data.outsource.name}.`
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      if (err.status === 422) {
        error.value = 'Pilihan penugasan tidak valid atau tidak aktif. Silakan pilih kembali.'
      } else if (err.status === 429) {
        error.value = 'Terlalu banyak permintaan sesi. Mohon tunggu beberapa saat.'
      } else {
        error.value = err.message ?? 'Gagal memulai sesi presensi.'
      }
    } else {
      error.value = 'Koneksi bermasalah. Periksa jaringan internet Anda.'
    }
  } finally {
    isSubmitting.value = false
  }
}

async function requestLocation(): Promise<{ latitude: number; longitude: number; accuracy?: number } | null> {
  if (!navigator.geolocation) {
    error.value = 'Fitur Geolocation tidak didukung oleh browser Anda.'
    locationStatus.value = 'error'
    return null
  }

  locationStatus.value = 'locating'

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        locationAccuracy.value = Math.round(position.coords.accuracy)
        locationStatus.value = 'ready'
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        })
      },
      (geoErr) => {
        locationStatus.value = 'error'
        if (geoErr.code === geoErr.PERMISSION_DENIED) {
          error.value = 'Izin lokasi (GPS) ditolak. Mohon aktifkan izin lokasi di browser ponsel Anda.'
        } else {
          error.value = 'Gagal mendeteksi lokasi GPS. Pastikan GPS aktif dan berada di area terbuka.'
        }
        resolve(null)
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  })
}

async function submitAttendance(): Promise<void> {
  if (!sessionToken.value) {
    error.value = 'Sesi telah kedaluwarsa. Silakan inisiasi sesi kembali.'
    step.value = 'city'
    return
  }

  const locationData = await requestLocation()
  if (!locationData) return

  isSubmitting.value = true
  error.value = ''
  message.value = ''

  try {
    const response: OutsourceAttendanceResponse = await (isAttendanceOpen.value
      ? outsourceCheckOut(sessionToken.value, locationData.latitude, locationData.longitude, locationData.accuracy)
      : outsourceCheckIn(sessionToken.value, locationData.latitude, locationData.longitude, locationData.accuracy))

    if (response.success) {
      attendanceId.value = response.data.attendance_id
      attendanceStatus.value = response.data.status
      attendanceDate.value = response.data.attendance_date
      checkInAt.value = response.data.check_in_at
      checkOutAt.value = response.data.check_out_at
      durationMinutes.value = response.data.duration_minutes

      if (isAttendanceOpen.value) {
        step.value = 'completed'
        message.value = 'Presensi Clock-Out berhasil dicatat. Tugas hari ini selesai!'
        clearStoredOutsourceSession()
      } else {
        step.value = 'attendance_open'
        message.value = 'Presensi Clock-In berhasil dicatat! Selamat bertugas.'

        if (selectedOutsource.value && selectedStore.value) {
          saveStoredOutsourceSession({
            session_token: sessionToken.value,
            expires_at: expiresAt.value ?? '',
            outsource: selectedOutsource.value,
            store: { id: selectedStore.value, name: selectedStoreName.value, city_id: selectedCity.value ?? 0 },
            attendance_id: response.data.attendance_id,
            check_in_at: response.data.check_in_at,
            status: 'attendance_open',
          })
        }
      }
    }
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      switch (err.status) {
        case 401:
          error.value = 'Sesi presensi telah berakhir. Silakan pilih kembali penugasan Anda.'
          clearStoredOutsourceSession()
          resetSelection()
          break
        case 422:
          error.value = err.message ?? 'Presensi gagal. Pastikan Anda berada di dalam area radius 150m toko penugasan.'
          break
        case 429:
          error.value = 'Terlalu banyak percobaan. Harap tunggu beberapa saat.'
          break
        default:
          error.value = err.message ?? 'Terjadi kesalahan sistem. Silakan coba lagi.'
      }
    } else {
      error.value = 'Koneksi bermasalah. Periksa jaringan internet ponsel Anda.'
    }
  } finally {
    isSubmitting.value = false
  }
}

function restoreExistingSession(): boolean {
  const saved = getStoredOutsourceSession()
  if (saved && saved.session_token) {
    sessionToken.value = saved.session_token
    expiresAt.value = saved.expires_at
    selectedOutsource.value = saved.outsource
    selectedStore.value = saved.store.id
    selectedStoreName.value = saved.store.name
    selectedCity.value = saved.store.city_id

    if (saved.check_in_at) {
      checkInAt.value = saved.check_in_at
      step.value = 'attendance_open'
    } else {
      step.value = 'session'
    }
    return true
  }
  return false
}

function resetSelection(): void {
  clearStoredOutsourceSession()
  sessionToken.value = null
  expiresAt.value = null
  selectedCity.value = null
  selectedStore.value = null
  selectedStoreName.value = ''
  selectedOutsource.value = null
  stores.value = []
  outsources.value = []
  attendanceId.value = null
  attendanceStatus.value = null
  attendanceDate.value = null
  checkInAt.value = null
  checkOutAt.value = null
  durationMinutes.value = null
  locationAccuracy.value = null
  locationStatus.value = 'idle'
  error.value = ''
  message.value = ''
  step.value = 'city'
  loadCities()
}

onMounted(() => {
  clockTimer = window.setInterval(() => {
    now.value = new Date()
  }, 1000)

  const restored = restoreExistingSession()
  if (!restored) {
    loadCities()
  }
})

onUnmounted(() => {
  if (clockTimer !== null) {
    clearInterval(clockTimer)
  }
})
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
        <div v-if="isSessionActive && selectedOutsource" class="user-badge" :title="selectedOutsource.name">
          <span>{{ selectedOutsource.name.charAt(0).toUpperCase() }}</span>
        </div>
        <div v-else class="kiosk-badge" title="Sesi Individu">
          <AppIcon name="Building2" class="badge-icon" :size="18" :stroke-width="2" aria-hidden="true" />
        </div>
      </div>
    </header>

    <section class="welcome-block">
      <div class="date-row">
        <p class="welcome-kicker">{{ formatCurrentDate() }}</p>
        <span class="clock-live">{{ formatCurrentTime() }}</span>
      </div>
      <h1 v-if="isSessionActive && selectedOutsource">
        Hi, {{ selectedOutsource.name.split(' ')[0] }}
      </h1>
      <h1 v-else>
        Presensi Outsource
      </h1>
      <p v-if="isSessionActive">
        Sesi individu aktif di <strong>{{ selectedStoreName || 'Toko Penugasan' }}</strong>.
      </p>
      <p v-else>
        Pilih penugasan individu Anda dan validasi lokasi kerja Anda secara otomatis.
      </p>
    </section>

    <section v-if="error" class="app-banner banner-error" role="alert">
      <div class="banner-content">
        <AppIcon name="TriangleAlert" class="banner-icon" :size="18" :stroke-width="2.2" aria-hidden="true" />
        <p>{{ error }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="error = ''">
        <AppIcon name="X" :size="14" :stroke-width="2.5" aria-hidden="true" />
      </button>
    </section>

    <section v-if="message" class="app-banner banner-success" role="status">
      <div class="banner-content">
        <AppIcon name="CircleCheckBig" class="banner-icon" :size="18" :stroke-width="2.2" aria-hidden="true" />
        <p>{{ message }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="message = ''">
        <AppIcon name="X" :size="14" :stroke-width="2.5" aria-hidden="true" />
      </button>
    </section>

    <template v-if="!isSessionActive && step !== 'completed'">
      <div class="wizard-stepper" aria-label="Langkah Inisiasi Sesi">
        <div class="step-item" :class="{ active: step === 'city', done: step === 'store' || step === 'outsource' }">
          <span class="step-num">1</span>
          <span class="step-text">Kota</span>
        </div>
        <div class="step-divider" />
        <div class="step-item" :class="{ active: step === 'store', done: step === 'outsource' }">
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
            <p class="card-sub">Tentukan wilayah operasional kerja Anda hari ini.</p>
          </div>
        </div>

        <div class="field-block">
          <label for="city-select">Wilayah Kota</label>
          <div class="select-wrapper">
            <select id="city-select" v-model="selectedCity" :disabled="isLoading" @change="onCitySelected">
              <option :value="null" disabled>-- Pilih kota penempatan --</option>
              <option v-for="city in cities" :key="city.id" :value="city.id">
                {{ city.name }} ({{ city.code }})
              </option>
            </select>
            <AppIcon name="ChevronDown" class="select-chevron" :size="16" :stroke-width="2.3" aria-hidden="true" />
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
          {{ isLoading ? 'Memuat Toko...' : 'Lanjutkan ke Pilih Toko' }}
        </AppButton>
      </section>

      <section v-if="step === 'store'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill" aria-hidden="true">
            <AppIcon name="Building2" :size="18" :stroke-width="2" />
          </span>
          <div>
            <h2>Pilih Toko / Lokasi Kerja</h2>
            <p class="card-sub">Pilih toko tempat Anda bertugas di kota yang dipilih.</p>
          </div>
        </div>

        <div class="field-block">
          <label for="store-select">Toko / Outlet</label>
          <div class="select-wrapper">
            <select id="store-select" v-model="selectedStore" :disabled="isLoading" @change="onStoreSelected">
              <option :value="null" disabled>-- Pilih toko / outlet --</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">
                {{ store.name }}
              </option>
            </select>
            <AppIcon name="ChevronDown" class="select-chevron" :size="16" :stroke-width="2.3" aria-hidden="true" />
          </div>
        </div>

        <div class="button-group">
          <AppButton type="button" class="btn-secondary" variant="secondary" icon="ArrowLeft" icon-position="left" @click="step = 'city'">
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
            {{ isLoading ? 'Memuat Personel...' : 'Lanjutkan ke Profil' }}
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
            <p class="card-sub">Pilih nama Anda yang terdaftar pada penugasan toko ini.</p>
          </div>
        </div>

        <div class="field-block">
          <label for="outsource-select">Nama Personel Outsource</label>
          <div class="select-wrapper">
            <select id="outsource-select" v-model="selectedOutsource" :disabled="isLoading || outsources.length === 0">
              <option :value="null" disabled>-- Pilih nama Anda --</option>
              <option v-for="outsource in outsources" :key="outsource.id" :value="outsource">
                {{ outsource.name }} ({{ outsource.outsource_code }})
              </option>
            </select>
            <AppIcon name="ChevronDown" class="select-chevron" :size="16" :stroke-width="2.3" aria-hidden="true" />
          </div>
          <p v-if="outsources.length === 0 && !isLoading" class="hint-empty">
            Belum ada data pekerja outsource yang ditugaskan di toko ini.
          </p>
        </div>

        <div class="button-group">
          <AppButton type="button" class="btn-secondary" variant="secondary" icon="ArrowLeft" icon-position="left" @click="step = 'store'">
            Kembali
          </AppButton>
          <AppButton
            type="button"
            class="btn-primary"
            variant="primary"
            icon="ArrowRight"
            :disabled="!selectedOutsource || isSubmitting"
            @click="startSession"
          >
            {{ isSubmitting ? 'Menginisiasi Sesi...' : 'Mulai Sesi Presensi' }}
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
          <span class="status-pulse-dot" :class="{ active: isAttendanceOpen }" :title="isAttendanceOpen ? 'Sesi Terbuka' : 'Sesi Aktif'" />
        </div>

        <div class="time-grid">
          <div class="time-item">
            <span>Check In</span>
            <strong>{{ formatTime(checkInAt) }}</strong>
          </div>
          <div class="time-item">
            <span>Check Out</span>
            <strong>{{ formatTime(checkOutAt) }}</strong>
          </div>
        </div>

        <div class="summary-row">
          <div class="summary-pill">
            <span class="mini-label">Lokasi</span>
            <strong>{{ selectedStoreName || 'Menunggu penugasan' }}</strong>
          </div>
          <div class="summary-pill">
            <span class="mini-label">Akurasi GPS</span>
            <strong>{{ locationAccuracy !== null ? `±${locationAccuracy} m` : 'Belum terukur' }}</strong>
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
            <span class="status-indicator-badge" :class="{
              'badge-ready': locationStatus === 'ready',
              'badge-locating': locationStatus === 'locating',
              'badge-error': locationStatus === 'error',
            }">
              {{ locationStatus === 'locating' ? 'Mencari...' : (locationStatus === 'ready' ? 'Siap' : 'GPS') }}
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
              <strong>{{ locationAccuracy !== null ? `±${locationAccuracy} m` : 'Belum ada' }}</strong>
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
          <strong>{{ selectedStoreName || 'Belum dipilih' }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Waktu</span>
          <strong>{{ formatCurrentTime() }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>GPS</span>
          <strong>{{ locationAccuracy !== null ? `±${locationAccuracy} m` : 'Belum ada' }}</strong>
        </div>
        <div class="mini-summary-item">
          <span>Status</span>
          <strong>{{ isAttendanceOpen ? 'Clock In aktif' : 'Siap hadir' }}</strong>
        </div>
      </section>

      <section class="info-grid">
        <div class="info-card">
          <span class="info-title">Personel</span>
          <strong>{{ selectedOutsource?.name }}</strong>
          <small>{{ selectedOutsource?.outsource_code }}</small>
        </div>
        <div class="info-card">
          <span class="info-title">Toko</span>
          <strong>{{ selectedStoreName }}</strong>
          <small>Radius validasi 150 m</small>
        </div>
        <div class="info-card">
          <span class="info-title">Sesi</span>
          <strong>{{ expiresAt ? formatDate(expiresAt) : 'Aktif' }}</strong>
          <small>{{ expiresAt ? 'Kedaluwarsa sesi' : 'Belum diatur' }}</small>
        </div>
      </section>

      <section class="session-details-card">
        <div class="detail-header-row">
          <h3>Detail sesi</h3>
          <span class="detail-pill">{{ isAttendanceOpen ? 'Bertugas' : 'Siap hadir' }}</span>
        </div>

        <div class="session-row">
          <span class="label">Personel</span>
          <strong class="value">{{ selectedOutsource?.name }} ({{ selectedOutsource?.outsource_code }})</strong>
        </div>
        <div class="session-row">
          <span class="label">Lokasi Toko</span>
          <span class="value">{{ selectedStoreName }}</span>
        </div>
        <div class="session-row">
          <span class="label">Status Absensi</span>
          <span class="value">{{ isAttendanceOpen ? 'Clock In sedang aktif' : 'Sesi belum di-clock in' }}</span>
        </div>
        <div v-if="expiresAt" class="session-row">
          <span class="label">Kedaluwarsa Sesi</span>
          <span class="value text-muted">{{ formatDate(expiresAt) }}</span>
        </div>

        <AppButton type="button" class="btn-link-reset" variant="ghost" icon="ArrowRight" @click="resetSelection">
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
        <p class="completed-sub">Terima kasih atas kerja keras Anda hari ini!</p>

        <div class="summary-grid">
          <div class="summary-item">
            <span>Tanggal</span>
            <strong>{{ formatDate(attendanceDate ?? checkInAt) }}</strong>
          </div>
          <div class="summary-item">
            <span>Personel</span>
            <strong>{{ selectedOutsource?.name }}</strong>
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
              {{ Math.floor(durationMinutes / 60) }} Jam {{ durationMinutes % 60 }} Menit
            </strong>
          </div>
        </div>

        <AppButton type="button" class="btn-primary" variant="primary" icon="CircleCheckBig" @click="resetSelection">
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
  appearance: none;
  padding: 0.75rem 2.2rem 0.75rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 9px;
  background: #fff;
  color: var(--text-h);
  font-size: 0.92rem;
  outline: none;
  transition: border-color 0.2s;
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

/* Button System */
.button-group {
  display: flex;
  gap: 0.75rem;
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
  transition: transform 0.15s ease, box-shadow 0.2s ease, opacity 0.2s ease;
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
  transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}

.btn-secondary:hover {
  background: #f4f4f5;
  border-color: rgba(235, 28, 36, 0.2);
  transform: translateY(-1px);
}

/* HERO ATTENDANCE CARD (Employee PWA Concept) */
.hero-attendance-card {
  padding: 1.35rem;
  border-radius: 14px;
  background: var(--accent);
  color: #fff;
  box-shadow: 0 16px 32px rgba(235, 28, 36, 0.25);
  margin-bottom: 1.25rem;
}

.card-header-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
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
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.5);
  transition: all 0.3s;
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
  margin: 1.25rem 0;
  padding-top: 1rem;
  border-top: 1px solid rgba(255, 255, 255, 0.25);
}

.time-item {
  display: grid;
  gap: 0.25rem;
}

.time-item span {
  color: rgba(255, 255, 255, 0.75);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.time-item strong {
  font-size: 1.35rem;
  font-family: var(--mono);
}

.hero-action-button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0.9rem 1.15rem;
  border-radius: 9px;
  border: none;
  background: #fff;
  color: var(--accent);
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 18px rgba(17, 17, 17, 0.08);
  transition: transform 0.15s ease, box-shadow 0.2s ease, opacity 0.2s ease;
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
  grid-template-columns: repeat(3, minmax(0, 1fr));
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
  display: flex;
  align-items: center;
  justify-content: space-between;
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
  font-weight: 600;
  color: var(--text-h);
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
  margin: 1.1rem 0 1rem;
}

.summary-pill {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.18);
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
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto 1rem;
  place-items: center;
  border-radius: 50%;
  background: #dcfce7;
  color: #15803d;
  font-size: 1.8rem;
  font-weight: 700;
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
  border-radius: 10px;
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
  font-size: 0.72rem;
  color: var(--text);
  text-transform: uppercase;
}

.summary-item strong {
  font-size: 0.95rem;
  color: var(--text-h);
}

.highlight-duration {
  color: var(--accent);
  font-size: 1.1rem !important;
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
</style>
