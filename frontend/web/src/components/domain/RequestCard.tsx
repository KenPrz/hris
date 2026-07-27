'use client'

/**
 * One pending request in an approver's queue (`/team/approvals`, `/office/approvals`) —
 * the requester, a per-type summary of what's being asked for, their note, an attachment
 * link when there is one, and Approve / Reject. Reject requires typing a decision note
 * before it fires; there is no bare "reject with nothing to say."
 *
 * Pure presentational — it owns no query or mutation of its own. `onApprove`/`onReject`
 * are wired by the caller to `useQueueDecision` (the queue's optimistic decide mutation);
 * `pending` disables both actions while that mutation is in flight for THIS card
 * specifically — the caller compares its mutation's `variables.id` against `request.id`,
 * the same idiom `/me/requests`'s Withdraw button already uses for its own mutation.
 *
 * `summarize` switches on `request.type` so a future leave/OT request type is a new case
 * here, not a new component.
 */

import { useState } from 'react'

import type { AttendanceAdjustmentDetail, LeaveRequestDetail, OvertimeRequestDetail, RequestRecord, RequestState, RequestType } from '@/lib/api'
import { formatDateSpan, timeInZone } from '@/lib/date'
import { formatDuration } from '@/lib/duration'
import { getToken } from '@/lib/session'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import type { TagKind } from '@/components/Tag'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { TextInput } from '@/components/ui/TextInput'

export interface RequestCardProps {
  request: RequestRecord
  onApprove: () => void
  onReject: (note: string) => void
  pending: boolean
}

const TYPE_LABEL: Record<RequestType, string> = {
  attendance_adjustment: 'Attendance correction',
  leave: 'Leave',
  overtime: 'Overtime',
}

// `manager_approved` gets its OWN label here ("Awaiting HR", not "Pending") — the one
// state distinction worth calling out on a card, since a manager-hop item and a fresh
// hop-1 item read identically otherwise. Mirrors `/me/requests`'s own STATE_LABEL (each
// file keeps its own copy, same duplication as TYPE_LABEL above — see that page's comment).
const STATE_LABEL: Record<RequestState, string> = {
  pending: 'Pending',
  manager_approved: 'Awaiting HR',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Withdrawn',
}

const STATE_TAG_KIND: Record<RequestState, TagKind> = {
  pending: 'warning',
  manager_approved: 'warning',
  approved: 'success',
  rejected: 'error',
  cancelled: 'neutral',
}

/**
 * "Add OUT punch at 18:00" / "Void the 08:00 IN" / "Amend to 08:15" — mirrors what
 * `CorrectionForm` collected for each operation. `direction`/`punched_at` are only ever
 * present on the operations that submitted them (`SubmitAttendanceAdjustment` stores
 * `null` for a bare `void`), so each branch degrades to a plain description when the
 * field it needs isn't there rather than rendering "at null".
 */
function summarizeAttendanceAdjustment(detail: AttendanceAdjustmentDetail): string {
  const time = detail.punched_at !== null ? timeInZone(detail.punched_at, OFFICE_TIME_ZONE) : null
  const direction = detail.direction !== null ? detail.direction.toUpperCase() : null

  switch (detail.operation) {
    case 'add':
      return direction !== null && time !== null ? `Add ${direction} punch at ${time}` : 'Add a punch'
    case 'void':
      return time !== null && direction !== null ? `Void the ${time} ${direction}` : 'Void a punch'
    case 'amend':
      return time !== null ? `Amend to ${time}` : 'Amend a punch'
  }
}

// The office's actual `minutes_per_leave_day` isn't available here — this card only
// carries the leave detail, not the office config `LeaveUnit::readable` would need to
// decompose `amount_minutes` exactly. 480 (an 8-hour day) is the office DEFAULT (see the
// `minutes_per_leave_day` migration), so this is a display-only approximation of the
// server's authoritative amount — not a recomputation of what it charges.
const APPROX_MINUTES_PER_DAY = 480

function formatLeaveCost(minutes: number): string {
  const days = Math.floor(minutes / APPROX_MINUTES_PER_DAY)
  const remainderMinutes = minutes % APPROX_MINUTES_PER_DAY
  const hours = Math.floor(remainderMinutes / 60)
  const mins = remainderMinutes % 60

  const parts: string[] = []
  if (days > 0) parts.push(`${days} day${days === 1 ? '' : 's'}`)
  if (hours > 0) parts.push(`${hours} hr${hours === 1 ? '' : 's'}`)
  if (mins > 0) parts.push(`${mins} min`)

  return parts.length > 0 ? parts.join(' ') : '0 days'
}

