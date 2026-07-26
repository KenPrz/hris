'use client'

/**
 * Submits an attendance correction request (`POST /attendance/adjustments`, multipart —
 * see `api.adjustments.submit`). On success it invalidates BOTH `keys.requests.mine()`
 * (the new request now shows up in "my requests") AND `keys.attendance.all()` — an
 * approved `add`/`void`/`amend` eventually changes the punch log itself, and even before
 * approval the pending request is what a screen showing "why is this day incomplete"
 * needs to refetch.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { CorrectionInput, RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useSubmitCorrection() {
  const queryClient = useQueryClient()

  return useMutation<RequestRecord, unknown, CorrectionInput>({
    mutationFn: (input) => api.adjustments.submit(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.requests.mine() })
      void queryClient.invalidateQueries({ queryKey: keys.attendance.all() })
    },
  })
}
