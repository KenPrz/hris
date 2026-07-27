'use client'

/**
 * Submits a leave request (`POST /leave/requests`, multipart — see `api.leave.submitRequest`).
 * On success it invalidates BOTH `keys.requests.mine()` (the new request now shows up in
 * "my requests") AND `keys.leave.myBalances()` — the moment a leave request lands the
 * balance screen's "available" figure should account for the pending debit, mirroring how
 * `useSubmitCorrection` invalidates the attendance side after an adjustment.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { LeaveRequestInput, RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useSubmitLeaveRequest() {
  const queryClient = useQueryClient()

  return useMutation<RequestRecord, unknown, LeaveRequestInput>({
    mutationFn: (input) => api.leave.submitRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.requests.mine() })
      void queryClient.invalidateQueries({ queryKey: keys.leave.myBalances() })
    },
  })
}
