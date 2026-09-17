import { onUnmounted, ref, type Ref } from 'vue'

type CameraState = 'idle' | 'requesting' | 'ready' | 'denied' | 'unavailable'

export function useAttendanceCamera() {
  const stream: Ref<MediaStream | null> = ref(null)
  const cameraError: Ref<string> = ref('')
  const cameraState: Ref<CameraState> = ref('idle')

  async function startCamera(): Promise<MediaStream | null> {
    if (cameraState.value === 'ready' && stream.value) {
      return stream.value
    }

    cameraError.value = ''
    cameraState.value = 'requesting'

    try {
      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Camera API is not available in this browser.')
      }

      const mediaStream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: 'user',
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      })

      stream.value = mediaStream
      cameraState.value = 'ready'

      return mediaStream
    } catch (err) {
      stopCamera()

      if (err instanceof DOMException) {
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
          cameraState.value = 'denied'
          cameraError.value = 'Camera permission was denied. Please allow camera access in your browser settings.'
          return null
        }

        if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
          cameraState.value = 'unavailable'
          cameraError.value = 'No camera device was found on this device.'
          return null
        }
      }

      cameraState.value = 'unavailable'
      cameraError.value = 'Unable to access the camera. Please check your device and try again.'
      return null
    }
  }

  function stopCamera(): void {
    if (stream.value) {
      stream.value.getTracks().forEach((track) => track.stop())
      stream.value = null
    }

    if (cameraState.value !== 'denied' && cameraState.value !== 'unavailable') {
      cameraState.value = 'idle'
    }

    cameraError.value = ''
  }

  async function captureFrame(video: HTMLVideoElement): Promise<Blob | null> {
    if (!video.srcObject || !(video.srcObject instanceof MediaStream)) {
      return null
    }

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth || 640
    canvas.height = video.videoHeight || 480

    const ctx = canvas.getContext('2d')
    if (!ctx) {
      return null
    }

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

    return new Promise<Blob | null>((resolve) => {
      canvas.toBlob(
        (blob) => resolve(blob),
        'image/jpeg',
        0.92,
      )
    })
  }

  onUnmounted(() => {
    stopCamera()
  })

  return {
    stream,
    cameraError,
    cameraState,
    startCamera,
    stopCamera,
    captureFrame,
  }
}
