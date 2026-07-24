/**
 * The one place a day's punches get sorted and paired into worked spans. Both the
 * attendance hero (`page.tsx`) and the month ledger (`DayCell.tsx`) go through this —
 * two independent implementations of the same rule is how a hero and a day cell end up
 * disagreeing about the same day's total (see the M3.5 fix-wave review).
 *
 * Presentational only — M5 owns the authoritative worked-time computation (breaks,
 * overnight spans, Art.82 exemptions, premium multipliers all live there).
 */

import type { AttendanceLog } from './api'

/** Chronological order by the punch's actual instant — the only ordering a day's punches
 * are ever shown or paired in. */
export function sortByPunchedAt(punches: AttendanceLog[]): AttendanceLog[] {
  return [...punches].sort((a, b) => new Date(a.punched_at).getTime() - new Date(b.punched_at).getTime())
}

export type PunchPairing =
  | { kind: 'none' }
  | { kind: 'paired'; totalMinutes: number }
  /** Every closed pair before the trailing punch pairs cleanly, and the day ends on a
   * lone `in` with nothing to close it yet — a shift genuinely in progress, not an
   * irregularity. `completedMinutes` is the sum of the closed pairs; `openSince` is the
   * trailing in's timestamp, for a caller that wants to add elapsed time up to now. */
  | { kind: 'open'; completedMinutes: number; openSince: string }
  /** Anything else: two `in`s in a row, an `out` before its `in`, a day ending on a
   * dangling `out`, a negative span. This layer refuses to guess at a total. */
  | { kind: 'unpaired' }

/**
 * Pairs a day's punches into in→out spans. `sortedPunches` MUST already be chronological
 * (see `sortByPunchedAt`) — this function does not sort.
 *
 * "Pairs cleanly" means: alternating in/out starting with `in`, each out strictly after
 * its in. A day can also be legitimately *open* — every punch up to a trailing lone `in`
 * pairs cleanly, and that `in` simply hasn't been closed yet. Anything else is
 * `'unpaired'`: this presentational layer does not invent a total for it.
 */
export function pairPunches(sortedPunches: AttendanceLog[]): PunchPairing {
  if (sortedPunches.length === 0) return { kind: 'none' }

  const isEven = sortedPunches.length % 2 === 0
  const closedCount = isEven ? sortedPunches.length : sortedPunches.length - 1

  let completedMinutes = 0

  for (let i = 0; i < closedCount; i += 2) {
    const inPunch = sortedPunches[i]
    const outPunch = sortedPunches[i + 1]

    if (inPunch.direction !== 'in' || outPunch.direction !== 'out') return { kind: 'unpaired' }

    const spanMs = new Date(outPunch.punched_at).getTime() - new Date(inPunch.punched_at).getTime()
    if (spanMs < 0) return { kind: 'unpaired' }

    // Punch timestamps carry seconds; worked time is integer minutes everywhere, so a
    // presentational span rounds to the nearest whole minute. M5's authoritative
    // computation defines the real rounding rule — this is display only.
    completedMinutes += Math.round(spanMs / 60_000)
  }

  if (isEven) return { kind: 'paired', totalMinutes: completedMinutes }

  const trailing = sortedPunches[sortedPunches.length - 1]
  if (trailing.direction !== 'in') return { kind: 'unpaired' }

  return { kind: 'open', completedMinutes, openSince: trailing.punched_at }
}

/** One row in a compact ledger: an `in`→`out` span, an open `in` (`out === null`), or a
 * stray punch that didn't pair (`start` carries its own direction). */
export type PunchSpan = { start: AttendanceLog; out: AttendanceLog | null }

/**
 * Shapes a day's punches into the spans a month cell draws — `08:00–17:00` on one row
 * instead of an `in` line and an `out` line. Greedy: a consecutive `in`,`out` becomes one
 * span; anything that doesn't pair (a lone trailing `in`, a stray `out`, `in` then `in`)
 * is emitted on its own so every raw time still shows. This only decides what's *drawn* —
 * it never produces a total. `pairPunches` remains the one place a number comes from, and
 * the two agree because they walk the same chronological order.
 */
export function groupIntoSpans(sortedPunches: AttendanceLog[]): PunchSpan[] {
  const spans: PunchSpan[] = []

  for (let i = 0; i < sortedPunches.length; ) {
    const start = sortedPunches[i]
    const next = sortedPunches[i + 1]

    if (start.direction === 'in' && next?.direction === 'out') {
      spans.push({ start, out: next })
      i += 2
    } else {
      spans.push({ start, out: null })
      i += 1
    }
  }

  return spans
}
