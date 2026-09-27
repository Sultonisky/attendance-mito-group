<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '../../../components/AppButton.vue'
import AppIcon from '../../../components/AppIcon.vue'
import { ApiError } from '../../../services/apiClient'
import {
  fetchAttendanceToday,
  fetchAttendanceWorkLocations,
  submitCheckIn,
  submitCheckOut,
  type AttendanceWorkLocation,
} from '../../../services/attendanceService'
import { useAttendanceCamera } from '../../../composables/useAttendanceCamera'
import { useGeolocation } from '../../../composables/useGeolocation'
import type { AttendanceRecord } from '../../../types/attendance'
import { formatAttendanceLongDate, formatAttendanceTime } from '../../../utils/attendanceDateTime'
import { distanceMeters, formatDistance } from '../../../utils/geo'
import GeofenceMap from '../../../components/GeofenceMap.vue'

const router = useRouter()

const camera = useAttendanceCamera()
const videoRef = ref<HTMLVideoElement | null>(null)
const { cameraError, cameraState, startCamera, stopCamera, captureFrame } = camera

const {
  location,
  locationError,
  locationState,
  requestLocation,
  clearLocation,
} = useGeolocation()

const todayRecord = ref<AttendanceRecord | null>(null)
const isLoading = ref(true)
const pageError = ref('')
const isSubmitting = ref(false)
const resultMessage = ref('')
const resultError = ref('')
const showCameraWorkflow = ref(false)
const now = ref(new Date())

let clockTimer: number | null = null

const hasOpenSession = computed(() => {
  if (!todayRecord.value) return false
  return todayRecord.value.sessions.some((session) => session.status === 'open')
})

const actionLabel = computed(() => {
  if (hasOpenSession.value) return 'Clock Out'
  return 'Clock In'
})

// ── Work locations (geofence targets, same concept as outsource pins) ────────
const workLocations = ref<AttendanceWorkLocation[]>([])
const hasAssignment = ref(true)
const workLocationsError = ref('')
const selectedWorkLocationId = ref<number | null>(null)

const selectedWorkLocation = computed(() =>
  workLocations.value.find((loc) => loc.id === selectedWorkLocationId.value) ?? null,
)

const workLocationBlocker = computed(() => {
  if (workLocationsError.value) return workLocationsError.value
  if (!hasAssignment.value) return 'Anda belum di-assign ke work location. Hubungi HR/admin.'
  if (!workLocations.value.length) return 'Work location Anda belum punya koordinat aktif. Hubungi HR/admin.'
  return ''
})

const userPosition = computed(() =>
  location.value ? { lat: location.value.latitude, lng: location.value.longitude } : null,
)

const distanceToWorkLocation = computed(() => {
  if (!selectedWorkLocation.value || !userPosition.value) return null
  return distanceMeters(
    { lat: selectedWorkLocation.value.latitude, lng: selectedWorkLocation.value.longitude },
    userPosition.value,
  )
})

const isInsideRadius = computed(() => {
  if (distanceToWorkLocation.value === null || !selectedWorkLocation.value) return null
  return distanceToWorkLocation.value <= selectedWorkLocation.value.radius_meters
})

function workLocationLabel(loc: AttendanceWorkLocation): string {
  return `${loc.city} · ${loc.name}${loc.area_type_label ? ` (${loc.area_type_label})` : ''}`
}

async function loadWorkLocations(): Promise<void> {
  workLocationsError.value = ''
  try {
    const res = await fetchAttendanceWorkLocations()
    workLocations.value = res.data
    hasAssignment.value = res.meta.has_assignment
    if (!workLocations.value.some((loc) => loc.id === selectedWorkLocationId.value)) {
      selectedWorkLocationId.value = workLocations.value.length === 1 ? workLocations.value[0]!.id : null
    }
  } catch {
    workLocations.value = []
    workLocationsError.value = 'Gagal memuat work location. Silakan coba lagi.'
  }
}

function onWorkLocationChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  selectedWorkLocationId.value = value ? Number(value) : null
}

const canCapture = computed(() => {
  return cameraState.value === 'ready'
    && locationState.value === 'ready'
    && selectedWorkLocation.value !== null
    && !isSubmitting.value
})

const statusLabel = computed(() => {
  if (!todayRecord.value) return 'Not clocked in'
  return formatStatus(todayRecord.value.status)
})

