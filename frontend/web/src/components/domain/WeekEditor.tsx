'use client'

import type { ShiftDay, Weekday } from '@/lib/api'
import { hhmmToMinutes, minutesToHHMM } from '@/lib/minutes'

export interface WeekEditorProps {
  value: ShiftDay[]
  onChange: (next: ShiftDay[]) => void
}

// 0 = Monday .. 6 = Sunday, matching `weekdayIndex` in `lib/date.ts` and the
// `ShiftDay.weekday` wire convention.
const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

// What a rest day just switched to "working" starts as — a plain 08:00-17:00 with an
// hour's break. Arbitrary but unsurprising; the admin edits it immediately.
const DEFAULT_START_MINUTE = 480
const DEFAULT_END_MINUTE = 1020
const DEFAULT_BREAK_MINUTES = 60

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
 * A working row's `end_minute` can land >=1440 when "crosses midnight" is checked
 * (`hhmmToMinutes(end) + 1440`) — that is read back out of the stored value, not tracked
 * as separate component state, so toggling the checkbox is the only place the +1440 is
 * added or removed.
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
  const restId = `week-editor-${label}-rest`
  const startId = `week-editor-${label}-start`
  const endId = `week-editor-${label}-end`
  const breakId = `week-editor-${label}-break`
  const crossesMidnightId = `week-editor-${label}-crosses-midnight`

  const crossesMidnight = day.end_minute !== null && day.end_minute >= 1440
  const startHHMM = day.start_minute !== null ? minutesToHHMM(day.start_minute) : ''
  const endHHMM = day.end_minute !== null ? minutesToHHMM(day.end_minute) : ''

  function setRest(isRest: boolean): void {
    if (isRest) {
      onChange({ weekday: day.weekday, is_rest: true, start_minute: null, end_minute: null, break_minutes: null })
      return
    }
    onChange({
      weekday: day.weekday,
      is_rest: false,
      start_minute: DEFAULT_START_MINUTE,
      end_minute: DEFAULT_END_MINUTE,
      break_minutes: DEFAULT_BREAK_MINUTES,
    })
  }

  function setStart(nextHHMM: string): void {
    if (nextHHMM === '') return
    onChange({ ...day, is_rest: false, start_minute: hhmmToMinutes(nextHHMM) })
  }

  function setEnd(nextHHMM: string): void {
    if (nextHHMM === '') return
    onChange({
      ...day,
      is_rest: false,
      end_minute: hhmmToMinutes(nextHHMM) + (crossesMidnight ? 1440 : 0),
    })
  }

  function setBreakMinutes(nextValue: string): void {
    const parsed = Number(nextValue)
    onChange({ ...day, is_rest: false, break_minutes: Number.isNaN(parsed) ? 0 : parsed })
  }

  function setCrossesMidnight(next: boolean): void {
    // Reapply the +1440 rule to the current end wall-clock time, not to a copy of the
    // checkbox's previous state — end_minute is the only source of truth for it.
    const endWallClock = day.end_minute !== null ? day.end_minute % 1440 : 0
    onChange({ ...day, is_rest: false, end_minute: endWallClock + (next ? 1440 : 0) })
  }

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

      <label
        htmlFor={restId}
        className="flex items-center"
        style={{ gap: 'var(--sp-xxs)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
      >
        <input
          id={restId}
          type="checkbox"
          checked={day.is_rest}
          onChange={(event) => setRest(event.target.checked)}
          style={{ accentColor: 'var(--blue)' }}
        />
        {`${label} rest day`}
      </label>

      {!day.is_rest ? (
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
              value={day.break_minutes ?? 0}
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
    </div>
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
 * type union for one field kind used nowhere else yet. */
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
