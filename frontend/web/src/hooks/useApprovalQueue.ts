'use client'

/**
 * The plain (non-optimistic) read side of an approver's queue: a manager's direct
 * reports' pending requests (`GET /team/approvals`) or an HR admin's whole-office queue
 * (`GET /office/approvals`). The optimistic decide-and-remove mutation for these queues
 * is Task 7's `useDecideQueueRequest` — this hook only fetches.
 */

import { useQuery } from '@tanstack/react-query'

import type { RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export type ApprovalQueueScope = 'team' | 'office'

export function useApprovalQueue(scope: ApprovalQueueScope) {
  return useQuery<RequestRecord[]>({
    queryKey: scope === 'team' ? keys.requests.teamApprovals() : keys.requests.officeApprovals(),
    queryFn: () => (scope === 'team' ? api.requests.teamApprovals() : api.requests.officeApprovals()),
  })
}
