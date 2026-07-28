'use client'

/**
 * Closes an office's current cutoff window (`POST /office/cutoffs/close`, keyed by
 * `period_start` — see `CutoffCloseInput`). On success it invalidates BOTH the office's
 * cutoff list (`keys.cutoffs.list(officeId)` — the just-closed window now shows as closed)
 * AND both approval queues (`keys.requests.teamApprovals()` / `officeApprovals()`): closing
 * a period locks its days, so a pending request landing on one of those dates is no longer
 * approvable and must drop out of the queues.
 *
 * A refusal (422 `cutoff_has_unresolved_exceptions`) rejects with the `ApiError` carrying
 * `details.incomplete_dates` / `details.pending_request_ids`; the caller surfaces those.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { CutoffCloseInput, CutoffPeriod } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useCloseCutoff(officeId: string | null) {
  const queryClient = useQueryClient()

  return useMutation<CutoffPeriod, unknown, CutoffCloseInput>({
    mutationFn: (input) => api.cutoffs.close(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.cutoffs.list(officeId ?? '') })
      void queryClient.invalidateQueries({ queryKey: keys.requests.teamApprovals() })
      void queryClient.invalidateQueries({ queryKey: keys.requests.officeApprovals() })
    },
  })
}
