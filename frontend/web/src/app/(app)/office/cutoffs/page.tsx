'use client'

/**
 * An HR admin's view of one office's cutoff periods (M7a) — mirrors `/office/leave-types`'s
 * scaffold: an office picker sourced from `session.hr_offices` (a single office skips the
 * picker; only length > 1 shows one), loading/error/empty via
 * `Skeleton`/`InlineNotification`/`EmptyState`, and mutations that invalidate through the
 * same keyed pattern.
 *
 * The list (`GET /office/cutoffs`) is every stored period PLUS one synthetic current-window
 * entry whose `id` is `null` — the office's still-running, not-yet-persisted window (a row
 * exists only once `CloseCutoff` has touched it). That entry is the "Close period" target:
 * a close is keyed by `period_start`, never by id, so a null id never blocks it — the button
 * appears on every OPEN period (the synthetic current window, and any period a reopen put
 * back to open). A reopen needs a real id for its route binding, and only a CLOSED (stored)
 * period ever carries one, so the "Reopen" prompt only shows there — the null-id entry is
 * always open and never offers it.
 *
 * Closing runs a strict server-side gate: a 422 `cutoff_has_unresolved_exceptions` carries
 * the exact blockers (`incomplete_dates`, `pending_request_ids`), surfaced verbatim so an
 * operator knows what to resolve before the period can close.
 */

import Link from 'next/link'
import { useState } from 'react'
import type { FormEvent } from 'react'

import type { CutoffPeriod, CutoffUnresolvedDetails } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { useCutoffs } from '@/hooks/useCutoffs'
import { useCloseCutoff } from '@/hooks/useCloseCutoff'
import { useReopenCutoff } from '@/hooks/useReopenCutoff'
import { useSession } from '@/hooks/useSession'
import { formatDateSpan } from '@/lib/date'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'

