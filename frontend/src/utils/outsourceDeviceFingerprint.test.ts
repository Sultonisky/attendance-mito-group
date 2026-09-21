import { beforeEach, describe, expect, it } from 'vitest'
import { getOutsourceDeviceFingerprint } from './outsourceDeviceFingerprint'

const STORAGE_KEY = 'mito.outsource.device_fingerprint'

describe('getOutsourceDeviceFingerprint', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('creates and persists a fingerprint on first call', () => {
    const fingerprint = getOutsourceDeviceFingerprint()

    expect(fingerprint).toMatch(/^[0-9a-f]{32}$/)
    expect(localStorage.getItem(STORAGE_KEY)).toBe(fingerprint)
  })

  it('returns the stored fingerprint on subsequent calls', () => {
    const first = getOutsourceDeviceFingerprint()
    const second = getOutsourceDeviceFingerprint()

    expect(second).toBe(first)
  })

  it('reuses a valid pre-existing fingerprint', () => {
    localStorage.setItem(STORAGE_KEY, '  abcdef1234567890abcdef1234567890  ')

    expect(getOutsourceDeviceFingerprint()).toBe('abcdef1234567890abcdef1234567890')
  })

  it('regenerates when the stored value is too short', () => {
    localStorage.setItem(STORAGE_KEY, 'short')

    const fingerprint = getOutsourceDeviceFingerprint()

    expect(fingerprint).toMatch(/^[0-9a-f]{32}$/)
    expect(fingerprint).not.toBe('short')
  })
})
