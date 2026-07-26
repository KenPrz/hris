'use client'

/**
 * The "file a correction" form off the attendance calendar — an add/void/amend request
 * against `POST /attendance/adjustments`, submitted through `useSubmitCorrection`
 * (Task 4's mutation; it owns the multipart request and the `keys.requests.mine()` +
 * `keys.attendance.all()` invalidation on success).
 *
 * Which fields are required mirrors `SubmitAdjustmentRequest`'s `required_if` exactly:
 *   - `void`  needs `target_log_id` + `note`.
 *   - `add`   needs `direction` + `punched_at` + `note`.
 *   - `amend` needs ALL of the above — it both retargets an existing punch and restates
 *             its direction/time.
 * Submit stays disabled until the chosen operation's fields are filled in; the backend is
 * still the final authority (a 422/409 surfaces via the `ApiError` this component reads
 * off `useSubmitCorrection()`'s `error`).
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { AdjustmentOperation, AttendanceLog, CorrectionInput, PunchDirection } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { toIsoInZone, timeInZone } from '@/lib/date'
import { sortByPunchedAt } from '@/lib/punches'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { useSubmitCorrection } from '@/hooks/useSubmitCorrection'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { TextInput } from '@/components/ui/TextInput'

export interface CorrectionFormProps {
  /** The office-local `YYYY-MM-DD` this correction is filed against — combined with the
   * time field into `punched_at` for `add`/`amend`. */
  date: string
  /** That day's punches, already loaded by the caller (`useMyAttendance`) — the source
   * for the target-punch picker on `void`/`amend`. Never fetched again here. */
  punches: AttendanceLog[]
  onDone: () => void
}

const OPERATION_OPTIONS: SelectOption[] = [
  { value: 'add', label: 'Add a missing punch' },
  { value: 'void', label: 'Void a punch' },
  { value: 'amend', label: 'Amend a punch' },
]

const DIRECTION_OPTIONS: SelectOption[] = [
  { value: 'in', label: 'In' },
  { value: 'out', label: 'Out' },
]

const ACCEPTED_ATTACHMENT_TYPES = '.pdf,.jpg,.jpeg,.png'

function needsTargetPunch(operation: AdjustmentOperation): boolean {
  return operation === 'void' || operation === 'amend'
}

function needsDirectionAndTime(operation: AdjustmentOperation): boolean {
  return operation === 'add' || operation === 'amend'
}

/** `"OUT · 18:00"` — direction (upper-cased) and the punch's local time, so the picker
 * never shows a bare, unlabelled id. */
function targetPunchOptions(punches: AttendanceLog[]): SelectOption[] {
  return sortByPunchedAt(punches).map((candidate) => ({
    value: candidate.id,
    label: `${candidate.direction.toUpperCase()} · ${timeInZone(candidate.punched_at, OFFICE_TIME_ZONE)}`,
  }))
}

export function CorrectionForm({ date, punches, onDone }: CorrectionFormProps) {
  const [operation, setOperation] = useState<AdjustmentOperation>('add')
  const [targetLogId, setTargetLogId] = useState('')
  const [direction, setDirection] = useState<PunchDirection>('in')
  const [time, setTime] = useState('')
  const [note, setNote] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)

  const submitMutation = useSubmitCorrection()
  const apiError = submitMutation.error instanceof ApiError ? submitMutation.error : null

  const showTargetPunch = needsTargetPunch(operation)
  const showDirectionAndTime = needsDirectionAndTime(operation)
  const punchOptions = targetPunchOptions(punches)

  // Changing the operation swaps which fields are required; a stale 422/409 from the
  // previous attempt (e.g. "target_log_id required") no longer describes the form as it
  // now stands, so clear it rather than leave a misleading banner up.
  function updateOperation(nextValue: string): void {
    setOperation(nextValue as AdjustmentOperation)
    submitMutation.reset()
  }

  const isValid =
    note.trim() !== '' && (!showTargetPunch || targetLogId !== '') && (!showDirectionAndTime || time !== '')

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    const input: CorrectionInput = {
      operation,
      note,
      ...(showTargetPunch ? { target_log_id: targetLogId } : {}),
      ...(showDirectionAndTime
        ? { direction, punched_at: toIsoInZone(date, time, OFFICE_TIME_ZONE) }
        : {}),
      ...(attachment ? { attachment } : {}),
    }

    submitMutation.mutate(input, { onSuccess: onDone })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select
        id="correction-operation"
        label="Type"
        value={operation}
        onChange={updateOperation}
        options={OPERATION_OPTIONS}
      />

      {showTargetPunch ? (
        punchOptions.length > 0 ? (
          <Select
            id="correction-target"
            label="Punch"
            value={targetLogId}
            onChange={setTargetLogId}
            options={punchOptions}
          />
        ) : (
          <InlineNotification kind="info" title="No punches recorded for this day.">
            There is nothing to void or amend on {date} yet.
          </InlineNotification>
        )
      ) : null}

      {showDirectionAndTime ? (
        <>
          <Select
            id="correction-direction"
            label="Direction"
            value={direction}
            onChange={(value) => setDirection(value as PunchDirection)}
            options={DIRECTION_OPTIONS}
          />
          <TimeField id="correction-time" label="Time" value={time} onChange={setTime} />
        </>
      ) : null}

      <TextInput id="correction-note" label="Note" value={note} onChange={setNote} required />

      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <label
          htmlFor="correction-attachment"
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
        >
          Attachment (optional)
        </label>
        <input
          id="correction-attachment"
          type="file"
          accept={ACCEPTED_ATTACHMENT_TYPES}
          onChange={(event) => setAttachment(event.target.files?.[0] ?? null)}
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
        />
      </div>

      {apiError ? (
        <InlineNotification kind="error" title="That correction didn't submit.">
          {apiError.message}
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitMutation.isPending} disabled={submitMutation.isPending || !isValid}>
          Submit
        </Button>
      </div>
    </form>
  )
}

interface TimeFieldProps {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
}

/** A token-styled `<input type="time">` — `TextInput` only covers `text`/`email`/
 * `password`/`date` (see its own comment), so this mirrors its look directly rather than
 * stretching that component's type union for one field kind. Same pattern as
 * `DayShapeFields`'s private `TimeField`; not shared because the two live in unrelated
 * feature areas (shift shape vs. a correction's punched_at) and duplicating one small
 * input is cheaper than a forced shared abstraction between them. */
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
