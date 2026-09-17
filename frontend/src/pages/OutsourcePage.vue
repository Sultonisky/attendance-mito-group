<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
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
    <!-- PWA Top Header -->
    <header class="app-header">
      <div class="brand-lockup">
        <img class="brand-logo" src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p class="brand-eyebrow">MITO GROUP</p>
          <strong>Outsource Attendance</strong>
        </div>
      </div>

      <div v-if="isSessionActive && selectedOutsource" class="user-badge" :title="selectedOutsource.name">
        <span>{{ selectedOutsource.name.charAt(0).toUpperCase() }}</span>
      </div>
      <div v-else class="kiosk-badge" title="Sesi Individu">
        <span class="badge-icon">⚡</span>
      </div>
    </header>

    <!-- Welcome & Realtime Digital Clock Block -->
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
        Pilih penugasan individu Anda untuk memulai presensi kerja.
      </p>
    </section>

    <!-- Alerts / Notifications -->
    <section v-if="error" class="app-banner banner-error" role="alert">
      <div class="banner-content">
        <span class="banner-icon">⚠️</span>
        <p>{{ error }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="error = ''">✕</button>
    </section>

    <section v-if="message" class="app-banner banner-success" role="status">
      <div class="banner-content">
        <span class="banner-icon">✓</span>
        <p>{{ message }}</p>
      </div>
      <button type="button" class="banner-dismiss" @click="message = ''">✕</button>
    </section>

    <!-- STEP WIZARD (When No Session Active) -->
    <template v-if="!isSessionActive && step !== 'completed'">
      <!-- Wizard Progress Indicators -->
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

      <!-- Step 1: Select City -->
      <section v-if="step === 'city'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill">📍</span>
          <div>
            <h2>Pilih Kota Penempatan</h2>
            <p class="card-sub">Tentukan wilayah operasional kerja Anda hari ini.</p>
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
              <option :value="null" disabled>-- Pilih kota penempatan --</option>
              <option v-for="city in cities" :key="city.id" :value="city.id">
                {{ city.name }} ({{ city.code }})
              </option>
            </select>
            <span class="select-chevron">▾</span>
          </div>
        </div>

        <button
          type="button"
          class="btn-primary"
          :disabled="selectedCity === null || isLoading"
          @click="onCitySelected"
        >
          <span>{{ isLoading ? 'Memuat Toko...' : 'Lanjutkan ke Pilih Toko' }}</span>
          <span aria-hidden="true">→</span>
        </button>
      </section>

      <!-- Step 2: Select Store -->
      <section v-if="step === 'store'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill">🏪</span>
          <div>
            <h2>Pilih Toko / Lokasi Kerja</h2>
            <p class="card-sub">Pilih toko tempat Anda bertugas di kota yang dipilih.</p>
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
            <span class="select-chevron">▾</span>
          </div>
        </div>

        <div class="button-group">
          <button type="button" class="btn-secondary" @click="step = 'city'">
            ← Kembali
          </button>
          <button
            type="button"
            class="btn-primary"
            :disabled="selectedStore === null || isLoading"
            @click="onStoreSelected"
          >
            <span>{{ isLoading ? 'Memuat Personel...' : 'Lanjutkan ke Profil' }}</span>
            <span aria-hidden="true">→</span>
          </button>
        </div>
      </section>

      <!-- Step 3: Select Outsource Worker -->
      <section v-if="step === 'outsource'" class="pwa-card">
        <div class="card-title-row">
          <span class="card-icon-pill">👤</span>
          <div>
            <h2>Pilih Profil Personel</h2>
            <p class="card-sub">Pilih nama Anda yang terdaftar pada penugasan toko ini.</p>
          </div>
        </div>

        <div class="field-block">
          <label for="outsource-select">Nama Personel Outsource</label>
          <div class="select-wrapper">
            <select
              id="outsource-select"
              v-model="selectedOutsource"
              :disabled="isLoading || outsources.length === 0"
            >
              <option :value="null" disabled>-- Pilih nama Anda --</option>
              <option v-for="outsource in outsources" :key="outsource.id" :value="outsource">
                {{ outsource.name }} ({{ outsource.outsource_code }})
              </option>
            </select>
            <span class="select-chevron">▾</span>
          </div>
          <p v-if="outsources.length === 0 && !isLoading" class="hint-empty">
            Belum ada data pekerja outsource yang ditugaskan di toko ini.
          </p>
        </div>

        <div class="button-group">
          <button type="button" class="btn-secondary" @click="step = 'store'">
            ← Kembali
          </button>
          <button
            type="button"
            class="btn-primary"
            :disabled="!selectedOutsource || isSubmitting"
            @click="startSession"
          >
            <span>{{ isSubmitting ? 'Menginisiasi Sesi...' : 'Mulai Sesi Presensi' }}</span>
            <span aria-hidden="true">⚡</span>
          </button>
        </div>
      </section>
    </template>

    <!-- SESSION ACTIVE: HERO PWA ATTENDANCE CARD -->
    <template v-if="isSessionActive">
      <!-- MITO Signature Red Hero Attendance Card -->
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
            <span>Check Out</span>
            <strong>{{ formatTime(checkOutAt) }}</strong>
          </div>
        </div>

        <!-- Big Primary Action Button inside Hero Card -->
        <button
          type="button"
          class="hero-action-button"
          :disabled="isSubmitting"
          @click="submitAttendance"
        >
          <span>{{ actionButtonLabel }}</span>
          <span aria-hidden="true">→</span>
        </button>
      </section>

      <!-- GPS Geofence & Location Status Card -->
      <section class="quick-status-card">
        <div class="quick-icon-pill">
          <span>📡</span>
        </div>
        <div class="quick-details">
          <strong>Validasi Lokasi (PostGIS Geofence 150m)</strong>
          <small v-if="locationAccuracy !== null">
            Akurasi GPS terdeteksi: ±{{ locationAccuracy }} meter
          </small>
          <small v-else>
            GPS akan diverifikasi otomatis saat tombol Clock In/Out ditekan.
          </small>
        </div>
        <span
          class="status-indicator-badge"
          :class="{
            'badge-ready': locationStatus === 'ready',
            'badge-locating': locationStatus === 'locating',
            'badge-error': locationStatus === 'error',
          }"
        >
          {{ locationStatus === 'locating' ? 'Mencari...' : (locationStatus === 'ready' ? 'Siap' : 'GPS') }}
        </span>
      </section>

      <!-- Individual Session Info Card -->
      <section class="session-details-card">
        <div class="session-row">
          <span class="label">Personel</span>
          <strong class="value">{{ selectedOutsource?.name }} ({{ selectedOutsource?.outsource_code }})</strong>
        </div>
        <div class="session-row">
          <span class="label">Lokasi Toko</span>
          <span class="value">{{ selectedStoreName }}</span>
        </div>
        <div v-if="expiresAt" class="session-row">
          <span class="label">Kedaluwarsa Sesi</span>
          <span class="value text-muted">{{ formatDate(expiresAt) }}</span>
        </div>

        <button type="button" class="btn-link-reset" @click="resetSelection">
          <span>Ganti Personel / Akhiri Sesi</span>
          <span aria-hidden="true">↪</span>
        </button>
      </section>
    </template>

    <!-- STEP COMPLETED SCREEN -->
    <template v-if="step === 'completed'">
      <section class="pwa-card completed-box">
        <div class="completed-icon">✓</div>
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

        <button type="button" class="btn-primary" @click="resetSelection">
          <span>Selesai & Tutup Sesi</span>
          <span aria-hidden="true">✓</span>
        </button>
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
  padding: 0.35rem 0 1.5rem;
}

