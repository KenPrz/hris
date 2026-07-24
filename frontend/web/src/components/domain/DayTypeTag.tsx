'use client'

import { Tag } from '../Tag'
import type { TagKind } from '../Tag'

export type DayType =
  | 'special_working'
  | 'special_non_working'
  | 'regular_holiday'
  | 'double_regular_holiday'

export interface DayTypeTagProps {
  dayType: DayType
}

const LABEL: Record<DayType, string> = {
  special_working: 'Special working',
  special_non_working: 'Special non-working',
  regular_holiday: 'Regular holiday',
  double_regular_holiday: 'Double regular holiday',
}

// Monochrome by design (Tag has no separate "severity" palette) — kind tracks the day
// type's premium weight, lightest to heaviest: special days read neutral, a regular
// holiday and its double variant escalate through warning/error so the highest-premium
// day type is the most visually insistent one on the calendar.
const KIND: Record<DayType, TagKind> = {
  special_working: 'neutral',
  special_non_working: 'neutral',
  regular_holiday: 'warning',
  double_regular_holiday: 'error',
}

/** A holiday's day type, rendered as the existing Carbon `Tag`. */
export function DayTypeTag({ dayType }: DayTypeTagProps) {
  return <Tag kind={KIND[dayType]}>{LABEL[dayType]}</Tag>
}