async function loadToday(): Promise<void> {
  isLoading.value = true
  pageError.value = ''
  resultMessage.value = ''
  resultError.value = ''

  try {
    todayRecord.value = await fetchAttendanceToday()
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'error.unauthorized' })
        return
      }

      if (err.status === 403) {
        pageError.value = 'You do not have permission to view attendance.'
        return
      }
    }

    pageError.value = 'Unable to load attendance data. Please try again.'
  } finally {
    isLoading.value = false
  }
}

async function startAction(): Promise<void> {
  resultMessage.value = ''
  resultError.value = ''
  showCameraWorkflow.value = true

  const mediaStream = await startCamera()
  if (mediaStream && videoRef.value) {
    videoRef.value.srcObject = mediaStream
  }

  await requestLocation()
}

async function captureAndSubmit(): Promise<void> {
  if (!canCapture.value || !videoRef.value) return

  const blob = await captureFrame(videoRef.value)
  if (!blob) {
    resultError.value = 'Unable to capture camera image. Please try again.'
    return
  }

  const file = new File([blob], 'face-capture.jpg', { type: 'image/jpeg' })
  const formData = new FormData()
  formData.append('latitude', String(location.value!.latitude))
  formData.append('longitude', String(location.value!.longitude))
  formData.append('accuracy_meters', location.value!.accuracy != null ? String(location.value!.accuracy) : '')
  formData.append('source', 'web')
  formData.append('work_location_id', String(selectedWorkLocation.value!.id))
  formData.append('face_session_id', crypto.randomUUID())
  formData.append('face_image', file)

  isSubmitting.value = true
  resultMessage.value = ''
  resultError.value = ''

  try {
    const response = hasOpenSession.value ? await submitCheckOut(formData) : await submitCheckIn(formData)

    if (response.success) {
      todayRecord.value = response.data
      resultMessage.value = hasOpenSession.value ? 'Clock-out successful.' : 'Clock-in successful.'
      stopCamera()
      clearLocation()
      showCameraWorkflow.value = false
    } else {
      resultError.value = safeErrorMessage(response.data.error)
    }
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'error.unauthorized' })
        return
      }

      if (err.status === 403) {
        resultError.value = 'You do not have permission to perform this action.'
        return
      }

      if (err.status === 422) {
        resultError.value = err.errors?.face_image?.[0]
          ?? err.errors?.work_location_id?.[0]
          ?? err.errors?.latitude?.[0]
          ?? err.errors?.longitude?.[0]
          ?? err.message
        return
      }

      if (err.status === 409) {
        resultError.value = 'Attendance state conflict. Please refresh and try again.'
        return
      }

      if (err.status === 503) {
        resultError.value = 'AI service is unavailable. Please try again later.'
        return
      }
    }

    resultError.value = 'Network error. Please check your connection and try again.'
  } finally {
    isSubmitting.value = false
  }
}

function dismissResult(): void {
  resultMessage.value = ''
  resultError.value = ''
}

