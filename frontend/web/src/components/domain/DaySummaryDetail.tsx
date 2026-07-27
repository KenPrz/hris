'use client'

import type { DailySummary, DailySummaryLine, SummaryLineKind } from '@/lib/api'
import { Tag } from '../Tag'
import { Duration } from './Duration'

export interface DaySummaryDetailProps {
  /** `undefined` for a day with no computed summary — a day the compute engine hasn't
   * priced yet, or a day outside the employee's own attendance. Rendered as nothing. */
  summary: DailySummary | undefined
}

const LINE_LABEL: Record<SummaryLineKind, string> = {
  regular_day: 'Regular (day)',
  regular_night: 'Regular (night)',
  overtime_day: 'Overtime (day)',
  overtime_night: 'Overtime (night)',
  holiday_unworked: 'Holiday (unworked)',
  leave_with_pay: 'Leave (with pay)',
}

// applied_bp is basis points of the ordinary rate; 10000bp === 100%. A line above that is
// a premium (night differential, overtime, holiday) rather than a straight hour.
const PREMIUM_THRESHOLD_BP = 10_000

// Exported so `DaySummaryIndicator` — the compact in-cell footprint of this same computed
// layer — can badge a day identically without re-deriving the rule twice.
export function hasOvertimeLine(lines: DailySummaryLine[]): boolean {
  return lines.some((line) => line.kind === 'overtime_day' || line.kind === 'overtime_night')
}

export function hasPremiumLine(lines: DailySummaryLine[]): boolean {
  return lines.some((line) => line.applied_bp > PREMIUM_THRESHOLD_BP)
}

/** `10000` -> `"100"`, `12500` -> `"125"` — basis points to a percent, for the breakdown. */
function bpToPercent(bp: number): string {
  return String(bp / 100)
}

/**
 * One day's COMPUTED breakdown — the priced total, its badges, and the line items the
 * compute engine produced (`DailySummaryResource`'s `lines`). Additive to, never a
 * replacement for, the raw punch ledger a day cell already shows: this is what the punches
 * turned into, not a different record of what happened.
 */
export function DaySummaryDetail({ summary }: DaySummaryDetailProps) {
  if (summary === undefined) return null

  const { worked_minutes, lines, is_incomplete, unpaid_overtime_minutes } = summary
  const overtime = hasOvertimeLine(lines)
  const premium = hasPremiumLine(lines)
  const hasUnpaidExcess = unpaid_overtime_minutes > 0

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
      <div className="flex items-center flex-wrap" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          <Duration minutes={worked_minutes} />
        </span>
        {is_incomplete ? <Tag kind="warning">incomplete</Tag> : null}
        {overtime ? <Tag kind="neutral">OT</Tag> : null}
        {premium ? <Tag kind="neutral">premium</Tag> : null}
        {hasUnpaidExcess ? <Tag kind="warning">unpaid OT</Tag> : null}
      </div>

      {hasUnpaidExcess ? (
        // Overtime worked beyond what was pre-authorized: the compute engine records it but
        // prices none of it (M6c — no authorization, no premium). Surfaced as a muted line
        // so the employee sees WHY the priced total is short of the hours they were at their
        // desk, styled like the line-item rows below rather than as a priced line itself.
        <div
          className="flex items-center justify-between"
          style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-muted)' }}
        >
          <span>Unpaid excess</span>
          <span>
            <Duration minutes={unpaid_overtime_minutes} />
          </span>
        </div>
      ) : null}

      {lines.length > 0 ? (
        <ul className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
          {lines.map((line) => (
            <li
              key={line.kind}
              className="flex items-center justify-between"
              style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-muted)' }}
            >
              <span>{LINE_LABEL[line.kind]}</span>
              <span>
                <Duration minutes={line.minutes} /> · {bpToPercent(line.applied_bp)}%
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
