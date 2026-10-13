import { describe, expect, it } from 'vitest'
import { distanceMeters, formatDistance } from './geo'

describe('distanceMeters', () => {
  it('returns 0 for the same point', () => {
    expect(distanceMeters({ lat: -6.2, lng: 106.8 }, { lat: -6.2, lng: 106.8 })).toBe(0)
  })

  it('approximates ~15.7 km between two Jakarta points', () => {
    const d = distanceMeters({ lat: -6.2, lng: 106.8 }, { lat: -6.3, lng: 106.9 })
    expect(d).toBeGreaterThan(15500)
    expect(d).toBeLessThan(15900)
  })
})

describe('formatDistance', () => {
  it('formats meters and kilometers', () => {
    expect(formatDistance(null)).toBe('—')
    expect(formatDistance(42.4)).toBe('42 m')
    expect(formatDistance(1520)).toBe('1.5 km')
  })
})
