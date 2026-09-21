import { beforeEach, describe, expect, it } from 'vitest'
import { usePortalAnchor } from './usePortalAnchor'

describe('usePortalAnchor', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('returns null when no portal anchor is set', () => {
    const { getPortal } = usePortalAnchor()

    expect(getPortal()).toBeNull()
  })

  it('stores and clears the portal anchor', () => {
    const { getPortal, setPortal, clearPortal } = usePortalAnchor()

    setPortal('admin')
    expect(getPortal()).toBe('admin')

    clearPortal()
    expect(getPortal()).toBeNull()
  })

  it('treats unanchored tabs as role-consistent', () => {
    const { isRoleConsistentWithPortal } = usePortalAnchor()

    expect(isRoleConsistentWithPortal(true)).toBe(true)
    expect(isRoleConsistentWithPortal(false)).toBe(true)
  })

  it('matches admin portal only with admin roles', () => {
    const { setPortal, isRoleConsistentWithPortal } = usePortalAnchor()

    setPortal('admin')

    expect(isRoleConsistentWithPortal(true)).toBe(true)
    expect(isRoleConsistentWithPortal(false)).toBe(false)
  })

  it('matches employee portal only with non-admin roles', () => {
    const { setPortal, isRoleConsistentWithPortal } = usePortalAnchor()

    setPortal('employee')

    expect(isRoleConsistentWithPortal(false)).toBe(true)
    expect(isRoleConsistentWithPortal(true)).toBe(false)
  })

  it('ignores invalid stored values', () => {
    sessionStorage.setItem('mito.portal', 'superadmin')
    const { getPortal, isRoleConsistentWithPortal } = usePortalAnchor()

    expect(getPortal()).toBeNull()
    expect(isRoleConsistentWithPortal(true)).toBe(true)
  })
})
