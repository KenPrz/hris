'use client'

import type { AttendanceLog } from '@/lib/api'
import { timeInZone } from '@/lib/date'
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
 * Presentational pairing only — M5 owns the authoritative worked-time computation
 * (breaks, overnight spans, Art.82 exemptions, premium multipliers all live there).
 * This exists solely so a cleanly-paired day can show a total next to its punches.
 *
 * "Pairs cleanly" means: an even number of punches, sorted chronologically, alternating
 * in/out starting with `in` (punches[0]=in, punches[1]=out, punches[2]=in, ...), with
 * each out strictly after its in. Anything else — an odd count, two `in`s in a row, an
 * out before its in — is not a rule this presentational layer is allowed to guess at,
 * so it returns `null` and the caller shows the punches without a total.
 */
function pairedTotalMinutes(sortedPunches: AttendanceLog[]): number | null {
  if (sortedPunches.length === 0 || sortedPunches.length % 2 !== 0) return null

  let totalMinutes = 0

  for (let i = 0; i < sortedPunches.length; i += 2) {
    const inPunch = sortedPunches[i]
    const outPunch = sortedPunches[i + 1]

    if (inPunch.direction !== 'in' || outPunch.direction !== 'out') return null

    const spanMs = new Date(outPunch.punched_at).getTime() - new Date(inPunch.punched_at).getTime()
    if (spanMs < 0) return null

    // Punch timestamps carry seconds; worked time is integer minutes everywhere, so a
    // presentational span rounds to the nearest whole minute. M5's authoritative
    // computation defines the real rounding rule — this is display only.
    totalMinutes += Math.round(spanMs / 60_000)
  }

  return totalMinutes
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

  const sortedPunches = [...punches].sort(
    (a, b) => new Date(a.punched_at).getTime() - new Date(b.punched_at).getTime(),
  )

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
