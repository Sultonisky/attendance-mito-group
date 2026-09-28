/**
 * Swap the PWA manifest per audience so "Install app" from /outsource opens
 * the outsource page instead of the employee portal.
 *
 * index.html picks the initial manifest with the same rule; this keeps it in
 * sync after client-side navigation.
 */

export const EMPLOYEE_MANIFEST_HREF = '/manifest.webmanifest'
export const OUTSOURCE_MANIFEST_HREF = '/manifest-outsource.webmanifest'

export function isOutsourcePath(path: string): boolean {
  return path === '/outsource' || path.startsWith('/outsource/')
}

export function manifestHrefForPath(path: string): string {
  return isOutsourcePath(path) ? OUTSOURCE_MANIFEST_HREF : EMPLOYEE_MANIFEST_HREF
}

export function syncPwaManifest(path: string): void {
  if (typeof document === 'undefined') return

  const href = manifestHrefForPath(path)
  let link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')

  if (!link) {
    link = document.createElement('link')
    link.rel = 'manifest'
    link.id = 'pwa-manifest'
    document.head.appendChild(link)
  }

  if (link.getAttribute('href') !== href) {
    link.setAttribute('href', href)
  }
}
