import { beforeEach, describe, expect, it } from 'vitest'
import {
  EMPLOYEE_MANIFEST_HREF,
  OUTSOURCE_MANIFEST_HREF,
  manifestHrefForPath,
  syncPwaManifest,
} from './pwaManifest'

function manifestHref(): string | null {
  return document.querySelector('link[rel="manifest"]')?.getAttribute('href') ?? null
}

describe('manifestHrefForPath', () => {
  it('uses the outsource manifest for /outsource', () => {
    expect(manifestHrefForPath('/outsource')).toBe(OUTSOURCE_MANIFEST_HREF)
    expect(manifestHrefForPath('/outsource/')).toBe(OUTSOURCE_MANIFEST_HREF)
  })

  it('uses the employee manifest elsewhere, including /outsource-* admin paths', () => {
    expect(manifestHrefForPath('/employee')).toBe(EMPLOYEE_MANIFEST_HREF)
    expect(manifestHrefForPath('/login/employee')).toBe(EMPLOYEE_MANIFEST_HREF)
    expect(manifestHrefForPath('/outsource-attendance')).toBe(EMPLOYEE_MANIFEST_HREF)
    expect(manifestHrefForPath('/')).toBe(EMPLOYEE_MANIFEST_HREF)
  })
})

describe('syncPwaManifest', () => {
  beforeEach(() => {
    document.head.innerHTML = ''
  })

  it('creates the manifest link when missing', () => {
    syncPwaManifest('/outsource')

    expect(manifestHref()).toBe(OUTSOURCE_MANIFEST_HREF)
  })

  it('swaps the existing link on navigation', () => {
    syncPwaManifest('/employee')
    syncPwaManifest('/outsource')

    expect(document.querySelectorAll('link[rel="manifest"]')).toHaveLength(1)
    expect(manifestHref()).toBe(OUTSOURCE_MANIFEST_HREF)

    syncPwaManifest('/employee')
    expect(manifestHref()).toBe(EMPLOYEE_MANIFEST_HREF)
  })
})
