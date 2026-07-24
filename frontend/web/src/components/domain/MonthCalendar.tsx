'use client'

import type { AttendanceMonth } from '@/lib/api'
import { daysInMonth, todayInZone, weekdayIndex } from '@/lib/date'
import { DayCell } from './DayCell'

export interface MonthCalendarProps {
  month: string
  days: AttendanceMonth
  timeZone: string
}

// Monday-first, matching `weekdayIndex`'s 0=Monday convention.
const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function chunkIntoWeeks(cells: readonly (string | null)[]): (string | null)[][] {
  const weeks: (string | null)[][] = []
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7))
  }
  return weeks
}

/**
 * A Monday-first month grid of `DayCell`s, built as a real `<table>` — `<th scope="col">`
 * weekday headers so a screen reader can navigate it by column, not just a styled div
 * grid. Leading and trailing blank cells pad the first and last week to 7 columns so the
 * 1st lands in its true weekday column; a day absent from `days` renders with no punches,
 * never a fabricated one.
 */
export function MonthCalendar({ month, days, timeZone }: MonthCalendarProps) {
  const dates = daysInMonth(month)
  const leadingBlanks = weekdayIndex(dates[0])

  const cells: (string | null)[] = [...Array.from({ length: leadingBlanks }, () => null), ...dates]

  const trailingBlanks = (7 - (cells.length % 7)) % 7
  for (let i = 0; i < trailingBlanks; i++) cells.push(null)

  const weeks = chunkIntoWeeks(cells)
  const today = todayInZone(timeZone)

  return (
    // Seven columns don't fit a phone. Rather than crush the punch times into unreadable
    // slivers, the grid keeps a legible minimum width and scrolls horizontally within its
    // own container — the page itself never scrolls sideways.
    <div style={{ overflowX: 'auto' }}>
      <table className="w-full" style={{ borderCollapse: 'collapse', minWidth: '48rem' }}>
        <caption className="sr-only">{month}</caption>
      <thead>
        <tr>
          {WEEKDAY_LABELS.map((label) => (
            <th
              key={label}
              scope="col"
              style={{
                font: 'var(--t-caption)',
                letterSpacing: 'var(--ls-caption)',
                color: 'var(--ink-muted)',
                textAlign: 'left',
                padding: 'var(--sp-xs)',
                borderBottom: '1px solid var(--hairline)',
              }}
            >
              {label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {weeks.map((week) => (
          <tr key={week.find((date) => date !== null) ?? 'blank-week'}>
            {week.map((date, columnIndex) =>
              date === null ? (
                <td
                  key={`blank-${columnIndex}`}
                  aria-hidden="true"
                  style={{ border: '1px solid var(--hairline)', padding: 0 }}
                />
              ) : (
                <td key={date} style={{ border: '1px solid var(--hairline)', verticalAlign: 'top', padding: 0 }}>
                  <DayCell date={date} punches={days[date] ?? []} timeZone={timeZone} isToday={date === today} />
                </td>
              ),
            )}
          </tr>
        ))}
        </tbody>
      </table>
    </div>
  )
}
