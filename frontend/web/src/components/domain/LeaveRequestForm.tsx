'use client'

/**
 * The "file a leave request" form off the balances page (`/me/leave`) — mirrors
 * `CorrectionForm`'s shape: a required leave-type `Select` (this office's ACTIVE types
 * only — `useLeaveTypes(officeId)` returns every type, including retired ones, so this
 * filters to `is_active` the same way `LeaveTypesPage`'s `grantableLeaveTypes` does), a
 * start/end date range, a full/half-day `Select`, a required note, and an optional
 * attachment. Submit stays disabled until every required field is present and
 * `end_date >= start_date`; the backend is still the final authority (a 422 surfaces via
 * the `ApiError` this component reads off `useSubmitLeaveRequest()`'s `error`, same as
 * `CorrectionForm`).
 *
 * The selected type's CURRENT BALANCE reads off `useMyLeave()`, joined by `leave_type_id`
 * — never recomputed. `/me/leave` only returns a row for types that `deducts_balance`
 * (see `ListMyLeaveController`), so a non-deducting type (e.g. an unpaid leave that never
 * touches the ledger) simply shows no balance line, which is correct: there IS no balance
 * for it, not a fabricated "0 days".
 *
 * The COST line ("~3 days") is a rough CALENDAR-day count between start/end (inclusive),
 * minus half a day when `day_part` is `half` — expressed in half-day integer units so no
 * float ever appears, per this codebase's "never a float" rule. This is NOT the
 * schedule-aware `amount_minutes` the server actually computes (which skips weekends and
 * holidays per the employee's real schedule) — it exists only as a sanity check for the
 * employee before they submit; the server is the one authority.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { LeaveDayPart, LeaveRequestInput } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { daysBetweenInclusive } from '@/lib/date'
import { useLeaveTypes } from '@/hooks/useLeaveTypes'
import { useMyLeave } from '@/hooks/useMyLeave'
import { useSubmitLeaveRequest } from '@/hooks/useSubmitLeaveRequest'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { TextInput } from '@/components/ui/TextInput'
import { LeaveDuration } from '@/components/domain/LeaveDuration'

export interface LeaveRequestFormProps {
  /** The requester's own office — scopes the leave-type Select to types configured for
   * it. Passed by the caller (from `useSession()`'s `employee.current_office_id`) rather
   * than resolved internally, same as `CorrectionForm` taking `date`/`punches` from its
   * caller instead of fetching them again. */
  officeId: string
  onDone: () => void
}

const DAY_PART_OPTIONS: SelectOption[] = [
  { value: 'full', label: 'Full day' },
  { value: 'half', label: 'Half day' },
]

const ACCEPTED_ATTACHMENT_TYPES = '.pdf,.jpg,.jpeg,.png'

/** `('2026-08-10', '2026-08-12', 'full')` → `'~3 days'`; a half day shaves one half-day
 * unit off the calendar span rather than subtracting `0.5` as a float. Returns `null` when
 * the range isn't complete/valid yet, so the caller can hide the line entirely. */
function estimateCost(startDate: string, endDate: string, dayPart: LeaveDayPart): string | null {
  if (startDate === '' || endDate === '' || endDate < startDate) return null

  const calendarDays = daysBetweenInclusive(startDate, endDate)
  const halfDayUnits = calendarDays * 2 - (dayPart === 'half' ? 1 : 0)
  if (halfDayUnits <= 0) return null

  const wholeDays = Math.floor(halfDayUnits / 2)
  const hasHalf = halfDayUnits % 2 === 1
  const days = hasHalf ? `${wholeDays}.5` : `${wholeDays}`

  return `~${days} day${halfDayUnits === 2 ? '' : 's'}`
}

export function LeaveRequestForm({ officeId, onDone }: LeaveRequestFormProps) {
  const [leaveTypeId, setLeaveTypeId] = useState('')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [dayPart, setDayPart] = useState<LeaveDayPart>('full')
  const [note, setNote] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)

  const leaveTypesQuery = useLeaveTypes(officeId)
  const balancesQuery = useMyLeave()
  const submitMutation = useSubmitLeaveRequest()
  const apiError = submitMutation.error instanceof ApiError ? submitMutation.error : null

  const leaveTypeOptions: SelectOption[] = (leaveTypesQuery.data ?? [])
    .filter((leaveType) => leaveType.is_active)
    .map((leaveType) => ({ value: leaveType.id, label: leaveType.name }))

  const selectedBalance =
    (balancesQuery.data ?? []).find((balance) => balance.leave_type.id === leaveTypeId) ?? null

  const cost = estimateCost(startDate, endDate, dayPart)

  const isValid =
    leaveTypeId !== '' && startDate !== '' && endDate !== '' && endDate >= startDate && note.trim() !== ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    const input: LeaveRequestInput = {
      leave_type_id: leaveTypeId,
      start_date: startDate,
      end_date: endDate,
      day_part: dayPart,
      note,
      ...(attachment ? { attachment } : {}),
    }

    submitMutation.mutate(input, { onSuccess: onDone })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select
        id="leave-request-type"
        label="Leave type"
        value={leaveTypeId}
        onChange={setLeaveTypeId}
        options={leaveTypeOptions}
      />

      {selectedBalance !== null ? (
        <div className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
          <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            Current balance:
          </span>
          <LeaveDuration readable={selectedBalance.balance_readable} />
        </div>
      ) : null}

      <TextInput id="leave-request-start" label="Start date" type="date" value={startDate} onChange={setStartDate} />
      <TextInput id="leave-request-end" label="End date" type="date" value={endDate} onChange={setEndDate} />

      <Select
        id="leave-request-day-part"
        label="Day part"
        value={dayPart}
        onChange={(value) => setDayPart(value as LeaveDayPart)}
        options={DAY_PART_OPTIONS}
      />

      {cost !== null ? (
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Estimated cost: {cost}
        </span>
      ) : null}

      <TextInput id="leave-request-note" label="Note" value={note} onChange={setNote} required />

      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <label
          htmlFor="leave-request-attachment"
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
        >
          Attachment (optional)
        </label>
        <input
          id="leave-request-attachment"
          type="file"
          accept={ACCEPTED_ATTACHMENT_TYPES}
          onChange={(event) => setAttachment(event.target.files?.[0] ?? null)}
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
        />
      </div>

      {apiError ? (
        <InlineNotification kind="error" title="That leave request didn't submit.">
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
