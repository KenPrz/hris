'use client'

/**
 * An office's schedule assignments (which employee or department runs which shift
 * template, from which date). Thin on purpose, like `useShiftTemplates` — the query key
 * comes from `keys.ts` so every mutation's invalidation can never drift from this hook's
 * fetch.
 *
 * `officeId` is nullable because the screen may not have resolved one yet — the query
 * simply stays disabled rather than firing a request no office can answer.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { ScheduleAssignment, ScheduleAssignmentCreateInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useScheduleAssignments(officeId: string | null) {
  return useQuery<ScheduleAssignment[]>({
    queryKey: keys.schedules.assignments(officeId ?? ''),
    queryFn: () => api.scheduleAssignments.list({ office: officeId as string }),
    enabled: officeId !== null,
  })
}

/** Every mutation below invalidates the SAME office's assignment list the caller is
 * viewing — not a global key — so a create/delete anywhere else can never blow away an
 * assignment list this screen isn't looking at. It ALSO invalidates every resolved query
 * (`resolvedAll`): assigning or unassigning a template changes what `ScheduleResolver`
 * produces for that employee, so a resolved calendar on screen must refetch. */
function useInvalidateScheduleAssignments(officeId: string | null) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: keys.schedules.assignments(officeId ?? '') })
    void queryClient.invalidateQueries({ queryKey: keys.schedules.resolvedAll() })
  }
}

export function useCreateScheduleAssignment(officeId: string | null) {
  const invalidate = useInvalidateScheduleAssignments(officeId)

  return useMutation<ScheduleAssignment, unknown, ScheduleAssignmentCreateInput>({
    mutationFn: (body) => api.scheduleAssignments.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useDeleteScheduleAssignment(officeId: string | null) {
  const invalidate = useInvalidateScheduleAssignments(officeId)

  return useMutation<undefined, unknown, string>({
    mutationFn: (id) => api.scheduleAssignments.delete(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}
