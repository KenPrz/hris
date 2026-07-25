'use client'

import type { DailySummary } from '@/lib/api'
import { Tag } from '../Tag'
import { Duration } from './Duration'
import { hasOvertimeLine, hasPremiumLine } from './DaySummaryDetail'

export interface DaySummaryIndicatorProps {
  /** `undefined` for a day with no computed summary — a day the compute engine hasn't
   * priced yet, or a day outside the employee's own attendance. Rendered as nothing, same
   * rule as `DaySummaryDetail`. */
  summary: DailySummary | undefined
}

/**
 * The computed layer's footprint INSIDE a calendar cell: one caption-sized line with the
 * worked total and its badges — small enough to sit alongside `DayCell`'s raw punches
 * within the grid's fixed, clipped cell height (see `MonthCalendar`'s `CELL_HEIGHT`).
 * Deliberately not the full breakdown — `DaySummaryDetail` owns that, and lives in the
 * day-detail panel below the calendar where there's no clip boundary to hide behind.
 * Shares `hasOvertimeLine`/`hasPremiumLine` with `DaySummaryDetail` so the two views of the
 * same summary can never disagree about which badges apply.
 */
export function DaySummaryIndicator({ summary }: DaySummaryIndicatorProps) {
  if (summary === undefined) return null

  const { worked_minutes, lines, is_incomplete } = summary
  const overtime = hasOvertimeLine(lines)
  const premium = hasPremiumLine(lines)

  return (
    <div
      className="flex items-center flex-wrap"
      style={{
        gap: 'var(--sp-xxs)',
        font: 'var(--t-caption)',
        letterSpacing: 'var(--ls-caption)',
        color: 'var(--ink)',
        overflow: 'hidden',
      }}
    >
      <Duration minutes={worked_minutes} />
      {is_incomplete ? <Tag kind="warning">incomplete</Tag> : null}
      {overtime ? <Tag kind="neutral">OT</Tag> : null}
      {premium ? <Tag kind="neutral">premium</Tag> : null}
    </div>
  )
}