function formatClosedAt(closedAt: string | null): string {
  if (closedAt === null) return '—'
  const date = new Date(closedAt)
  if (Number.isNaN(date.getTime())) return closedAt
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/** The `cutoff_has_unresolved_exceptions` detail shape (both string lists), pulled off the
 * `ApiError` only when the code matches — every other failure falls back to generic copy. */
function unresolvedDetailsOf(error: unknown): CutoffUnresolvedDetails | null {
  if (!(error instanceof ApiError) || error.code !== 'cutoff_has_unresolved_exceptions') return null

  const details = error.details as Partial<CutoffUnresolvedDetails>
  return {
    incomplete_dates: Array.isArray(details.incomplete_dates) ? details.incomplete_dates : [],
    pending_request_ids: Array.isArray(details.pending_request_ids) ? details.pending_request_ids : [],
  }
}

interface ReopenFormProps {
  period: CutoffPeriod
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (reason: string) => void
}

/** A reopen is loudly audited and always requires a reason (the backend 400s an empty one),
 * so the confirm button stays disabled until one is typed — same "no bare action" discipline
 * as `RequestCard`'s reject note. */
function ReopenForm({ period, submitting, submitError, onCancel, onSubmit }: ReopenFormProps) {
  const [reason, setReason] = useState('')
  const trimmed = reason.trim()

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (trimmed === '') return
    onSubmit(trimmed)
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <p style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
        Reopening {formatDateSpan(period.start_date, period.end_date)} makes its days editable again.
      </p>

      <TextInput
        id="reopen-reason"
        label="Reason for reopening"
        value={reason}
        onChange={setReason}
        required
      />

      {submitError ? (
        <InlineNotification kind="error" title="That reopen didn't go through.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" variant="danger" loading={submitting} disabled={submitting || trimmed === ''}>
          Reopen period
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface CutoffRowProps {
  period: CutoffPeriod
  closing: boolean
  onClose: () => void
  onReopen: () => void
}

function CutoffRow({ period, closing, onClose, onReopen }: CutoffRowProps) {
  const isOpen = period.state === 'open'

  return (
    <tr style={{ borderTop: '1px solid var(--hairline)' }}>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)', font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {formatDateSpan(period.start_date, period.end_date)}
      </td>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)' }}>
        <Tag kind={isOpen ? 'warning' : 'neutral'}>{isOpen ? 'Open' : 'Closed'}</Tag>
      </td>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
        {formatClosedAt(period.closed_at)}
      </td>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)', textAlign: 'right' }}>
        {isOpen ? (
          <Button loading={closing} disabled={closing} onClick={onClose}>
            Close period
          </Button>
        ) : (
          // A closed period is always a stored row, so `id` is non-null here — the only
          // state that carries the id the export route binds to. "View export" is the drill
          // into that period's per-employee earnings; it never shows on an open row.
          <div className="inline-flex items-center justify-end" style={{ gap: 'var(--sp-sm)' }}>
            <Link
              href={`/office/cutoffs/${period.id}/export`}
              className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
              style={{
                font: 'var(--t-body-sm)',
                letterSpacing: 'var(--ls-body)',
                color: 'var(--blue)',
                textDecoration: 'none',
              }}
            >
              View export
            </Link>
            <Button variant="ghost" onClick={onReopen}>
              Reopen
            </Button>
          </div>
        )}
      </td>
    </tr>
  )
}

export default function CutoffsPage() {
  const { session } = useSession()

  const hrOffices = session?.hr_offices ?? []
  // hr_offices is the authority for "offices you administer"; current_office_id only covers
  // the degenerate single-office case — same fallback /office/leave-types uses.
  const defaultOfficeId = hrOffices[0] ?? session?.employee?.current_office_id ?? null

  const [chosenOfficeId, setChosenOfficeId] = useState<string | null>(null)
  const officeId = chosenOfficeId ?? defaultOfficeId

  const [reopenTarget, setReopenTarget] = useState<CutoffPeriod | null>(null)

  const cutoffsQuery = useCutoffs(officeId)
  const closeMutation = useCloseCutoff(officeId)
  const reopenMutation = useReopenCutoff(officeId)

  const periods = cutoffsQuery.data ?? []
  const unresolved = unresolvedDetailsOf(closeMutation.error)

  function handleClose(period: CutoffPeriod): void {
    if (officeId === null) return
    closeMutation.mutate({ office_id: officeId, period_start: period.start_date })
  }

  function handleReopenSubmit(reason: string): void {
    // Only ever called from a CLOSED period's dialog, and a closed period is always a stored
    // row — so `id` is non-null here. The guard keeps TypeScript honest about the null case.
    if (reopenTarget?.id == null) return
    reopenMutation.mutate({ id: reopenTarget.id, reason }, { onSuccess: () => setReopenTarget(null) })
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Office" title="Cutoffs" level={1} />

        {hrOffices.length > 1 ? (
          <Select
            id="cutoff-office"
            label="Office"
            value={officeId ?? ''}
            onChange={setChosenOfficeId}
            options={hrOffices.map((id) => ({ value: id, label: id }))}
          />
        ) : null}

        {officeId === null ? (
          <InlineNotification kind="info" title="No office to show cutoffs for.">
            This account doesn&rsquo;t administer any office&rsquo;s cutoffs.
          </InlineNotification>
        ) : (
          <>
            {closeMutation.isError ? (
              unresolved !== null ? (
                <InlineNotification kind="error" title="This period can't be closed yet.">
                  {unresolved.incomplete_dates.length > 0
                    ? `Incomplete days: ${unresolved.incomplete_dates.join(', ')}. `
                    : ''}
                  {unresolved.pending_request_ids.length > 0
                    ? `${unresolved.pending_request_ids.length} pending request${unresolved.pending_request_ids.length === 1 ? '' : 's'} still to decide. `
                    : ''}
                  Resolve these, then close the period.
                </InlineNotification>
              ) : (
                <InlineNotification kind="error" title="That close didn't go through.">
                  {closeMutation.error instanceof ApiError
                    ? closeMutation.error.message
                    : 'Check your connection and try again.'}
                </InlineNotification>
              )
            ) : null}

            {closeMutation.isSuccess ? <InlineNotification kind="success" title="Period closed." /> : null}

            {cutoffsQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : cutoffsQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load cutoff periods.">
                Check your connection and try again.
              </InlineNotification>
            ) : periods.length === 0 ? (
              <EmptyState title="No cutoff periods yet" />
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table
                  aria-label="Cutoff periods"
                  style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    background: 'var(--surface-1)',
                    borderRadius: 'var(--radius)',
                  }}
                >
                  <thead>
                    <tr>
                      {['Period', 'State', 'Closed at', ''].map((heading, index) => (
                        <th
                          key={heading || 'actions'}
                          scope="col"
                          style={{
                            padding: 'var(--sp-sm) var(--sp-md)',
                            textAlign: index === 3 ? 'right' : 'left',
                            font: 'var(--t-caption)',
                            letterSpacing: 'var(--ls-caption)',
                            color: 'var(--ink-subtle)',
                          }}
                        >
                          {heading}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {periods.map((period) => (
                      <CutoffRow
                        // A stored period keys on its id; the synthetic current window (id
                        // null) keys on its start date — one such entry exists at most.
                        key={period.id ?? `current-${period.start_date}`}
                        period={period}
                        closing={
                          closeMutation.isPending &&
                          closeMutation.variables?.period_start === period.start_date
                        }
                        onClose={() => handleClose(period)}
                        onReopen={() => setReopenTarget(period)}
                      />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

        <Dialog
          open={reopenTarget !== null}
          onClose={() => setReopenTarget(null)}
          title="Reopen cutoff period"
        >
          {reopenTarget === null ? null : (
            <ReopenForm
              key={reopenTarget.id ?? reopenTarget.start_date}
              period={reopenTarget}
              submitting={reopenMutation.isPending}
              submitError={reopenMutation.isError}
              onCancel={() => setReopenTarget(null)}
              onSubmit={handleReopenSubmit}
            />
          )}
        </Dialog>
      </div>
    </AppShell>
  )
}