function formatStatus(status: string): string {
  return status
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatTime(iso: string | null): string {
  return formatAttendanceTime(iso, '--')
}

function formatDate(date: Date): string {
  return formatAttendanceLongDate(date)
}

function safeErrorMessage(error: string | null): string {
  if (!error) return 'An unexpected error occurred.'
  return error
}

onMounted(() => {
  loadToday()
  loadWorkLocations()
  clockTimer = window.setInterval(() => {
    now.value = new Date()
  }, 60000)
})

onUnmounted(() => {
  if (clockTimer !== null) {
    clearInterval(clockTimer)
  }
  stopCamera()
  clearLocation()
})
</script>

<template>
  <main class="attendance-page">
    <header class="attendance-header">
      <AppButton
        type="button"
        class="back-button"
        variant="ghost"
        icon="ArrowLeft"
        aria-label="Back to employee app"
        @click="router.push({ name: 'employee-app' })"
      />
      <div class="attendance-title">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p class="attendance-eyebrow">MITO GROUP</p>
          <h1>Attendance</h1>
        </div>
      </div>
      <div class="attendance-header-meta">
        <span class="secure-pill">
          <AppIcon name="ShieldCheck" :size="12" :stroke-width="2.5" aria-hidden="true" />
          <span>Secure</span>
        </span>
        <p class="attendance-date">{{ formatDate(now) }}</p>
      </div>
    </header>

    <section v-if="isLoading" class="attendance-loading" aria-label="Loading attendance">
      <p>Loading today&rsquo;s attendance...</p>
    </section>

    <section
      v-else-if="pageError"
      class="attendance-error"
      role="alert"
    >
      <p>{{ pageError }}</p>
      <AppButton type="button" variant="secondary" @click="loadToday">Retry</AppButton>
    </section>

    <section v-else class="attendance-body">
      <section class="status-card" aria-live="polite">
        <div class="status-card-heading">
          <div>
            <p class="status-kicker">YOUR ATTENDANCE</p>
            <h2>{{ statusLabel }}</h2>
          </div>
          <span class="status-orb" :class="{ active: hasOpenSession }" />
        </div>

        <div v-if="todayRecord && todayRecord.sessions.length" class="sessions">
          <div
            v-for="session in todayRecord.sessions"
            :key="session.id"
            class="session-row"
          >
            <span>IN: {{ formatTime(session.check_in_at) }}</span>
            <span>OUT: {{ formatTime(session.check_out_at) }}</span>
            <span v-if="session.duration_minutes != null">
              {{ Math.floor(session.duration_minutes / 60) }}h {{ session.duration_minutes % 60 }}m
            </span>
            <span
              class="session-badge"
              :class="session.status === 'open' ? 'badge-open' : 'badge-closed'"
            >
              {{ session.status }}
            </span>
          </div>
        </div>

        <p v-else class="no-sessions">No attendance sessions recorded today.</p>

        <p class="status-helper">
          {{ hasOpenSession ? 'You are currently clocked in. Complete your day when you leave.' : 'Ready to record your presence with Face ID and location.' }}
        </p>

        <AppButton
          v-if="!hasOpenSession || todayRecord"
          type="button"
          variant="primary"
          icon="ArrowRight"
          :disabled="isSubmitting || showCameraWorkflow || Boolean(workLocationBlocker)"
          @click="startAction"
        >
          {{ actionLabel }}
        </AppButton>
      </section>

      <section class="location-card" aria-live="polite">
        <div class="workflow-heading">
          <div>
            <p class="status-kicker">LOKASI KERJA</p>
            <h2 class="location-title">
              {{ selectedWorkLocation ? selectedWorkLocation.name : workLocations.length > 1 ? 'Pilih lokasi' : 'Work location' }}
            </h2>
          </div>
          <AppIcon name="MapPinned" class="workflow-lock" :size="18" :stroke-width="2.2" aria-hidden="true" />
        </div>

        <div v-if="workLocationBlocker" class="location-blocker" role="alert">
          <p>{{ workLocationBlocker }}</p>
          <AppButton type="button" variant="secondary" @click="loadWorkLocations">Muat ulang</AppButton>
        </div>

        <template v-else>
          <label v-if="workLocations.length > 1" class="location-select">
            <span>Lokasi absensi</span>
            <select :value="selectedWorkLocationId ?? ''" :disabled="isSubmitting" @change="onWorkLocationChange">
              <option value="" disabled>Pilih lokasi…</option>
              <option v-for="loc in workLocations" :key="loc.id" :value="loc.id">
                {{ workLocationLabel(loc) }}
              </option>
            </select>
          </label>

          <template v-if="selectedWorkLocation">
            <p class="location-meta">
              {{ selectedWorkLocation.city }}<template v-if="selectedWorkLocation.area_type_label"> · {{ selectedWorkLocation.area_type_label }}</template>
              · radius {{ Math.round(selectedWorkLocation.radius_meters) }} m
            </p>
            <p v-if="selectedWorkLocation.address" class="location-address">{{ selectedWorkLocation.address }}</p>

            <GeofenceMap
              :target="{ lat: selectedWorkLocation.latitude, lng: selectedWorkLocation.longitude }"
              :radius-meters="selectedWorkLocation.radius_meters"
              :user="userPosition"
              :target-title="selectedWorkLocation.name"
            />

            <div class="location-distance">
              <span>
                Jarak: <strong>{{ formatDistance(distanceToWorkLocation) }}</strong>
                <template v-if="location?.accuracy != null"> · akurasi ±{{ Math.round(location.accuracy) }} m</template>
              </span>
              <span
                v-if="isInsideRadius !== null"
                class="distance-badge"
                :class="isInsideRadius ? 'badge-inside' : 'badge-outside'"
              >
                {{ isInsideRadius ? 'Dalam radius' : 'Di luar radius' }}
              </span>
              <span v-else class="distance-badge badge-pending">Lokasi belum aktif</span>
            </div>
            <p v-if="isInsideRadius === false" class="location-warning">
              Anda terdeteksi di luar radius lokasi kerja. Absensi akan ditolak server jika tetap di luar radius.
            </p>
          </template>
          <p v-else class="location-meta">Pilih lokasi absensi sebelum Clock In/Out.</p>

          <AppButton
            type="button"
            class="secondary-action"
            variant="secondary"
            :disabled="isSubmitting || locationState === 'requesting'"
            @click="clearLocation(); requestLocation()"
          >
            {{ locationState === 'ready' ? 'Perbarui GPS' : 'Cek lokasi saya' }}
          </AppButton>
        </template>
      </section>

      <section
        v-if="showCameraWorkflow"
        class="camera-workflow"
        aria-live="polite"
      >
        <div class="workflow-heading">
          <div>
            <p class="status-kicker">VERIFICATION</p>
            <h2>Ready when you are</h2>
          </div>
          <AppIcon name="ShieldCheck" class="workflow-lock" :size="18" :stroke-width="2.2" aria-hidden="true" />
        </div>
        <div class="verification-steps" aria-label="Verification progress">
          <span :class="{ complete: cameraState === 'ready' }"><b>1</b> Face</span>
          <i />
          <span :class="{ complete: locationState === 'ready' }"><b>2</b> Location</span>
          <i />
          <span :class="{ complete: isSubmitting }"><b>3</b> Submit</span>
        </div>
        <div class="workflow-status">
          <div class="status-item">
            <span class="status-label-sm">Camera</span>
            <span
              class="status-value"
              :class="cameraState === 'ready' ? 'text-success' : cameraState === 'denied' || cameraState === 'unavailable' ? 'text-error' : 'text-muted'"
            >
              {{ cameraState }}
            </span>
          </div>
          <div class="status-item">
            <span class="status-label-sm">Location</span>
            <span
              class="status-value"
              :class="locationState === 'ready' ? 'text-success' : locationState === 'denied' || locationState === 'unavailable' ? 'text-error' : 'text-muted'"
            >
              {{ locationState }}
            </span>
          </div>
        </div>

        <p
          v-if="cameraError"
          class="workflow-error"
          role="alert"
        >
          {{ cameraError }}
        </p>
        <p
          v-if="locationError"
          class="workflow-error"
          role="alert"
        >
          {{ locationError }}
        </p>

        <div v-if="cameraState === 'ready'" class="camera-preview">
          <video ref="videoRef" autoplay playsinline muted class="camera-video">
            Your browser does not support the video element.
          </video>
          <div class="face-guide" aria-hidden="true">
            <div class="face-outline">
              <span class="eye-line" />
              <span class="scan-line" />
            </div>
            <div class="shoulder-outline" />
            <p>Position your face inside the frame</p>
          </div>
        </div>

        <div class="workflow-actions">
          <AppButton
            v-if="cameraState !== 'ready'"
            type="button"
            class="secondary-action"
            variant="secondary"
            :disabled="isSubmitting"
            @click="startCamera"
          >
            Enable Camera
          </AppButton>

          <AppButton
            v-if="locationState !== 'ready'"
            type="button"
            class="secondary-action"
            variant="secondary"
            :disabled="isSubmitting"
            @click="requestLocation"
          >
            Enable Location
          </AppButton>

          <AppButton
            type="button"
            class="primary-action"
            variant="primary"
            :disabled="!canCapture"
            @click="captureAndSubmit"
          >
            {{ isSubmitting ? 'Verifying...' : 'Capture & Submit' }}
          </AppButton>

          <AppButton
            v-if="!isSubmitting"
            type="button"
            class="secondary-action"
            variant="secondary"
            @click="stopCamera(); clearLocation(); showCameraWorkflow = false"
          >
            Cancel
          </AppButton>
        </div>
      </section>

      <section
        v-if="resultMessage"
        class="result-banner"
        role="status"
      >
        <p>{{ resultMessage }}</p>
        <AppButton type="button" variant="secondary" @click="dismissResult">Dismiss</AppButton>
      </section>

      <section
        v-if="resultError"
        class="error-banner"
        role="alert"
      >
        <p>{{ resultError }}</p>
        <AppButton type="button" variant="secondary" @click="dismissResult">Dismiss</AppButton>
      </section>
    </section>
  </main>
</template>

<style scoped>
.attendance-page {
  box-sizing: border-box;
  width: min(100%, 34rem);
  min-height: 100svh;
  margin: 0 auto;
  padding: 1rem 1rem 2rem;
  background: #f8f8f8;
  text-align: left;
}

.attendance-header {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 1rem;
  padding: 0.35rem 0 1.5rem;
  border-bottom: 0;
  margin-bottom: 0.5rem;
}

.back-button {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  flex: 0 0 auto;
  margin: 0;
  padding: 0;
  place-items: center;
  border: 1px solid var(--border);
  border-radius: 50%;
  background: #fff;
  color: var(--text-h);
  font-size: 1.1rem;
}

.back-button:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.attendance-title {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}

.attendance-title img {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 6px;
  object-fit: cover;
}

.attendance-eyebrow {
  margin: 0 0 0.15rem;
  color: var(--accent);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.attendance-header h1 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: var(--text-h);
}

.attendance-header-meta {
  display: grid;
  gap: 0.15rem;
  margin-left: auto;
  justify-items: end;
}

.secure-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: #25834a;
  font-size: 0.65rem;
  font-weight: 700;
}

