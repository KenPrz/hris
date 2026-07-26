'use client'

/**
 * One employee's leave balances, for a manager/HR screen viewing someone other than
 * themselves (`GET /employees/{employee}/leave`) — the same shape `useMyLeave` reads, just
 * scoped to a chosen employee rather than the caller.
 *
 * `employeeId` is nullable for the same reason `useResolvedMonth`'s is — the screen may
 * not have a selected employee yet, so the query stays disabled rather than firing a
 * request no employee can answer.
 */

import { useQuery } from '@tanstack/react-query'

import type { LeaveBalance } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useEmployeeLeave(employeeId: string | null) {
  return useQuery<LeaveBalance[]>({
    queryKey: keys.leave.employeeBalances(employeeId ?? ''),
    queryFn: () => api.leave.employeeBalances(employeeId as string),
    enabled: employeeId !== null,
  })
}
