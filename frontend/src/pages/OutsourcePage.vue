<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { ApiError } from '../services/apiClient'
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

const isSessionActive = computed(() => step.value === 'session' || step.value === 'attendance_open')
const isAttendanceOpen = computed(() => step.value === 'attendance_open')

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
      error.value = 'Unable to load cities. Please try again.'
    }
  } finally {
    isLoading.value = false
  }
}

async function loadStores(): Promise<void> {
  if (selectedCity.value === null) return

  isLoading.value = true
  error.value = ''
  stores.value = []
  selectedStore.value = null

  try {
    stores.value = await fetchOutsourceStores(selectedCity.value)
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'Unable to load stores. Please try again.'
    }
  } finally {
    isLoading.value = false
  }
}

async function loadOutsources(): Promise<void> {
  if (selectedStore.value === null) return

  isLoading.value = true
  error.value = ''
  outsources.value = []
  selectedOutsource.value = null

  try {
    outsources.value = await fetchOutsourceOutsources(selectedStore.value)
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      error.value = err.message
    } else {
      error.value = 'Unable to load outsources. Please try again.'
    }
  } finally {
    isLoading.value = false
  }
}

async function startSession(): Promise<void> {
  if (selectedCity.value === null || selectedStore.value === null || selectedOutsource.value === null) {
    error.value = 'Please complete all selections.'
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
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      if (err.status === 422) {
        error.value = 'Invalid selection. Please try again.'
      } else if (err.status === 429) {
        error.value = 'Too many attempts. Please wait and try again.'
      } else {
        error.value = 'Unable to start session. Please try again.'
      }
    } else {
      error.value = 'Network error. Please check your connection.'
    }
  } finally {
    isSubmitting.value = false
  }
}

async function requestLocation(): Promise<{ latitude: number; longitude: number; accuracy?: number } | null> {
  if (!navigator.geolocation) {
    error.value = 'Geolocation is not supported by your browser.'
    return null
  }

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        })
      },
      () => {
        error.value = 'Unable to get location. Please enable location services.'
        resolve(null)
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  })
}

async function submitAttendance(): Promise<void> {
  if (!sessionToken.value) {
    error.value = 'Session expired. Please refresh and try again.'
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
        message.value = 'Check-out recorded successfully.'
        sessionToken.value = null
      } else {
        step.value = 'attendance_open'
        message.value = 'Check-in recorded successfully.'
      }
    }
  } catch (e: unknown) {
    const err = e as Error
    if (err instanceof ApiError) {
      switch (err.status) {
        case 401:
          error.value = 'Session expired. Please refresh and start again.'
          sessionToken.value = null
          step.value = 'city'
          break
        case 422:
          error.value = err.message ?? 'Unable to record attendance. Please try again.'
          break
        case 429:
          error.value = 'Too many attempts. Please wait and try again.'
          break
        default:
          error.value = 'Network error. Please check your connection and try again.'
      }
    } else {
      error.value = 'Network error. Please check your connection.'
    }
  } finally {
    isSubmitting.value = false
  }
}

function resetSelection(): void {
  sessionToken.value = null
  expiresAt.value = null
  selectedCity.value = null
  selectedStore.value = null
  selectedOutsource.value = null
  stores.value = []
  outsources.value = []
  attendanceId.value = null
  attendanceStatus.value = null
  attendanceDate.value = null
  checkInAt.value = null
  checkOutAt.value = null
  durationMinutes.value = null
  error.value = ''
  message.value = ''
  step.value = 'city'
}

