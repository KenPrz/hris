/**
 * Basis points ↔ percent — the display-only conversion for `*_bp` fields (`worked_bp`,
 * `overtime_ordinary_bp`, `night_diff_bp`, …). The wire and the domain layer never see a
 * percent: every multiplier is an integer basis point (1 bp = 0.01%), same discipline as
 * `Money` (centavos) and `Minutes` (integer minutes) — see docs/01-architecture.md. This
 * module exists only so a screen can show an admin "125" instead of "12500".
 *
 * `percentToBp` rounds to the nearest integer bp: a percent field on the wire is still an
 * integer bp underneath, and `Math.round` is what keeps a repeating-decimal percent
 * (entered by a human, or computed via floating point on the way here) from producing a
 * fractional bp the backend's `integer` column cannot hold.
 */

export function bpToPercent(bp: number): number {
  return bp / 100
}

export function percentToBp(percent: number): number {
  return Math.round(percent * 100)
}
