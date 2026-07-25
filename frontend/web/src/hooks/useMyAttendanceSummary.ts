'use client'

/**
 * A month of the current employee's own COMPUTED attendance — one `DailySummary` per
 * priced day, keyed later by date in the caller. Mirrors `useMyAttendance` exactly: thin
 * on purpose, the query key comes from `keys.ts` so nothing else can drift from it.
 */

import { useQuery } from '@tanstack/react-query'

import type { DailySummary } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyAttendanceSummary(month: string) {
  return useQuery<DailySummary[]>({
    queryKey: keys.attendance.summary(month),
    queryFn: () => api.attendance.summary(month),
  })
}
