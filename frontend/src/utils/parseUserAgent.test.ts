import { describe, expect, it } from 'vitest'
import { parseUserAgent } from './parseUserAgent'

describe('parseUserAgent', () => {
  it('summarizes Chrome on Windows', () => {
    const ua =
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36'

    expect(parseUserAgent(ua)).toEqual({
      label: 'Chrome 153 · Windows 10/11',
      browser: 'Chrome 153',
      os: 'Windows 10/11',
    })
  })

  it('summarizes Firefox on Linux', () => {
    const ua =
      'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0'

    expect(parseUserAgent(ua).label).toBe('Firefox 128 · Linux')
  })

  it('prefers Edge over Chrome token', () => {
    const ua =
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'

    expect(parseUserAgent(ua).browser).toBe('Edge 120')
  })

  it('summarizes Safari on iOS', () => {
    const ua =
      'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'

    expect(parseUserAgent(ua).label).toBe('Safari 17 · iOS 17')
  })

  it('returns dash for empty input', () => {
    expect(parseUserAgent(null).label).toBe('—')
    expect(parseUserAgent('   ').label).toBe('—')
  })
})
