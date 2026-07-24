'use client'

import type { ShiftDay, Weekday } from '@/lib/api'
import { DayShapeFields } from './DayShapeFields'

export interface WeekEditorProps {
  value: ShiftDay[]
  onChange: (next: ShiftDay[]) => void
}

// 0 = Monday .. 6 = Sunday, matching `weekdayIndex` in `lib/date.ts` and the
// `ShiftDay.weekday` wire convention.
const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function shiftDayFor(value: ShiftDay[], weekday: Weekday): ShiftDay {
  const day = value.find((candidate) => candidate.weekday === weekday)
  if (!day) {
    throw new Error(`WeekEditor: value is missing weekday ${weekday}.`)
  }
  return day
}

/**
 * A shift template's 7-day week, Mon..Sun, one row per day. Fully controlled — every
 * edit computes the whole next `ShiftDay[]` and hands it to `onChange`; the component
 * holds no state of its own beyond what the browser's native `<input>` elements keep
 * for in-progress typing.
 *
 * Each row's is_rest/hours/break/crosses-midnight fields are `DayShapeFields` (M4b Task
 * 14 extracted them out of this component so the `/office/schedules` resolved-calendar's
 * single-day override editor could reuse the same crosses-midnight math) — this component
 * now only owns the Mon..Sun row shell and the weekday label.
 */
export function WeekEditor({ value, onChange }: WeekEditorProps) {
  function updateDay(next: ShiftDay): void {
    onChange(value.map((day) => (day.weekday === next.weekday ? next : day)))
  }

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
      {WEEKDAY_LABELS.map((label, index) => (
        <WeekEditorRow
          key={label}
          label={label}
          day={shiftDayFor(value, index as Weekday)}
          onChange={updateDay}
        />
      ))}
    </div>
  )
}

interface WeekEditorRowProps {
  label: string
  day: ShiftDay
  onChange: (next: ShiftDay) => void
}

function WeekEditorRow({ label, day, onChange }: WeekEditorRowProps) {
  return (
    <div
      className="flex items-center flex-wrap"
      style={{
        gap: 'var(--sp-md)',
        padding: 'var(--sp-xs) 0',
        borderBottom: '1px solid var(--hairline)',
      }}
    >
      <span
        style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)', width: '3ch' }}
      >
        {label}
      </span>

      <DayShapeFields label={label} value={day} onChange={(next) => onChange({ weekday: day.weekday, ...next })} />
    </div>
  )
}
