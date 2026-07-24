'use client'

/**
 * A month of the current employee's own attendance, keyed by office-local date. Thin on
 * purpose — no wrapper abstraction over `useQuery`; the query key comes from `keys.ts` so
 * `usePunch`'s invalidation and this hook's fetch can never drift apart.
 */

import { useQuery } from '@tanstack/react-query'

import type { AttendanceMonth } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyAttendance(month: string) {
  return useQuery<AttendanceMonth>({
    queryKey: keys.attendance.month(month),
    queryFn: () => api.myAttendance(month),
  })
}
