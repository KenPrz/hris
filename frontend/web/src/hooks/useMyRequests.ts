'use client'

/**
 * The current employee's own requests (`GET /requests` — "my requests"). Thin on
 * purpose, like `useMyAttendance` — the query key comes from `keys.ts` so
 * `useSubmitCorrection` and `useDecideRequest`'s invalidations can never drift from this
 * hook's fetch.
 */

import { useQuery } from '@tanstack/react-query'

import type { RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyRequests() {
  return useQuery<RequestRecord[]>({
    queryKey: keys.requests.mine(),
    queryFn: () => api.requests.mine(),
  })
}
