'use client'

import { formatDuration } from '@/lib/duration'

export interface DurationProps {
  minutes: number
}

/**
 * The one place integer minutes become human text in a component — trivial by design,
 * a direct mirror of `lib/duration.ts`. No styling of its own: it is always embedded in
 * a parent (`DayCell`, a stat tile) that sets the typographic context around it.
 */
export function Duration({ minutes }: DurationProps) {
  return <span>{formatDuration(minutes)}</span>
}