function formatTime(iso: string | null): string {
  if (!iso) return '--'
  const date = new Date(iso)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatDate(iso: string | null): string {
  if (!iso) return '--'
  const date = new Date(iso)
  return date.toLocaleDateString([], { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(() => {
  loadCities()
})
</script>

<template>
  <main class="outsource-page">
    <header class="outsource-header">
      <div>
        <p class="outsource-eyebrow">MITO GROUP</p>
        <h1>Outsource Attendance</h1>
      </div>
    </header>

    <section v-if="error" class="outsource-error" role="alert">
      <p>{{ error }}</p>
      <button type="button" @click="error = ''">Dismiss</button>
    </section>

    <section v-if="message" class="outsource-success" role="status">
      <p>{{ message }}</p>
      <button type="button" @click="message = ''">Dismiss</button>
    </section>

    <!-- Step 1: Select City -->
    <section v-if="step === 'city'" class="outsource-card">
      <h2>Select City</h2>
      <div class="form-group">
        <label for="city">City</label>
        <select
          id="city"
          v-model="selectedCity"
          :disabled="isLoading"
          @change="loadStores"
        >
          <option :value="null">-- Select city --</option>
          <option v-for="city in cities" :key="city.id" :value="city.id">
            {{ city.name }}
          </option>
        </select>
      </div>
      <button
        type="button"
        class="primary-action"
        :disabled="selectedCity === null || isLoading"
        @click="step = 'store'"
      >
        Next
      </button>
    </section>

    <!-- Step 2: Select Store -->
    <section v-if="step === 'store'" class="outsource-card">
      <h2>Select Store</h2>
      <div class="form-group">
        <label for="store">Store</label>
        <select
          id="store"
          v-model="selectedStore"
          :disabled="isLoading"
          @change="loadOutsources"
        >
          <option :value="null">-- Select store --</option>
          <option v-for="store in stores" :key="store.id" :value="store.id">
            {{ store.name }}
          </option>
        </select>
      </div>
      <div class="button-row">
        <button type="button" class="secondary-action" @click="step = 'city'">Back</button>
        <button
          type="button"
          class="primary-action"
          :disabled="selectedStore === null || isLoading"
          @click="step = 'outsource'"
        >
          Next
        </button>
      </div>
    </section>

    <!-- Step 3: Select Outsource -->
    <section v-if="step === 'outsource'" class="outsource-card">
      <h2>Select Outsource</h2>
      <div class="form-group">
        <label for="outsource">Outsource</label>
        <select
          id="outsource"
          v-model="selectedOutsource"
          :disabled="isLoading || outsources.length === 0"
        >
          <option :value="null">-- Select outsource --</option>
          <option v-for="outsource in outsources" :key="outsource.id" :value="outsource">
            {{ outsource.name }} ({{ outsource.outsource_code }})
          </option>
        </select>
      </div>
      <div class="button-row">
        <button type="button" class="secondary-action" @click="step = 'store'">Back</button>
        <button
          type="button"
          class="primary-action"
          :disabled="!selectedOutsource || isSubmitting"
          @click="startSession"
        >
          {{ isSubmitting ? 'Starting...' : 'Start Attendance Session' }}
        </button>
      </div>
    </section>

    <!-- Step 4: Session Active -->
    <section v-if="isSessionActive" class="outsource-card">
      <div class="session-info">
        <p class="session-kicker">SESSION ACTIVE</p>
        <h2>{{ selectedOutsource?.name }}</h2>
        <p class="session-code">ID: {{ selectedOutsource?.outsource_code }}</p>
        <p v-if="expiresAt" class="session-expiry">Expires: {{ formatDate(expiresAt) }}</p>
      </div>

      <div class="action-area">
        <button
          v-if="!isAttendanceOpen"
          type="button"
          class="primary-action"
          :disabled="isSubmitting"
          @click="submitAttendance"
        >
          {{ isSubmitting ? 'Processing...' : 'Clock In' }}
        </button>
        <button
          v-else
          type="button"
          class="primary-action"
          :disabled="isSubmitting"
          @click="submitAttendance"
        >
          {{ isSubmitting ? 'Processing...' : 'Clock Out' }}
        </button>
      </div>

      <div v-if="checkInAt" class="attendance-info">
        <p><strong>Date:</strong> {{ formatDate(attendanceDate ?? checkInAt) }}</p>
        <p><strong>In:</strong> {{ formatTime(checkInAt) }}</p>
        <p v-if="checkOutAt"><strong>Out:</strong> {{ formatTime(checkOutAt) }}</p>
        <p v-if="durationMinutes !== null"><strong>Duration:</strong> {{ Math.floor(durationMinutes / 60) }}h {{ durationMinutes % 60 }}m</p>
      </div>
    </section>

    <!-- Step 5: Completed -->
    <section v-if="step === 'completed'" class="outsource-card completed-card">
      <h2>Attendance Completed</h2>
      <div class="attendance-info">
        <p><strong>Date:</strong> {{ formatDate(attendanceDate ?? checkInAt) }}</p>
        <p><strong>In:</strong> {{ formatTime(checkInAt) }}</p>
        <p><strong>Out:</strong> {{ formatTime(checkOutAt) }}</p>
        <p v-if="durationMinutes !== null"><strong>Duration:</strong> {{ Math.floor(durationMinutes / 60) }}h {{ durationMinutes % 60 }}m</p>
      </div>
      <button type="button" class="primary-action" @click="resetSelection">New Attendance</button>
    </section>
  </main>
</template>

<style scoped>
.outsource-page {
  box-sizing: border-box;
  width: min(100%, 34rem);
  min-height: 100svh;
  margin: 0 auto;
  padding: 1rem 1rem 2rem;
  background: #f8f8f8;
  text-align: left;
}

.outsource-header {
  padding: 0.35rem 0 1.5rem;
}

.outsource-eyebrow {
  margin: 0 0 0.15rem;
  color: var(--accent);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.outsource-header h1 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: var(--text-h);
}

.outsource-card {
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 1.2rem;
  background: #fff;
  box-shadow: var(--shadow);
}

.outsource-card h2 {
  margin: 0 0 1rem;
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text-h);
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text);
}

.form-group select {
  width: 100%;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 0.95rem;
  background: #fff;
  color: var(--text-h);
}

.form-group select:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.button-row {
  display: flex;
  gap: 0.75rem;
  margin-top: 1rem;
}

.primary-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.85rem 1.5rem;
  border-radius: 7px;
  border: none;
  background: var(--accent);
  color: #fff;
  font-size: 0.9rem;
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.2s;
}

.primary-action:hover:not(:disabled) {
  opacity: 0.9;
}

.primary-action:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.secondary-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  flex: 1;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text-h);
  font-size: 1rem;
  cursor: pointer;
}

