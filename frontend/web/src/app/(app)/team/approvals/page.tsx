'use client'

/**
 * A manager's approval queue — every pending request from their direct reports
 * (`GET /team/approvals`), rendered as `<RequestCard>`s with the optimistic
 * decide-and-remove mutation (`useQueueDecision`). Mirrors `/office/approvals` exactly
 * except for the scope and the query key; kept as two files rather than one
 * parameterized route because the two live under separate route groups with separate
 * RBAC gates (team vs. office), not because the rendering differs.
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

export default function TeamApprovalsPage() {
  const queueQuery = useApprovalQueue('team')
  const decideMutation = useQueueDecision(keys.requests.teamApprovals())

  const requests = queueQuery.data ?? []

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Team" title="Approvals" level={1} />

        {queueQuery.isLoading ? (
          <Skeleton height="12rem" />
        ) : queueQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load your approval queue.">
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
