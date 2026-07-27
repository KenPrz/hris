'use client'

/**
 * The "file overtime" form off the attendance day-detail panel (`/me/attendance`) —
 * an overtime PRE-AUTHORIZATION request (`POST /overtime/requests`, `useSubmitOvertimeRequest`).
 * Mirrors `LeaveRequestForm`'s shape: labeled Carbon inputs, submit disabled until every
 * required field is present, and a 422 surfaced via the `ApiError` this component reads off
 * the mutation's `error`. Overtime is single-hop (one approver, no manager→HR chain), so
 * there is no balance to preview here the way leave carries one — just the minutes the
 * entered hours resolve to.
 *
 * `hours` is a decimal-hours ENTRY convenience only (the wire type `OvertimeRequestInput`
 * carries `hours`, and the backend converts to integer minutes — the one authority). The
 * "overtime requested" hint decomposes `hours * 60` through `formatDuration`, the same
 * integer-minute renderer every other duration in the app uses, so the employee sees the
 * request in the system's own unit before submitting. The `0.25` step keeps entries on
 * quarter-hour boundaries, which land on whole minutes.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { OvertimeRequestInput } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { formatDuration } from '@/lib/duration'
import { useSubmitOvertimeRequest } from '@/hooks/useSubmitOvertimeRequest'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { TextInput } from '@/components/ui/TextInput'

export interface OvertimeRequestFormProps {
  /** Optional default date — the attendance panel passes the day the employee is
   * inspecting so filing overtime against it needs no re-typing. Omitted, the field
   * starts empty. */
  defaultDate?: string
  onDone: () => void
}

/** Decimal hours → whole minutes, or `null` when the entry isn't a usable positive number.
 * `0.25`-step entries always resolve to whole minutes; a stray free-typed value that
 * wouldn't (e.g. `0.1`h → `6`m is fine, but `0.01`h → `0.6`m) is rounded so the preview
 * can never hand `formatDuration` a fractional minute. */
function minutesFromHours(raw: string): number | null {
  const hours = Number(raw)
  if (raw.trim() === '' || !Number.isFinite(hours) || hours <= 0) return null
  return Math.round(hours * 60)
}

export function OvertimeRequestForm({ defaultDate, onDone }: OvertimeRequestFormProps) {
  const [date, setDate] = useState(defaultDate ?? '')
  const [hours, setHours] = useState('')
  const [note, setNote] = useState('')

  const submitMutation = useSubmitOvertimeRequest()
  const apiError = submitMutation.error instanceof ApiError ? submitMutation.error : null

  const minutes = minutesFromHours(hours)
  const isValid = date !== '' && minutes !== null && note.trim() !== ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    const input: OvertimeRequestInput = {
      date,
      hours: Number(hours),
      note,
    }

    submitMutation.mutate(input, { onSuccess: onDone })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="overtime-request-date" label="Date" type="date" value={date} onChange={setDate} />

      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <label
          htmlFor="overtime-request-hours"
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
        >
          Hours
        </label>
        <input
          id="overtime-request-hours"
          type="number"
          inputMode="decimal"
          min="0"
          step="0.25"
          value={hours}
          onChange={(event) => setHours(event.target.value)}
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

      {minutes !== null ? (
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Overtime requested: {formatDuration(minutes)}
        </span>
      ) : null}

      <TextInput id="overtime-request-note" label="Note" value={note} onChange={setNote} required />

      {apiError ? (
        <InlineNotification kind="error" title="That overtime request didn't submit.">
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