.secure-pill span {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
  background: #2c9c5b;
}

.attendance-date {
  margin: 0;
  color: var(--text);
  font-size: 0.7rem;
}

.attendance-loading,
.attendance-error {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
}

.attendance-error p {
  margin: 0 0 0.75rem;
  color: #b91c1c;
}

.status-card {
  padding: 1.35rem;
  border-radius: 14px;
  background: var(--accent);
  color: #fff;
  box-shadow: 0 16px 32px rgba(235, 28, 36, 0.2);
}

.status-card-heading,
.workflow-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.status-kicker {
  margin: 0;
  color: var(--accent);
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.13em;
}

.status-card .status-kicker {
  color: rgba(255, 255, 255, 0.7);
}

.status-card h2,
.workflow-heading h2 {
  margin: 0.35rem 0 0;
  color: #fff;
  font-size: 1.4rem;
  font-weight: 700;
}

.status-orb {
  width: 0.8rem;
  height: 0.8rem;
  border: 4px solid rgba(255, 255, 255, 0.35);
  border-radius: 50%;
}

.status-orb.active {
  background: #fff;
}

.status-label {
  display: none;
}

.sessions {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.session-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  padding-top: 0.9rem;
  border-top: 1px solid rgba(255, 255, 255, 0.25);
  color: rgba(255, 255, 255, 0.82);
  font-size: 0.8rem;
}

