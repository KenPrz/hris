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
 * What the cell shows beneath the punch list, from the shared pairing rule:
 *  - `total`   — the day paired cleanly; show the worked minutes.
 *  - `open`    — an open shift on *today*: you're clocked in right now. Normal, not a
 *                warning. The hero carries the live running total; the static cell only
 *                notes the state so it doesn't look like a missing punch.
 *  - `warn`    — genuinely irregular, or an open shift on a *past* day (a forgotten
 *                clock-out). No total is invented; the day is flagged for attention.
 *  - `none`    — nothing to say (no punches, or an empty day).
 * Splitting `open`-today from the warning is the whole point: an employee is clocked in
 * all day, and a "Unpaired" warning on today's cell — contradicting the hero one row up —
 * is exactly the false alarm that split surfaced in the first browser pass.
 */
type DayStatus =
  | { kind: 'total'; minutes: number }
  | { kind: 'open' }
  | { kind: 'warn' }
  | { kind: 'none' }

function dayStatus(sortedPunches: AttendanceLog[], isToday: boolean): DayStatus {
  const pairing = pairPunches(sortedPunches)

  switch (pairing.kind) {
    case 'paired':
      return { kind: 'total', minutes: pairing.totalMinutes }
    case 'open':
      return isToday ? { kind: 'open' } : { kind: 'warn' }
    case 'unpaired':
      return { kind: 'warn' }
    case 'none':
      return { kind: 'none' }
  }
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

  const status = dayStatus(sortedPunches, isToday)

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
                {punch.direction === 'in' ? 'In' : 'Out'} {timeInZone(punch.punched_at, timeZone)}
              </span>
              {punch.verification === 'flagged' ? (
                <Tag kind="warning">{punch.flag_reason ?? 'Flagged'}</Tag>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {status.kind === 'total' ? (
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          <Duration minutes={status.minutes} />
        </span>
      ) : null}

      {status.kind === 'open' ? <Tag kind="neutral">In progress</Tag> : null}

      {status.kind === 'warn' ? <Tag kind="warning">Unpaired — no total</Tag> : null}
    </div>
  )
}
