'use client'

/**
 * An office's holidays for one calendar year. Thin on purpose, like `useMyAttendance` —
 * the query key comes from `keys.ts` so every mutation's invalidation can never drift
 * from this hook's fetch.
 *
 * `officeId` is nullable because the screen may not have resolved one yet (session still
 * loading, or the account administers no office) — the query simply stays disabled rather
 * than firing a request no office can answer.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { Holiday, HolidayCloneInput, HolidayCreateInput, HolidayUpdateInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useHolidays(officeId: string | null, year: number) {
  return useQuery<Holiday[]>({
    queryKey: keys.holidays.forOfficeYear(officeId ?? '', year),
    queryFn: () => api.holidays.list(officeId as string, year),
    enabled: officeId !== null,
  })
}

/** Every mutation below invalidates the SAME office/year the caller is viewing — not
 * `keys.holidays.all()` — so a create/update/delete/clone anywhere else in the app can
 * never blow away a calendar this screen isn't looking at. */
function useInvalidateHolidays(officeId: string | null, year: number) {
  const queryClient = useQueryClient()
  return () =>
    queryClient.invalidateQueries({ queryKey: keys.holidays.forOfficeYear(officeId ?? '', year) })
}

export function useCreateHoliday(officeId: string | null, year: number) {
  const invalidate = useInvalidateHolidays(officeId, year)

  return useMutation<Holiday, unknown, HolidayCreateInput>({
    mutationFn: (body) => api.holidays.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useUpdateHoliday(officeId: string | null, year: number) {
  const invalidate = useInvalidateHolidays(officeId, year)

  return useMutation<Holiday, unknown, { id: string; body: HolidayUpdateInput }>({
    mutationFn: ({ id, body }) => api.holidays.update(id, body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useDeleteHoliday(officeId: string | null, year: number) {
  const invalidate = useInvalidateHolidays(officeId, year)

  return useMutation<undefined, unknown, string>({
    mutationFn: (id) => api.holidays.delete(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useCloneHolidays(officeId: string | null, year: number) {
  const invalidate = useInvalidateHolidays(officeId, year)

  return useMutation<Holiday[], unknown, HolidayCloneInput>({
    mutationFn: (body) => api.holidays.clone(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}