.session-badge {
  padding: 0.15rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.badge-open {
  background: var(--accent-bg);
  color: var(--accent);
  border: 1px solid var(--accent-border);
}

.badge-closed {
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.25);
}

.no-sessions {
  margin: 1.2rem 0 0;
  color: rgba(255, 255, 255, 0.75);
  font-size: 0.8rem;
}

.status-helper {
  margin: 1.1rem 0;
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.8rem;
  line-height: 1.45;
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

.location-card {
  display: grid;
  gap: 0.75rem;
  margin-top: 1rem;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 1.2rem;
  background: #fff;
  box-shadow: var(--shadow);
}

.location-card .location-title {
  color: var(--text-h);
  font-size: 1.05rem;
}

.location-select {
  display: grid;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--text);
}

.location-select select {
  width: 100%;
  padding: 0.6rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--text-h);
  font-size: 0.9rem;
}

.location-meta,
.location-address {
  margin: 0;
  color: var(--text);
  font-size: 0.8rem;
}

.location-address {
  color: #6b6b73;
}

.location-distance {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: var(--text-h);
}

.distance-badge {
  padding: 0.15rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 700;
}

.badge-inside {
  background: #dcfce7;
  color: #15803d;
}

.badge-outside {
  background: #fee2e2;
  color: #b91c1c;
}

.badge-pending {
  background: #f1f1f3;
  color: #6b6b73;
}

.location-warning {
  margin: 0;
  color: #b45309;
  font-size: 0.78rem;
}

.location-blocker {
  display: grid;
  gap: 0.6rem;
  padding: 0.85rem;
  border: 1px dashed #f59e0b;
  border-radius: 10px;
  background: #fffbeb;
  color: #92400e;
  font-size: 0.85rem;
}

.location-blocker p {
  margin: 0;
}

.camera-workflow {
  margin-top: 1rem;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 1.2rem;
  background: #fff;
  box-shadow: var(--shadow);
}

.workflow-heading h2 {
  color: var(--text-h);
  font-size: 1.15rem;
}

.workflow-lock {
  display: grid;
  width: 2rem;
  height: 2rem;
  place-items: center;
  border-radius: 50%;
  background: var(--accent-bg);
  color: var(--accent);
}

