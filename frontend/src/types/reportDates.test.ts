import { describe, expect, it } from 'vitest'
import { defaultReportDates } from './reportDates'

function toDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day)
}

describe('defaultReportDates', () => {
  it('returns from/to in YYYY-MM-DD format', () => {
    const { from, to } = defaultReportDates()

    expect(from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(to).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('defaults to a 30-day window ending today', () => {
    const { from, to } = defaultReportDates()
    const diffDays = Math.round(
      (toDate(to).getTime() - toDate(from).getTime()) / (24 * 60 * 60 * 1000),
    )

    expect(diffDays).toBe(30)
    expect(toDate(to).toDateString()).toBe(new Date().toDateString())
  })
})
