'use client'

import type { ShiftDay } from '@/lib/api'
import { hhmmToMinutes, minutesToHHMM } from '@/lib/minutes'

/** The hours/rest/break shape a single calendar day carries, independent of what owns the
 * day — a weekday slot in a `ShiftTemplate` (`WeekEditor`) or a single date's
 * `ScheduleOverride` (the `/office/schedules` resolved-calendar Dialog, M4b Task 14). */
export type DayShape = Pick<ShiftDay, 'is_rest' | 'start_minute' | 'end_minute' | 'break_minutes'>

export interface DayShapeFieldsProps {
  /** Used both as the visible field labels (`"Mon start"`, `"Override start"`, ...) and to
   * build unique element ids — must be unique among every `DayShapeFields` rendered at the
   * same time (`WeekEditor` renders seven, one per weekday). */
  label: string
  value: DayShape
  onChange: (next: DayShape) => void
}

// What a rest day just switched to "working" starts as — a plain 08:00-17:00 with an
// hour's break. Arbitrary but unsurprising; the admin edits it immediately.
const DEFAULT_START_MINUTE = 480
const DEFAULT_END_MINUTE = 1020
const DEFAULT_BREAK_MINUTES = 60

/**
 * One day's is_rest/hours/break/crosses-midnight fields — extracted out of `WeekEditor`'s
 * per-row internals (M4b Task 14) so the single-day override editor and `WeekEditor`'s
 * seven weekday rows share one implementation of the crosses-midnight math instead of two
 * copies that could drift apart.
 *
 * Fully controlled, like `WeekEditor` itself: every edit computes the whole next
 * `DayShape` and hands it to `onChange`. A working day's `end_minute` can land >=1440 when
 * "crosses midnight" is checked (`hhmmToMinutes(end) + 1440`) — read back out of the
 * stored value, not tracked as separate state, so toggling the checkbox is the only place
 * the +1440 is added or removed.
 */
export function DayShapeFields({ label, value, onChange }: DayShapeFieldsProps) {
  const restId = `day-shape-${label}-rest`
  const startId = `day-shape-${label}-start`
  const endId = `day-shape-${label}-end`
  const breakId = `day-shape-${label}-break`
  const crossesMidnightId = `day-shape-${label}-crosses-midnight`

  const crossesMidnight = value.end_minute !== null && value.end_minute >= 1440
  const startHHMM = value.start_minute !== null ? minutesToHHMM(value.start_minute) : ''
  const endHHMM = value.end_minute !== null ? minutesToHHMM(value.end_minute) : ''

  function setRest(isRest: boolean): void {
    if (isRest) {
      onChange({ is_rest: true, start_minute: null, end_minute: null, break_minutes: null })
      return
    }
    onChange({
      is_rest: false,
      start_minute: DEFAULT_START_MINUTE,
      end_minute: DEFAULT_END_MINUTE,
      break_minutes: DEFAULT_BREAK_MINUTES,
    })
  }

  function setStart(nextHHMM: string): void {
    if (nextHHMM === '') return
    onChange({ ...value, is_rest: false, start_minute: hhmmToMinutes(nextHHMM) })
  }

  function setEnd(nextHHMM: string): void {
    if (nextHHMM === '') return
    onChange({
      ...value,
      is_rest: false,
      end_minute: hhmmToMinutes(nextHHMM) + (crossesMidnight ? 1440 : 0),
    })
  }

  function setBreakMinutes(nextValue: string): void {
    const parsed = Number(nextValue)
    onChange({ ...value, is_rest: false, break_minutes: Number.isNaN(parsed) ? 0 : parsed })
  }

  function setCrossesMidnight(next: boolean): void {
    // Reapply the +1440 rule to the current end wall-clock time, not to a copy of the
    // checkbox's previous state — end_minute is the only source of truth for it.
    const endWallClock = value.end_minute !== null ? value.end_minute % 1440 : 0
    onChange({ ...value, is_rest: false, end_minute: endWallClock + (next ? 1440 : 0) })
  }

  return (
    <>
      <label
        htmlFor={restId}
        className="flex items-center"
        style={{ gap: 'var(--sp-xxs)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
      >
        <input
          id={restId}
          type="checkbox"
          checked={value.is_rest}
          onChange={(event) => setRest(event.target.checked)}
          style={{ accentColor: 'var(--blue)' }}
        />
        {`${label} rest day`}
      </label>

      {!value.is_rest ? (
        <>
          <TimeField id={startId} label={`${label} start`} value={startHHMM} onChange={setStart} />
          <TimeField id={endId} label={`${label} end`} value={endHHMM} onChange={setEnd} />

          <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
            <label
              htmlFor={breakId}
              style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
            >
              {`${label} break minutes`}
            </label>
            <input
              id={breakId}
              type="number"
              min={0}
              step={1}
              value={value.break_minutes ?? 0}
              onChange={(event) => setBreakMinutes(event.target.value)}
              className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
              style={{
                background: 'var(--surface-1)',
                color: 'var(--ink)',
                border: 'none',
                borderBottom: '1px solid var(--field-border)',
                borderRadius: 'var(--radius)',
                padding: 'calc(var(--sp-sm) - 1px) var(--sp-md)',
                font: 'var(--t-body)',
                letterSpacing: 'var(--ls-body)',
                width: '8ch',
              }}
            />
          </div>

          <label
            htmlFor={crossesMidnightId}
            className="flex items-center"
            style={{ gap: 'var(--sp-xxs)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
          >
            <input
              id={crossesMidnightId}
              type="checkbox"
              checked={crossesMidnight}
              onChange={(event) => setCrossesMidnight(event.target.checked)}
              style={{ accentColor: 'var(--blue)' }}
            />
            {`${label} crosses midnight`}
          </label>

          {crossesMidnight ? (
            <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}>
              +1 day
            </span>
          ) : null}
        </>
      ) : null}
    </>
  )
}

interface TimeFieldProps {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
}

/** A token-styled `<input type="time">` — `TextInput` only covers `text`/`email`/
 * `password`, so this mirrors its look directly rather than stretching that component's
 * type union for one field kind used nowhere else. */
function TimeField({ id, label, value, onChange }: TimeFieldProps) {
  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
      <label htmlFor={id} style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {label}
      </label>
      <input
        id={id}
        type="time"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
        style={{
          background: 'var(--surface-1)',
          color: 'var(--ink)',
          border: 'none',
          borderBottom: '1px solid var(--field-border)',
          borderRadius: 'var(--radius)',
          padding: 'calc(var(--sp-sm) - 1px) var(--sp-md)',
          font: 'var(--t-body)',
          letterSpacing: 'var(--ls-body)',
        }}
      />
    </div>
  )
}
