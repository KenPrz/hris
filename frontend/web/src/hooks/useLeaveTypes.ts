'use client'

/**
 * An office's leave types (M6b-a Task 4's config surface). Thin on purpose, like
 * `useHolidays`/`useShiftTemplates` — the query key comes from `keys.ts` so
 * `useSaveLeaveType`'s invalidation can never drift from this hook's fetch.
 *
 * `officeId` is nullable because the screen may not have resolved one yet (session still
 * loading, or the account administers no office) — the query simply stays disabled rather
 * than firing a request no office can answer.
 */

import { useQuery } from '@tanstack/react-query'

import type { LeaveType } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useLeaveTypes(officeId: string | null) {
  return useQuery<LeaveType[]>({
    queryKey: keys.leave.types(officeId ?? ''),
    queryFn: () => api.leave.types(officeId as string),
    enabled: officeId !== null,
  })
}