/** `"Aug 10–12 · full day · 3 days"` — the span (`formatDateSpan`), the day part in
 * prose, and a rough cost decomposition of `amount_minutes` (see `formatLeaveCost`). */
function summarizeLeave(detail: LeaveRequestDetail): string {
  const span = formatDateSpan(detail.start_date, detail.end_date)
  const dayPartLabel = detail.day_part === 'full' ? 'full day' : 'half day'
  const cost = formatLeaveCost(detail.amount_minutes)

  return `${span} · ${dayPartLabel} · ${cost}`
}

/** `"2h overtime · Jul 15"` — the pre-authorized duration (`formatDuration` on the integer
 * minutes the backend resolved from the requested hours) and the single day it's for
 * (`formatDateSpan` on a one-day span renders just `"Jul 15"`). */
function summarizeOvertime(detail: OvertimeRequestDetail): string {
  return `${formatDuration(detail.minutes)} overtime · ${formatDateSpan(detail.date, detail.date)}`
}

function summarize(request: RequestRecord): string {
  switch (request.type) {
    case 'attendance_adjustment':
      return request.detail !== null && 'operation' in request.detail
        ? summarizeAttendanceAdjustment(request.detail)
        : 'Attendance correction'
    case 'leave':
      return request.detail !== null && 'leave_type_id' in request.detail
        ? summarizeLeave(request.detail)
        : 'Leave request'
    case 'overtime':
      return request.detail !== null && 'minutes' in request.detail
        ? summarizeOvertime(request.detail)
        : 'Overtime request'
  }
}

/**
 * `GET /requests/{id}/attachment` is an authenticated stream, not a public URL — a plain
 * `<a href>` would navigate without the bearer token and 401. Fetches it with the same
 * `Authorization` header `lib/api.ts`'s `request()` adds, then hands the browser a
 * same-origin blob URL to save.
 */
async function downloadAttachment(requestId: string): Promise<void> {
  const token = getToken()
  const response = await fetch(`/api/v1/requests/${requestId}/attachment`, {
    headers: token !== null ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!response.ok) return

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = ''
  link.click()
  URL.revokeObjectURL(url)
}

export function RequestCard({ request, onApprove, onReject, pending }: RequestCardProps) {
  const [rejecting, setRejecting] = useState(false)
  const [note, setNote] = useState('')

  function handleStartReject(): void {
    setRejecting(true)
  }

  function handleCancelReject(): void {
    setRejecting(false)
    setNote('')
  }

  function handleConfirmReject(): void {
    const trimmed = note.trim()
    if (trimmed === '') return
    onReject(trimmed)
  }

  return (
    <li
      className="flex flex-col"
      style={{
        gap: 'var(--sp-sm)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
        padding: 'var(--sp-md)',
      }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {request.employee_id}
        </span>
        <div className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
          <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {TYPE_LABEL[request.type]}
          </span>
          <Tag kind={STATE_TAG_KIND[request.state]}>{STATE_LABEL[request.state]}</Tag>
        </div>
      </div>

      <span style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {summarize(request)}
      </span>

      <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
        {request.note}
      </span>

      {request.has_attachment ? (
        <div>
          <Button variant="ghost" onClick={() => void downloadAttachment(request.id)}>
            Download attachment
          </Button>
        </div>
      ) : null}

      {rejecting ? (
        <div className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
          <TextInput
            id={`reject-note-${request.id}`}
            label="Reason for rejecting"
            value={note}
            onChange={setNote}
            required
          />
          <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
            <Button
              variant="danger"
              loading={pending}
              disabled={pending || note.trim() === ''}
              onClick={handleConfirmReject}
            >
              Confirm reject
            </Button>
            <Button variant="ghost" disabled={pending} onClick={handleCancelReject}>
              Cancel
            </Button>
          </div>
        </div>
      ) : (
        <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
          <Button loading={pending} disabled={pending} onClick={onApprove}>
            Approve
          </Button>
          <Button variant="danger" disabled={pending} onClick={handleStartReject}>
            Reject
          </Button>
        </div>
      )}
    </li>
  )
}
