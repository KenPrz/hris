/**
 * The one place integer minutes become human text — the direct mirror of the backend's
 * `App\Domain\Time\Minutes`.
 *
 * Worked time is integer minutes everywhere, in every layer. `7h 20m` is 7.333… hours,
 * and a shift is not a number you may round twice; JavaScript's `number` is IEEE-754,
 * so a decimal-hours value passing through the browser would be exactly the drift this
 * system exists to prevent. See docs/01-architecture.md.
 */

function assertWholeMinutes(minutes: number): void {
  if (!Number.isInteger(minutes)) {
    throw new Error(`Worked time is integer minutes; got ${minutes}.`)
  }
  if (minutes < 0) {
    throw new Error(`A duration cannot be negative; got ${minutes}.`)
  }
}

/** `450` → `"7h 30m"`, `60` → `"1h"`, `45` → `"45m"`, `0` → `"0m"`. */
export function formatDuration(minutes: number): string {
  assertWholeMinutes(minutes)

  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60

  if (hours === 0) return `${remainder}m`
  if (remainder === 0) return `${hours}h`

  return `${hours}h ${remainder}m`
}

/**
 * Decimal hours, two places — for payroll export columns and nothing else.
 *
 * Deliberately separate from `formatDuration` and deliberately awkward to reach for:
 * this is the only representation in the system where a duration is not integer
 * minutes, and it exists solely because external payroll formats demand it.
 */
export function formatDurationDecimal(minutes: number): string {
  assertWholeMinutes(minutes)

  return (minutes / 60).toFixed(2)
}
