/**
 * Lightweight User-Agent summarizer for audit UI.
 * Keeps the raw string elsewhere; this only produces a short human label.
 */

export type ParsedUserAgent = {
  label: string
  browser: string | null
  os: string | null
}

export function parseUserAgent(ua: string | null | undefined): ParsedUserAgent {
  const raw = (ua ?? '').trim()
  if (raw === '') {
    return { label: '—', browser: null, os: null }
  }

  const browser = detectBrowser(raw)
  const os = detectOs(raw)

  if (browser && os) {
    return { label: `${browser} · ${os}`, browser, os }
  }
  if (browser) {
    return { label: browser, browser, os: null }
  }
  if (os) {
    return { label: os, browser: null, os }
  }

  return { label: 'Unknown client', browser: null, os: null }
}

function detectBrowser(ua: string): string | null {
  // Order matters: more specific clients before generic Chromium/Safari tokens.
  const rules: Array<{ name: string; pattern: RegExp }> = [
    { name: 'Edge', pattern: /Edg(?:e|A|iOS)?\/([\d.]+)/i },
    { name: 'Opera', pattern: /(?:OPR|Opera)\/([\d.]+)/i },
    { name: 'Samsung Internet', pattern: /SamsungBrowser\/([\d.]+)/i },
    { name: 'Firefox', pattern: /Firefox\/([\d.]+)/i },
    { name: 'Chrome', pattern: /(?:Chrome|CriOS)\/([\d.]+)/i },
    { name: 'Safari', pattern: /Version\/([\d.]+).*Safari\//i },
  ]

  for (const rule of rules) {
    const match = ua.match(rule.pattern)
    if (!match) continue
    const major = majorVersion(match[1] ?? '')
    return major ? `${rule.name} ${major}` : rule.name
  }

  return null
}

function detectOs(ua: string): string | null {
  const android = ua.match(/Android ([\d.]+)/i)
  if (android) {
    const major = majorVersion(android[1] ?? '')
    return major ? `Android ${major}` : 'Android'
  }

  if (/iPhone|iPad|iPod/i.test(ua)) {
    const match = ua.match(/OS ([\d_]+)/i)
    const major = match ? majorVersion((match[1] ?? '').replaceAll('_', '.')) : null
    return major ? `iOS ${major}` : 'iOS'
  }

  if (/Windows NT 10\.0/i.test(ua)) return 'Windows 10/11'
  if (/Windows NT 6\.3/i.test(ua)) return 'Windows 8.1'
  if (/Windows NT 6\.2/i.test(ua)) return 'Windows 8'
  if (/Windows NT 6\.1/i.test(ua)) return 'Windows 7'
  if (/Windows NT/i.test(ua)) return 'Windows'

  const mac = ua.match(/Mac OS X ([\d_]+)/i)
  if (mac) {
    const major = majorVersion((mac[1] ?? '').replaceAll('_', '.'))
    return major ? `macOS ${major}` : 'macOS'
  }

  if (/CrOS/i.test(ua)) return 'Chrome OS'
  if (/Linux/i.test(ua)) return 'Linux'

  return null
}

function majorVersion(version: string): string | null {
  const major = version.split('.')[0]?.trim()
  return major && /^\d+$/.test(major) ? major : null
}
