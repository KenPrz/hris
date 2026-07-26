'use client'

/**
 * HR manually crediting an employee's leave balance (`POST /leave/grants`). Invalidates
 * two keys on success: `keys.leave.employeeBalances(employee_id)` — the granted
 * employee's balance list, so an HR/manager screen looking at them refetches — AND
 * `keys.leave.myBalances()`, so the caller's own `/me/leave` view never shows a stale
 * balance if the grant happens to affect the acting user's own employee record.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { LeaveGrantInput, LeaveLedgerEntry } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useGrantLeave() {
  const queryClient = useQueryClient()

  return useMutation<LeaveLedgerEntry, unknown, LeaveGrantInput>({
    mutationFn: (body) => api.leave.grant(body),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: keys.leave.employeeBalances(variables.employee_id) })
      void queryClient.invalidateQueries({ queryKey: keys.leave.myBalances() })
    },
  })
}
