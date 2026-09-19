const DEVICE_FINGERPRINT_KEY = 'mito.outsource.device_fingerprint'

function createDeviceFingerprint(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().replace(/-/g, '')
  }

  const bytes = new Uint8Array(16)
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes)
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256)
    }
  }

  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * Stable per-browser device id (shared across tabs of the same browser origin).
 * Different browsers on the same phone still get different ids — that is a known web limit.
 */
export function getOutsourceDeviceFingerprint(): string {
  try {
    const existing = localStorage.getItem(DEVICE_FINGERPRINT_KEY)
    if (existing && existing.trim().length >= 16) {
      return existing.trim()
    }

    const created = createDeviceFingerprint()
    localStorage.setItem(DEVICE_FINGERPRINT_KEY, created)
    return created
  } catch {
    // Private mode / blocked storage: ephemeral fingerprint for this page lifetime.
    return createDeviceFingerprint()
  }
}
