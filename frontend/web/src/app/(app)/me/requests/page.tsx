'use client'

/**
 * "My requests" — every request the current employee has filed, with its status and (once
 * decided) outcome, plus a Withdraw action while it's still pending. This is deliberately
 * a self-contained row, not the shared `RequestCard` the manager/HR approval queues will
 * use once that lands — building the same list twice against the same schema is cheaper
 * than a cross-task import dependency, and a later task can adopt the card here if it
 * fits.
 *
 * Withdraw goes through `useDecideRequest`'s `cancel` action rather than a dedicated hook:
 * an employee cancelling their own pending request is rare enough that the plain,
 * non-optimistic round trip (invalidate-then-refetch `keys.requests.mine()`) is fine —
 * see that hook's doc comment for why the manager/HR queues need a different, optimistic
 * one instead.
 */

import type { RequestRecord, RequestState, RequestType } from '@/lib/api'
import { useDecideRequest } from '@/hooks/useDecideRequest'
import { useMyRequests } from '@/hooks/useMyRequests'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import type { TagKind } from '@/components/Tag'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'

// The backend's full `RequestType` set is one value today (`attendance_adjustment`) — see
// `RequestRecord` in lib/api.ts. A `Record` here (rather than a fallback string) means a
// future request type fails typecheck instead of silently rendering "undefined".
const TYPE_LABEL: Record<RequestType, string> = {
  attendance_adjustment: 'Attendance correction',
}

const STATE_LABEL: Record<RequestState, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Withdrawn',
}

const STATE_TAG_KIND: Record<RequestState, TagKind> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'error',
  cancelled: 'neutral',
}

interface RequestRowProps {
  request: RequestRecord
  withdrawing: boolean
  onWithdraw: (id: string) => void
}

function RequestRow({ request, withdrawing, onWithdraw }: RequestRowProps) {
  return (
    <li
      className="flex flex-col"
      style={{
        gap: 'var(--sp-xs)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
        padding: 'var(--sp-md)',
      }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {TYPE_LABEL[request.type]}
        </span>
        <Tag kind={STATE_TAG_KIND[request.state]}>{STATE_LABEL[request.state]}</Tag>
      </div>

      <span style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {request.note}
      </span>

      {request.decision_note !== null ? (
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Decision note: {request.decision_note}
        </span>
      ) : null}

      {request.state === 'pending' ? (
        <div>
          <Button variant="ghost" loading={withdrawing} disabled={withdrawing} onClick={() => onWithdraw(request.id)}>
            Withdraw
          </Button>
        </div>
      ) : null}
    </li>
  )
}

export default function MyRequestsPage() {
  const requestsQuery = useMyRequests()
  const decideMutation = useDecideRequest()

  function handleWithdraw(id: string): void {
    decideMutation.mutate({ id, action: 'cancel' })
  }

  const requests = requestsQuery.data ?? []

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Me" title="My requests" level={1} />

        {requestsQuery.isLoading ? (
          <Skeleton height="12rem" />
        ) : requestsQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load your requests.">
            Check your connection and try again.
          </InlineNotification>
        ) : requests.length === 0 ? (
          <EmptyState title="You haven't filed any requests." />
        ) : (
          <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
            {requests.map((request) => (
              <RequestRow
                key={request.id}
                request={request}
                withdrawing={decideMutation.isPending && decideMutation.variables?.id === request.id}
                onWithdraw={handleWithdraw}
              />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  )
}
