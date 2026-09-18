export function defaultReportDates(): { from: string; to: string } {
  const today = new Date()
  const from = new Date(today)
  from.setDate(today.getDate() - 30)

  const format = (date: Date): string => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
  }

  return { from: format(from), to: format(today) }
}
