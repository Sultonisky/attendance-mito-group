/**
 * usePortalAnchor
 *
 * Stores the current tab's portal context ('admin' | 'employee') in
 * sessionStorage. sessionStorage is isolated per tab — it is NOT shared
 * between tabs in the same browser, unlike localStorage or cookies.
 *
 * This is the key mechanism that prevents cross-session interference:
 *   Tab A (admin)  → sessionStorage: portal=admin
 *   Tab B (employee) → sessionStorage: portal=employee  (different storage)
 *
 * When Tab A refreshes and the server session now belongs to an employee
 * (because Tab B logged in and overwrote the shared cookie), the guard can
 * detect the mismatch using the anchor and redirect to login.admin instead
 * of silently landing the admin tab in the employee app.
 *
 * Security note: this is a UX-level mechanism only.
 * The Laravel backend remains the authority for all authorization.
 */

export type Portal = 'admin' | 'employee'

const STORAGE_KEY = 'mito.portal'

export function usePortalAnchor() {
  /**
   * Read the portal anchor for this tab.
   * Returns null if no anchor has been set yet (e.g. fresh tab, not logged in).
   */
  function getPortal(): Portal | null {
    try {
      const val = sessionStorage.getItem(STORAGE_KEY)
      if (val === 'admin' || val === 'employee') return val
    } catch {
      // sessionStorage blocked (private mode edge cases) — degrade gracefully
    }
    return null
  }

  /**
   * Set the portal anchor for this tab.
   * Call this immediately after a successful login.
   */
  function setPortal(portal: Portal): void {
    try {
      sessionStorage.setItem(STORAGE_KEY, portal)
    } catch {
      // ignore write failures
    }
  }

  /**
   * Clear the portal anchor for this tab.
   * Call this on logout or when the tab should no longer be tied to a portal.
   */
  function clearPortal(): void {
    try {
      sessionStorage.removeItem(STORAGE_KEY)
    } catch {
      // ignore
    }
  }

  /**
   * Returns true if the given user role is consistent with this tab's portal anchor.
   *   admin portal  → expects isAdmin === true
   *   employee portal → expects isAdmin === false
   * Returns true if no anchor is set (unanchored tabs are not restricted).
   */
  function isRoleConsistentWithPortal(isAdmin: boolean): boolean {
    const portal = getPortal()
    if (portal === null) return true
    return portal === 'admin' ? isAdmin : !isAdmin
  }

  return { getPortal, setPortal, clearPortal, isRoleConsistentWithPortal }
}
