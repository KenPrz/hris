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

/** One height for every day cell in the grid — the value that makes the month uniform. */
const CELL_HEIGHT = '7.5rem'

const ROW_STYLE: React.CSSProperties = {
  display: 'grid',
  // minmax(0, 1fr) — equal columns that can shrink; without the 0 floor a long span bar
  // would force its column wider than its siblings and the week would go ragged.
  gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
}

function chunkIntoWeeks(cells: readonly (string | null)[]): (string | null)[][] {
  const weeks: (string | null)[][] = []
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7))
  }
  return weeks
}

/**
 * A Monday-first month grid. Built on CSS grid with an explicit, identical height on every
 * day cell, so the grid is uniform by construction — a table left row height to equalize
 * from content, which drifted the moment one day had punches and its neighbours didn't.
 * ARIA grid roles keep it navigable: a screen reader still reads columns and cells.
 * Leading and trailing blanks pad the first and last week so the 1st lands in its true
 * weekday column; a day absent from `days` renders with no punches, never a fabricated one.
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
    // Seven columns don't fit a phone; the grid keeps a legible minimum width and scrolls
    // horizontally within its own container so the page never scrolls sideways.
    <div style={{ overflowX: 'auto' }}>
      <div
        role="grid"
        aria-label={`Attendance for ${month}`}
        style={{
          minWidth: '48rem',
          borderTop: '1px solid var(--hairline)',
          borderLeft: '1px solid var(--hairline)',
        }}
      >
        <div role="row" style={ROW_STYLE}>
          {WEEKDAY_LABELS.map((label) => (
            <div
              key={label}
              role="columnheader"
              style={{
                font: 'var(--t-caption)',
                letterSpacing: 'var(--ls-caption)',
                color: 'var(--ink-muted)',
                padding: 'var(--sp-xs)',
                borderRight: '1px solid var(--hairline)',
                borderBottom: '1px solid var(--hairline)',
              }}
            >
              {label}
            </div>
          ))}
        </div>

        {weeks.map((week) => (
          <div role="row" style={ROW_STYLE} key={week.find((date) => date !== null) ?? 'blank-week'}>
            {week.map((date, columnIndex) =>
              date === null ? (
                <div
                  key={`blank-${columnIndex}`}
                  aria-hidden="true"
                  style={{
                    height: CELL_HEIGHT,
                    borderRight: '1px solid var(--hairline)',
                    borderBottom: '1px solid var(--hairline)',
                  }}
                />
              ) : (
                <div
                  key={date}
                  role="gridcell"
                  style={{
                    height: CELL_HEIGHT,
                    overflow: 'hidden',
                    borderRight: '1px solid var(--hairline)',
                    borderBottom: '1px solid var(--hairline)',
                  }}
                >
                  <DayCell date={date} punches={days[date] ?? []} timeZone={timeZone} isToday={date === today} />
                </div>
              ),
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
