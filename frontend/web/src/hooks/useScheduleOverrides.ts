'use client'

/**
 * One employee's schedule overrides for one calendar month. Thin on purpose, like
 * `useHolidays` — the query key comes from `keys.ts` so every mutation's invalidation can
 * never drift from this hook's fetch.
 *
 * An override changes what `ScheduleResolver` produces for that date, so every mutation
 * here invalidates BOTH `keys.schedules.overrides` (this list) AND
 * `keys.schedules.resolved` (the resolved calendar the employee/manager actually looks
 * at) — invalidating only the former would leave a resolved month stale after an edit.
 *
 * `officeId` is nullable (and `employeeId` may be a not-yet-resolved id) for the same
 * reason `useHolidays`'s `officeId` is — the query stays disabled rather than firing a
 * request nothing can answer.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { ScheduleOverride, ScheduleOverrideCreateInput, ScheduleOverrideUpdateInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useScheduleOverrides(officeId: string | null, employeeId: string | null, month: string) {
  return useQuery<ScheduleOverride[]>({
    queryKey: keys.schedules.overrides(employeeId ?? '', month),
    queryFn: () =>
      api.scheduleOverrides.list({ office: officeId as string, employee: employeeId as string, month }),
    enabled: officeId !== null && employeeId !== null,
  })
}

/** Invalidates BOTH the overrides list and the resolved calendar for the same
 * employee/month — an override changes resolution, so a stale resolved month after an
 * edit is exactly the bug this double invalidation prevents. */
function useInvalidateScheduleOverrides(employeeId: string | null, month: string) {
  const queryClient = useQueryClient()
  return () => {
    queryClient.invalidateQueries({ queryKey: keys.schedules.overrides(employeeId ?? '', month) })
    queryClient.invalidateQueries({ queryKey: keys.schedules.resolved(employeeId ?? '', month) })
  }
}

export function useCreateScheduleOverride(employeeId: string | null, month: string) {
  const invalidate = useInvalidateScheduleOverrides(employeeId, month)

  return useMutation<ScheduleOverride, unknown, ScheduleOverrideCreateInput>({
    mutationFn: (body) => api.scheduleOverrides.create(body),
    onSuccess: () => {
      invalidate()
    },
  })
}

export function useUpdateScheduleOverride(employeeId: string | null, month: string) {
  const invalidate = useInvalidateScheduleOverrides(employeeId, month)

  return useMutation<ScheduleOverride, unknown, { id: string; body: ScheduleOverrideUpdateInput }>({
    mutationFn: ({ id, body }) => api.scheduleOverrides.update(id, body),
    onSuccess: () => {
      invalidate()
    },
  })
}

export function useDeleteScheduleOverride(employeeId: string | null, month: string) {
  const invalidate = useInvalidateScheduleOverrides(employeeId, month)

  return useMutation<undefined, unknown, string>({
    mutationFn: (id) => api.scheduleOverrides.delete(id),
    onSuccess: () => {
      invalidate()
    },
  })
}
