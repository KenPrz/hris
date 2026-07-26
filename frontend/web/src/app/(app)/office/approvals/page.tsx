'use client'

/**
 * An HR admin's approval queue — every office-wide pending request (`GET
 * /office/approvals`), rendered as `<RequestCard>`s with the same optimistic
 * decide-and-remove mutation (`useQueueDecision`) `/team/approvals` uses, keyed to its
 * own `keys.requests.officeApprovals()` so the two queues never share or clobber each
 * other's cache entry. See that page's comment for why this is a second file rather
 * than one route parameterized by scope.
 */

import { useApprovalQueue } from '@/hooks/useApprovalQueue'
import { useQueueDecision } from '@/hooks/useDecideRequest'
import { keys } from '@/lib/keys'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { RequestCard } from '@/components/domain/RequestCard'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'

export default function OfficeApprovalsPage() {
  const queueQuery = useApprovalQueue('office')
  const decideMutation = useQueueDecision(keys.requests.officeApprovals())

  const requests = queueQuery.data ?? []

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Office" title="Approvals" level={1} />

        {queueQuery.isLoading ? (
          <Skeleton height="12rem" />
        ) : queueQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load the approval queue.">
            Check your connection and try again.
          </InlineNotification>
        ) : requests.length === 0 ? (
          <EmptyState title="Nothing awaiting your approval." />
        ) : (
          <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
            {requests.map((request) => (
              <RequestCard
                key={request.id}
                request={request}
                pending={decideMutation.isPending && decideMutation.variables?.id === request.id}
                onApprove={() => decideMutation.mutate({ id: request.id, action: 'approve' })}
                onReject={(note) => decideMutation.mutate({ id: request.id, action: 'reject', note })}
              />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  )
}
