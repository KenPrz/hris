'use client'

import type { AttendanceLog } from '@/lib/api'
import { timeInZone } from '@/lib/date'
import { pairPunches, sortByPunchedAt } from '@/lib/punches'
import { Tag } from '../Tag'
import { Duration } from './Duration'

export interface DayCellProps {
  date: string
  punches: AttendanceLog[]
  timeZone: string
  isToday?: boolean
  inMonth?: boolean
}

/**
 * A total only renders when the day pairs cleanly (`pairPunches` returns `'paired'`).
 * A day that's still open (a trailing, uncosed `in`) or genuinely irregular both render
 * with no total here — the ledger is never occluded, but this layer is never allowed to
 * invent a number for either case. (The attendance hero adds a *live* total for the
 * open case, because it re-renders every second and knows "now"; a static day cell does
 * not, so it stays silent rather than show a total that's already stale.)
 */
function pairedTotalMinutes(sortedPunches: AttendanceLog[]): number | null {
  const pairing = pairPunches(sortedPunches)
  return pairing.kind === 'paired' ? pairing.totalMinutes : null
}

/**
 * The month ledger's atomic unit. Renders each punch's actual clock time — `in 08:02`,
 * `out 17:05` — because the raw punch log, not a rolled-up total, is the record a DOLE
 * inspector is shown. The total is a convenience layered on top when the day pairs
 * cleanly; when it doesn't (a missing clock-out is common and real), the punches still
 * render and the total is honestly omitted rather than guessed.
 */
export function DayCell({ date, punches, timeZone, isToday = false, inMonth = true }: DayCellProps) {
  const dayNumber = Number(date.slice(8, 10))

  const sortedPunches = sortByPunchedAt(punches)

  const totalMinutes = pairedTotalMinutes(sortedPunches)
  const isUnpaired = sortedPunches.length > 0 && totalMinutes === null

  return (
    <div
      className="flex h-full flex-col"
      style={{
        gap: 'var(--sp-xxs)',
        padding: 'var(--sp-xs)',
        minHeight: '96px',
        background: isToday ? 'var(--surface-1)' : 'transparent',
        opacity: inMonth ? 1 : 0.4,
      }}
    >
      <span
        style={{
          font: isToday ? 'var(--t-emphasis)' : 'var(--t-body-sm)',
          letterSpacing: 'var(--ls-body)',
          color: inMonth ? 'var(--ink)' : 'var(--ink-subtle)',
        }}
      >
        {dayNumber}
      </span>

      {sortedPunches.length > 0 ? (
        <ul className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
          {sortedPunches.map((punch) => (
            <li key={punch.id} className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
              <span
                style={{
                  font: 'var(--t-caption)',
                  letterSpacing: 'var(--ls-caption)',
                  color: 'var(--ink-muted)',
                }}
              >
                {punch.direction} {timeInZone(punch.punched_at, timeZone)}
              </span>
              {punch.verification === 'flagged' ? (
                <Tag kind="warning">{punch.flag_reason ?? 'Flagged'}</Tag>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {totalMinutes !== null ? (
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          <Duration minutes={totalMinutes} />
        </span>
      ) : null}

      {isUnpaired ? <Tag kind="warning">Unpaired — no total</Tag> : null}
    </div>
  )
}