.brand-lockup {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.brand-logo {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 7px;
  object-fit: cover;
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

.user-badge {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-weight: 700;
  font-size: 0.95rem;
}

.kiosk-badge {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  place-items: center;
  border-radius: 50%;
  background: var(--accent-bg);
  color: var(--accent);
  font-size: 1rem;
}

/* Welcome Block */
.welcome-block {
  padding: 0.5rem 0 1.5rem;
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
  border-radius: 4px;
}

.welcome-block h1 {
  margin: 0.35rem 0 0.25rem;
  font-size: 1.85rem;
  font-weight: 700;
  letter-spacing: -0.04em;
  color: var(--text-h);
}

.welcome-block p {
  color: var(--text);
  font-size: 0.9rem;
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
  padding: 0.65rem 1rem;
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--border);
}

.step-item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
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
  width: 1.4rem;
  height: 1.4rem;
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
  background: var(--accent);
  color: #fff;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(235, 28, 36, 0.25);
  transition: transform 0.1s, opacity 0.2s;
}

.btn-primary:active:not(:disabled) {
  transform: scale(0.98);
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
  padding: 0.85rem 1rem;
  border-radius: 9px;
  border: 1px solid var(--border);
  background: #fff;
  color: var(--text-h);
  font-size: 0.92rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-secondary:hover {
  background: #f4f4f5;
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
  transition: transform 0.1s, opacity 0.2s;
}

.hero-action-button:active:not(:disabled) {
  transform: scale(0.98);
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

.status-indicator-badge {
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  background: #f4f4f5;
  color: #71717a;
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

/* Session Details Card */
.session-details-card {
  background: #fff;
  border-radius: 12px;
  border: 1px solid var(--border);
  padding: 1rem;
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
