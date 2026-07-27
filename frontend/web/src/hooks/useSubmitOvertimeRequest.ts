'use client'

/**
 * Submits an overtime pre-authorization request (`POST /overtime/requests`). On success it
 * invalidates `keys.requests.mine()` so the new request shows up in "my requests". Unlike
 * leave there is no balance to refresh — overtime authorization writes no ledger.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { OvertimeRequestInput, RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useSubmitOvertimeRequest() {
  const queryClient = useQueryClient()

  return useMutation<RequestRecord, unknown, OvertimeRequestInput>({
    mutationFn: (input) => api.overtime.submitRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.requests.mine() })
    },
  })
}
