'use client'

/**
 * The current employee's own leave balances (`GET /me/leave`) — one row per leave type,
 * carrying both raw minutes and the readable day/hour/minute decomposition. Thin on
 * purpose, like `useMyRequests` — the query key comes from `keys.ts` so `useGrantLeave`'s
 * invalidation can never drift from this hook's fetch.
 */

import { useQuery } from '@tanstack/react-query'

import type { LeaveBalance } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyLeave() {
  return useQuery<LeaveBalance[]>({
    queryKey: keys.leave.myBalances(),
    queryFn: () => api.leave.myBalances(),
  })
}
