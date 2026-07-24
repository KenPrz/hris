import type { AttendanceLog } from '@/lib/api'
import { timeInZone } from '@/lib/date'
import type { PunchSpan } from '@/lib/punches'
import { groupIntoSpans, pairPunches, sortByPunchedAt } from '@/lib/punches'
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
 * What the cell shows for the day's state, from the shared pairing rule:
 *  - `total` — the day paired cleanly; show the worked minutes.
 *  - `open`  — an open shift on *today*: you're clocked in right now. Normal, not a
 *              warning. The hero carries the live running total; the cell only notes the
 *              state so it doesn't look like a missing punch.
 *  - `warn`  — genuinely irregular, or an open shift on a *past* day (a forgotten
 *              clock-out). No total is invented; the day is flagged for attention.
 *  - `none`  — nothing to say.
 * Splitting `open`-today from the warning is the whole point: an employee is clocked in
 * all day, and a warning on today's cell — contradicting the hero one row up — is a false
 * alarm the first browser pass surfaced.
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

/** At most this many span rows draw before the rest collapse into "+N more", so every
 * cell in the grid is the same height no matter how busy the day was. */
const MAX_VISIBLE_SPANS = 3

/**
 * The month ledger's atomic unit — a fixed-height cell so the grid stays even whether a
 * day has one punch or eight. Each span reads as `08:00–17:00` (both real times, in the
 * office's zone), not a rolled-up number; a busy day shows the first few and "+N more".
 * The day's state (total, in-progress, or a warning) pins to the bottom so it lands in
 * the same place in every cell.
 */
export function DayCell({ date, punches, timeZone, isToday = false, inMonth = true }: DayCellProps) {
  const dayNumber = Number(date.slice(8, 10))

  const sortedPunches = sortByPunchedAt(punches)
  const spans = groupIntoSpans(sortedPunches)
  const visibleSpans = spans.slice(0, MAX_VISIBLE_SPANS)
  const hiddenCount = spans.length - visibleSpans.length
  const flagReasons = [
    ...new Set(
      sortedPunches
        .filter((punch) => punch.verification === 'flagged')
        .map((punch) => punch.flag_reason ?? 'Flagged'),
    ),
  ]

  const status = dayStatus(sortedPunches, isToday)

  return (
    <div
      className="flex flex-col"
      style={{
        gap: 'var(--sp-xxs)',
        padding: 'var(--sp-xs)',
        height: '7.5rem',
        overflow: 'hidden',
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

      {visibleSpans.length > 0 ? (
        <ul className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
          {visibleSpans.map((span) => (
            <li
              key={span.start.id}
              style={{
                font: 'var(--t-caption)',
                letterSpacing: 'var(--ls-caption)',
                color: 'var(--ink-muted)',
                whiteSpace: 'nowrap',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
              }}
            >
              {spanLabel(span, timeZone)}
            </li>
          ))}
        </ul>
      ) : null}

      {hiddenCount > 0 ? (
        <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}>
          +{hiddenCount} more
        </span>
      ) : null}

      <div className="flex items-center" style={{ gap: 'var(--sp-xxs)', marginTop: 'auto' }}>
        {status.kind === 'total' ? (
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
            <Duration minutes={status.minutes} />
          </span>
        ) : null}
        {status.kind === 'open' ? <Tag kind="neutral">In progress</Tag> : null}
        {status.kind === 'warn' ? <Tag kind="warning">Unpaired</Tag> : null}
        {flagReasons.length > 0 ? (
          // The full reason(s) stay on hover; the cell just marks that a punch was
          // flagged, so the month grid keeps its uniform height.
          <span title={flagReasons.join('; ')}>
            <Tag kind="warning">Flagged</Tag>
          </span>
        ) : null}
      </div>
    </div>
  )
}

/** `08:00–17:00`, or `08:00 –` for a shift still open, or a lone `Out 12:15` for a stray
 * punch that didn't pair — every time rendered in the office's zone. */
function spanLabel(span: PunchSpan, timeZone: string): string {
  const startTime = timeInZone(span.start.punched_at, timeZone)

  if (span.out !== null) {
    return `${startTime}–${timeInZone(span.out.punched_at, timeZone)}`
  }

  if (span.start.direction === 'in') {
    return `${startTime} –`
  }

  return `Out ${startTime}`
}