.verification-steps {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 1rem 0 1.25rem;
  color: #9999a0;
  font-size: 0.68rem;
  font-weight: 600;
}

.verification-steps span {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  white-space: nowrap;
}

.verification-steps b {
  display: grid;
  width: 1.15rem;
  height: 1.15rem;
  place-items: center;
  border-radius: 50%;
  background: #eeeeef;
  color: #88888f;
  font-size: 0.62rem;
}

.verification-steps .complete {
  color: var(--accent);
}

.verification-steps .complete b {
  background: var(--accent-bg);
  color: var(--accent);
}

.verification-steps i {
  flex: 1;
  height: 1px;
  background: var(--border);
}

.workflow-status {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.95rem;
}

.status-label-sm {
  color: var(--text);
}

.status-value {
  font-weight: 500;
}

.text-success {
  color: #15803d;
}

.text-error {
  color: #b91c1c;
}

.text-muted {
  color: var(--text);
}

.workflow-error {
  margin: 0 0 0.75rem;
  color: #b91c1c;
  font-size: 0.95rem;
}

.camera-preview {
  position: relative;
  margin: 0.75rem 0;
  width: min(100%, 22rem);
  aspect-ratio: 4 / 5;
  margin-inline: auto;
  border-radius: 14px;
  overflow: hidden;
  background: #151519;
  box-shadow: 0 8px 20px rgba(20, 20, 25, 0.15);
}

.camera-video {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.face-guide {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  flex-direction: column;
  justify-content: center;
  pointer-events: none;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0.12), transparent 35%, rgba(0, 0, 0, 0.28));
}

.face-outline {
  position: relative;
  width: 54%;
  height: 58%;
  transform: translateY(-5%);
  border: 2px solid rgba(255, 255, 255, 0.9);
  border-radius: 50% 50% 47% 47% / 43% 43% 57% 57%;
  box-shadow: 0 0 0 5px rgba(235, 28, 36, 0.25), 0 0 25px rgba(0, 0, 0, 0.18);
}

.face-outline::before,
.face-outline::after {
  content: '';
  position: absolute;
  top: -2px;
  width: 1.15rem;
  height: 1.15rem;
  border-top: 3px solid var(--accent);
}

.face-outline::before {
  left: -2px;
  border-left: 3px solid var(--accent);
  border-radius: 10px 0 0;
}

.face-outline::after {
  right: -2px;
  border-right: 3px solid var(--accent);
  border-radius: 0 10px 0 0;
}

.shoulder-outline {
  width: 78%;
  height: 23%;
  margin-top: -8%;
  border: 2px solid rgba(255, 255, 255, 0.7);
  border-bottom: 0;
  border-radius: 50% 50% 0 0;
  opacity: 0.65;
}

.eye-line {
  position: absolute;
  top: 31%;
  right: 10%;
  left: 10%;
  border-top: 1px dashed rgba(255, 255, 255, 0.7);
}

.face-guide p {
  position: absolute;
  right: 0.75rem;
  bottom: 0.75rem;
  left: 0.75rem;
  margin: 0;
  color: #fff;
  font-size: 0.72rem;
  font-weight: 600;
  text-align: center;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
}

.scan-line {
  position: absolute;
  top: 24%;
  right: 8%;
  left: 8%;
  height: 2px;
  background: var(--accent);
  box-shadow: 0 0 8px rgba(235, 28, 36, 0.9);
  animation: face-scan 2.6s ease-in-out infinite;
}

@keyframes face-scan {
  0%,
  100% {
    top: 24%;
    opacity: 0.35;
  }
  50% {
    top: 68%;
    opacity: 1;
  }
}

.workflow-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.result-banner {
  margin-top: 1rem;
  border: 1px solid #15803d;
  border-radius: 8px;
  padding: 1rem;
  background: #f0fdf4;
  color: #15803d;
}

.result-banner p {
  margin: 0 0 0.5rem;
}

.error-banner {
  margin-top: 1rem;
  border: 1px solid #b91c1c;
  border-radius: 8px;
  padding: 1rem;
  background: #fef2f2;
  color: #b91c1c;
}

.error-banner p {
  margin: 0 0 0.5rem;
}

@media (max-width: 640px) {
  .attendance-page {
    padding: 1rem;
  }

  .workflow-actions {
    flex-direction: column;
  }

  .primary-action,
  .secondary-action {
    width: 100%;
  }
}

@media (min-width: 700px) {
  .attendance-page {
    margin-top: 2rem;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
  }
}
</style>
