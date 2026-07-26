'use client'

/**
 * A plain, non-optimistic decision on a single request — approve, reject (with a
 * decision note), or cancel — via `POST /requests/{id}/{approve,reject,cancel}`. This is
 * the version `/me/requests` uses: a decision there is rare enough (an employee mostly
 * only cancels their own pending request) that waiting for the round trip before the list
 * updates is fine. The optimistic decide-and-remove-from-queue mutation a manager/HR
 * admin's approval queue needs is `useQueueDecision` below — do not extend this one to
 * do that; it is a separate hook on purpose.
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

/**
 * The ONE optimistic mutation in the frontend — confined to an approver's queue
 * (`/team/approvals`, `/office/approvals`) per the spec. Approving or rejecting a request
 * removes its card from `queueKey`'s cached list immediately, before the network round
 * trip completes: a queue is worked item-by-item, and waiting for the response before the
 * decided card disappears reads as the click not having done anything.
 *
 * `onMutate` cancels any in-flight fetch of `queueKey` (so it can't overwrite the
 * optimistic write with stale data), snapshots the current list so it can be restored, and
 * filters the decided id out. `onError` restores that snapshot — a failed decision must
 * not leave the card looking approved/rejected when it wasn't. `onSettled` invalidates
 * `queueKey` regardless of outcome, so the list is eventually consistent with the server
 * either way.
 */
export function useQueueDecision(queueKey: readonly unknown[]) {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (v: { id: string; action: 'approve' } | { id: string; action: 'reject'; note: string }) =>
      v.action === 'approve' ? api.requests.approve(v.id) : api.requests.reject(v.id, v.note),
    onMutate: async (v) => {
      await qc.cancelQueries({ queryKey: queueKey })
      const prev = qc.getQueryData<RequestRecord[]>(queueKey)
      qc.setQueryData<RequestRecord[]>(queueKey, (old) => (old ?? []).filter((r) => r.id !== v.id))
      return { prev }
    },
    onError: (_e, _v, ctx) => {
      if (ctx?.prev) qc.setQueryData(queueKey, ctx.prev)
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: queueKey })
    },
  })
}
