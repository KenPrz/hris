'use client'

/**
 * A plain, non-optimistic decision on a single request — approve, reject (with a
 * decision note), or cancel — via `POST /requests/{id}/{approve,reject,cancel}`. This is
 * the version `/me/requests` uses: a decision there is rare enough (an employee mostly
 * only cancels their own pending request) that waiting for the round trip before the list
 * updates is fine. The optimistic decide-and-remove-from-queue mutation a manager/HR
 * admin's approval queue needs is Task 7's `useDecideQueueRequest` — do not extend this
 * one to do that; build a new hook instead.
 *
 * Invalidates `keys.requests.mine()` only, matching queries by key prefix (TanStack's
 * default) — the /me/requests list that changed. It deliberately does NOT touch
 * `keys.requests.teamApprovals()`/`officeApprovals()`; those are separate queues with
 * their own (optimistic) decide hook.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export type RequestDecision =
  | { id: string; action: 'approve' }
  | { id: string; action: 'reject'; note: string }
  | { id: string; action: 'cancel' }

export function useDecideRequest() {
  const queryClient = useQueryClient()

  return useMutation<RequestRecord, unknown, RequestDecision>({
    mutationFn: (decision) => {
      switch (decision.action) {
        case 'approve':
          return api.requests.approve(decision.id)
        case 'reject':
          return api.requests.reject(decision.id, decision.note)
        case 'cancel':
          return api.requests.cancel(decision.id)
      }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.requests.mine() })
    },
  })
}