.secondary-action:hover:not(:disabled) {
  background: var(--code-bg);
}

.secondary-action:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.session-info {
  margin-bottom: 1.25rem;
}

.session-kicker {
  margin: 0;
  color: #25834a;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.session-info h2 {
  margin: 0.25rem 0 0.15rem;
  font-size: 1.25rem;
}

.session-code {
  margin: 0;
  color: var(--text);
  font-size: 0.85rem;
}

.session-expiry {
  margin: 0.35rem 0 0;
  color: var(--text);
  font-size: 0.75rem;
}

.action-area {
  margin-bottom: 1rem;
}

.attendance-info {
  padding-top: 0.75rem;
  border-top: 1px solid var(--border);
  color: var(--text);
  font-size: 0.85rem;
}

.attendance-info p {
  margin: 0.25rem 0;
}

.completed-card {
  text-align: center;
}

.completed-card h2 {
  margin-bottom: 0.75rem;
}

.outsource-error,
.outsource-success {
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.outsource-error {
  border: 1px solid #b91c1c;
  background: #fef2f2;
  color: #b91c1c;
}

.outsource-success {
  border: 1px solid #15803d;
  background: #f0fdf4;
  color: #15803d;
}

.outsource-error p,
.outsource-success p {
  margin: 0 0 0.5rem;
}

@media (max-width: 640px) {
  .button-row {
    flex-direction: column;
  }

  .primary-action,
  .secondary-action {
    width: 100%;
  }
}
</style>
