'use client'

/**
 * Renders a `LeaveBalance.balance_readable` decomposition as prose — "5 days", "1 day 1 hr
 * 15 min", "0 days" for an empty balance. Deliberately takes the ALREADY-decomposed
 * `{days, hours, minutes}` the backend sent (`LeaveUnit::readable`, mirrored in
 * `LeaveBalance`) rather than a raw minute count: recomputing the split client-side would
 * be a second implementation of the same day-length math `LeaveUnit` already owns, and the
 * two could silently drift (e.g. if an office's `minutes_per_leave_day` changes).
 */

export interface LeaveReadable {
  days: number
  hours: number
  minutes: number
}

export interface LeaveDurationProps {
  readable: LeaveReadable
}

function formatReadable({ days, hours, minutes }: LeaveReadable): string {
  // A wholly-zero balance is still worth naming explicitly — an empty `parts` array would
  // render nothing at all, which reads as a bug, not "zero."
  if (days === 0 && hours === 0 && minutes === 0) return '0 days'

  const parts: string[] = []
  if (days > 0) parts.push(`${days} day${days === 1 ? '' : 's'}`)
  if (hours > 0) parts.push(`${hours} hr`)
  if (minutes > 0) parts.push(`${minutes} min`)
  return parts.join(' ')
}

export function LeaveDuration({ readable }: LeaveDurationProps) {
  return (
    <span style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
      {formatReadable(readable)}
    </span>
  )
}
