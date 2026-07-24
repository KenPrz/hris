/**
 * Wall-clock minutes-from-midnight <-> `HH:MM`, for `ShiftDay.start_minute` /
 * `end_minute` — the direct counterpart to `duration.ts`'s *elapsed* minutes, but this
 * module is about a clock reading, not a span. Deliberately separate from
 * `duration.ts` (which formats a length like `7h 30m`) and `date.ts` (which is
 * calendar dates, not times-of-day); neither had a suitable helper to extend.
 *
 * `end_minute` can be stored >=1440 for a shift that crosses midnight (M4b's
 * `WeekEditor` writes `hhmmToMinutes(end) + 1440` when the "crosses midnight" checkbox
 * is on) — `minutesToHHMM` stays pure by wrapping any such value back onto the wall
 * clock; the "+1 day" fact is the editor's concern to display, not this formatter's.
 */

function assertWholeMinute(minutes: number): void {
  if (!Number.isInteger(minutes)) {
    throw new Error(`A minute-of-day is an integer; got ${minutes}.`)
  }
  if (minutes < 0) {
    throw new Error(`A minute-of-day cannot be negative; got ${minutes}.`)
  }
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** `90` -> `'01:30'`, `480` -> `'08:00'`, `0` -> `'00:00'`. A value >=1440 (a stored
 * cross-midnight `end_minute`) wraps onto the wall clock: `1620` -> `'03:00'`. */
export function minutesToHHMM(minutes: number): string {
  assertWholeMinute(minutes)

  const wallClockMinute = minutes % 1440
  const hours = Math.floor(wallClockMinute / 60)
  const remainder = wallClockMinute % 60

  return `${pad2(hours)}:${pad2(remainder)}`
}

/** `'08:00'` -> `480`, `'00:00'` -> `0`. Inverse of `minutesToHHMM` for wall-clock
 * (0..1439) values. */
export function hhmmToMinutes(hhmm: string): number {
  const [hoursPart, minutesPart] = hhmm.split(':')
  return Number(hoursPart) * 60 + Number(minutesPart)
}
