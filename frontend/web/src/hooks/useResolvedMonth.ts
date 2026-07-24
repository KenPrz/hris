'use client'

/**
 * One employee's fully-resolved schedule for one calendar month — the shape a calendar
 * screen actually renders (rest days, minutes, and which precedence tier produced them).
 * Read-only: nothing mutates a resolved month directly, only the overrides/assignments/
 * templates it is computed from, so there is no invalidation logic here — the mutation
 * hooks that touch those inputs are responsible for invalidating `keys.schedules.resolved`
 * themselves (see `useScheduleOverrides`).
 *
 * `employeeId` is nullable for the same reason `useHolidays`'s `officeId` is — the query
 * stays disabled rather than firing a request no employee can answer.
 */

import { useQuery } from '@tanstack/react-query'

import type { ResolvedMonth } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useResolvedMonth(employeeId: string | null, month: string) {
  return useQuery<ResolvedMonth>({
    queryKey: keys.schedules.resolved(employeeId ?? '', month),
    queryFn: () => api.resolvedSchedule.get(employeeId as string, month),
    enabled: employeeId !== null,
  })
}
