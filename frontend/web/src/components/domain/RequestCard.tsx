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

import type { RequestDetail, RequestRecord, RequestType } from '@/lib/api'
import { timeInZone } from '@/lib/date'
import { getToken } from '@/lib/session'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
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
}

/**
 * "Add OUT punch at 18:00" / "Void the 08:00 IN" / "Amend to 08:15" — mirrors what
 * `CorrectionForm` collected for each operation. `direction`/`punched_at` are only ever
 * present on the operations that submitted them (`SubmitAttendanceAdjustment` stores
 * `null` for a bare `void`), so each branch degrades to a plain description when the
 * field it needs isn't there rather than rendering "at null".
 */
function summarizeAttendanceAdjustment(detail: RequestDetail): string {
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

function summarize(request: RequestRecord): string {
  switch (request.type) {
    case 'attendance_adjustment':
      return request.detail !== null ? summarizeAttendanceAdjustment(request.detail) : 'Attendance correction'
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
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {TYPE_LABEL[request.type]}
        </span>
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
