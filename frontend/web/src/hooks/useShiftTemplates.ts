'use client'

/**
 * An office's shift templates. Thin on purpose, like `useHolidays` — the query key comes
 * from `keys.ts` so every mutation's invalidation can never drift from this hook's fetch.
 *
 * `officeId` is nullable because the screen may not have resolved one yet (session still
 * loading, or the account administers no office) — the query simply stays disabled rather
 * than firing a request no office can answer.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { ShiftTemplate, ShiftTemplateCreateInput, ShiftTemplateUpdateInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useShiftTemplates(officeId: string | null) {
  return useQuery<ShiftTemplate[]>({
    queryKey: keys.schedules.templates(officeId ?? ''),
    queryFn: () => api.shiftTemplates.list(officeId as string),
    enabled: officeId !== null,
  })
}

/** Every mutation below invalidates the SAME office's template list the caller is viewing
 * — not a global key — so a create/update/delete anywhere else can never blow away a
 * template list this screen isn't looking at. It ALSO invalidates every resolved query
 * (`resolvedAll`): a template edit or a change to the office default alters what
 * `ScheduleResolver` produces, so a resolved calendar on screen must refetch rather than
 * show a stale week. */
function useInvalidateShiftTemplates(officeId: string | null) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: keys.schedules.templates(officeId ?? '') })
    void queryClient.invalidateQueries({ queryKey: keys.schedules.resolvedAll() })
  }
}

export function useCreateShiftTemplate(officeId: string | null) {
  const invalidate = useInvalidateShiftTemplates(officeId)

  return useMutation<ShiftTemplate, unknown, ShiftTemplateCreateInput>({
    mutationFn: (body) => api.shiftTemplates.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateShiftTemplate(officeId: string | null) {
  const invalidate = useInvalidateShiftTemplates(officeId)

  return useMutation<ShiftTemplate, unknown, { id: string; body: ShiftTemplateUpdateInput }>({
    mutationFn: ({ id, body }) => api.shiftTemplates.update(id, body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useDeleteShiftTemplate(officeId: string | null) {
  const invalidate = useInvalidateShiftTemplates(officeId)

  return useMutation<undefined, unknown, string>({
    mutationFn: (id) => api.shiftTemplates.delete(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}

/** Sets which template `ScheduleResolver` falls back to once no employee or department
 * assignment covers a date. Invalidates the same templates key as the others above —
 * there is no separate "office defaults" query to keep in sync, since the screen tracks
 * the current default locally (the API has no GET for it; see the schedules page). */
export function useSetOfficeDefaultTemplate(officeId: string | null) {
  const invalidate = useInvalidateShiftTemplates(officeId)

  return useMutation<{ id: string; default_shift_template_id: string }, unknown, { office_id: string; template_id: string }>({
    mutationFn: (body) => api.officeDefaultTemplate.set(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}
