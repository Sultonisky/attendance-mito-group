import { onUnmounted, ref, type Ref } from 'vue'

type LocationState = 'idle' | 'requesting' | 'ready' | 'denied' | 'unavailable'

export interface LocationData {
  latitude: number
  longitude: number
  accuracy: number | null
}

export function useGeolocation() {
  const location: Ref<LocationData | null> = ref(null)
  const locationError: Ref<string> = ref('')
  const locationState: Ref<LocationState> = ref('idle')

  async function requestLocation(): Promise<void> {
    if (locationState.value === 'ready' && location.value) {
      return
    }

    locationError.value = ''
    locationState.value = 'requesting'

    try {
      if (!navigator.geolocation) {
        throw new Error('Geolocation is not supported by this browser.')
      }

      const position = await new Promise<GeolocationPosition>((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 15000,
          maximumAge: 0,
        })
      })

      location.value = {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
      }
      locationState.value = 'ready'
    } catch (err) {
      location.value = null

      if (err instanceof GeolocationPositionError) {
        if (err.code === GeolocationPositionError.PERMISSION_DENIED) {
          locationState.value = 'denied'
          locationError.value = 'Location permission was denied. Please allow location access in your browser settings.'
          return
        }

        if (err.code === GeolocationPositionError.POSITION_UNAVAILABLE) {
          locationState.value = 'unavailable'
          locationError.value = 'Location information is unavailable. Please try again.'
          return
        }

        if (err.code === GeolocationPositionError.TIMEOUT) {
          locationState.value = 'unavailable'
          locationError.value = 'Location request timed out. Please try again.'
          return
        }
      }

      locationState.value = 'unavailable'
      locationError.value = 'Unable to retrieve location. Please check your device and try again.'
    }
  }

  function clearLocation(): void {
    location.value = null
    locationError.value = ''
    locationState.value = 'idle'
  }

  onUnmounted(() => {
    clearLocation()
  })

  return {
    location,
    locationError,
    locationState,
    requestLocation,
    clearLocation,
  }
}
