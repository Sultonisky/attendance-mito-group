<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../services/apiClient'
import { fetchAttendanceToday, submitCheckIn, submitCheckOut } from '../services/attendanceService'
import { useAttendanceCamera } from '../composables/useAttendanceCamera'
import { useGeolocation } from '../composables/useGeolocation'
import type { AttendanceRecord } from '../types/attendance'

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
  if (hasOpenSession.value) return 'Check Out'
  return 'Check In'
})

const canCapture = computed(() => {
  return cameraState.value === 'ready' && locationState.value === 'ready' && !isSubmitting.value
})

const statusLabel = computed(() => {
  if (!todayRecord.value) return 'Not checked in'
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
        await router.push({ name: 'login', query: { redirect: '/attendance' } })
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
  formData.append('face_session_id', crypto.randomUUID())
  formData.append('face_image', file)

  isSubmitting.value = true
  resultMessage.value = ''
  resultError.value = ''

  try {
    const response = hasOpenSession.value ? await submitCheckOut(formData) : await submitCheckIn(formData)

    if (response.success) {
      todayRecord.value = response.data
      resultMessage.value = hasOpenSession.value ? 'Check-out successful.' : 'Check-in successful.'
      stopCamera()
      clearLocation()
      showCameraWorkflow.value = false
    } else {
      resultError.value = safeErrorMessage(response.data.error)
    }
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        await router.push({ name: 'login', query: { redirect: '/attendance' } })
        return
      }

      if (err.status === 403) {
        resultError.value = 'You do not have permission to perform this action.'
        return
      }

      if (err.status === 422) {
        resultError.value = err.errors?.face_image?.[0] ?? err.errors?.latitude?.[0] ?? err.errors?.longitude?.[0] ?? err.message
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
  if (!iso) return '--'
  const date = new Date(iso)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatDate(date: Date): string {
  return date.toLocaleDateString([], {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function safeErrorMessage(error: string | null): string {
  if (!error) return 'An unexpected error occurred.'
  return error
}

onMounted(() => {
  loadToday()
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
      <div class="attendance-title">
        <img src="/images/mito.png" alt="MITO electronic" />
        <div>
          <p class="attendance-eyebrow">MITO GROUP</p>
          <h1>Attendance</h1>
        </div>
      </div>
      <p class="attendance-date">{{ formatDate(now) }}</p>
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
      <button type="button" @click="loadToday">Retry</button>
    </section>

    <section v-else class="attendance-body">
      <section class="status-card" aria-live="polite">
        <h2>Today&rsquo;s Status</h2>
        <p class="status-label">{{ statusLabel }}</p>

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

        <button
          v-if="!hasOpenSession || todayRecord"
          type="button"
          class="primary-action"
          :disabled="isSubmitting || showCameraWorkflow"
          @click="startAction"
        >
          {{ actionLabel }}
        </button>
      </section>

      <section
        v-if="showCameraWorkflow"
        class="camera-workflow"
        aria-live="polite"
      >
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
          <video
            ref="videoRef"
            autoplay
            playsinline
            muted
            class="camera-video"
          >
            Your browser does not support the video element.
          </video>
        </div>

        <div class="workflow-actions">
          <button
            v-if="cameraState !== 'ready'"
            type="button"
            class="secondary-action"
            :disabled="isSubmitting"
            @click="startCamera"
          >
            Enable Camera
          </button>

          <button
            v-if="locationState !== 'ready'"
            type="button"
            class="secondary-action"
            :disabled="isSubmitting"
            @click="requestLocation"
          >
            Enable Location
          </button>

          <button
            type="button"
            class="primary-action"
            :disabled="!canCapture"
            @click="captureAndSubmit"
          >
            {{ isSubmitting ? 'Verifying...' : 'Capture & Submit' }}
          </button>

          <button
            v-if="!isSubmitting"
            type="button"
            class="secondary-action"
            @click="stopCamera(); clearLocation(); showCameraWorkflow = false"
          >
            Cancel
          </button>
        </div>
      </section>

      <section
        v-if="resultMessage"
        class="result-banner"
        role="status"
      >
        <p>{{ resultMessage }}</p>
        <button type="button" @click="dismissResult">Dismiss</button>
      </section>

      <section
        v-if="resultError"
        class="error-banner"
        role="alert"
      >
        <p>{{ resultError }}</p>
        <button type="button" @click="dismissResult">Dismiss</button>
      </section>
    </section>
  </main>
</template>

<style scoped>
.attendance-page {
  max-width: 1126px;
  margin: 0 auto;
  padding: 1.5rem;
  text-align: left;
}

.attendance-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1rem;
  margin-bottom: 1.5rem;
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
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
  color: var(--text-h);
}

.attendance-date {
  margin: 0;
  color: var(--text);
  font-size: 0.95rem;
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
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
}

.status-card h2 {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
  color: var(--text-h);
}

.status-label {
  margin: 0 0 1rem;
  font-size: 1rem;
  color: var(--text);
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
  font-size: 0.95rem;
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
  background: var(--code-bg);
  color: var(--text-h);
  border: 1px solid var(--border);
}

.no-sessions {
  margin: 0 0 1rem;
  color: var(--text);
  font-size: 0.95rem;
}

.primary-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  border: none;
  background: var(--accent);
  color: #fff;
  font-size: 1rem;
  font-weight: 500;
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
  padding: 0.75rem 1.25rem;
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

.camera-workflow {
  margin-top: 1.25rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  background: var(--bg);
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
  margin: 0.75rem 0;
  border-radius: 8px;
  overflow: hidden;
  background: #000;
}

.camera-video {
  display: block;
  width: 100%;
  max-height: 360px;
  object-fit: cover;
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
</style>
