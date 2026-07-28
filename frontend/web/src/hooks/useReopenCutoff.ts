'use client'

/**
 * Reopens a closed cutoff period (`POST /office/cutoffs/{id}/reopen`, a loudly-audited
 * action that always requires a `reason`). On success it invalidates BOTH the office's
 * cutoff list (`keys.cutoffs.list(officeId)` — the period is open again) AND both approval
 * queues (`keys.requests.teamApprovals()` / `officeApprovals()`): reopening unlocks the
 * period's days, so a request that was blocked as `cutoff_locked` becomes approvable again.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { CutoffPeriod } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export type ReopenCutoffVariables = { id: string; reason: string }

export function useReopenCutoff(officeId: string | null) {
  const queryClient = useQueryClient()

  return useMutation<CutoffPeriod, unknown, ReopenCutoffVariables>({
    mutationFn: ({ id, reason }) => api.cutoffs.reopen(id, { reason }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.cutoffs.list(officeId ?? '') })
      void queryClient.invalidateQueries({ queryKey: keys.requests.teamApprovals() })
      void queryClient.invalidateQueries({ queryKey: keys.requests.officeApprovals() })
    },
  })
}
