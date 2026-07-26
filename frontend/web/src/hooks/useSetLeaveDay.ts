'use client'

/**
 * Sets an office's `minutes_per_leave_day` (`PATCH /office/leave-day`) — the divisor
 * `LeaveUnit::toMinutes` uses to turn a `'day'`/`'half_shift'` grant amount into minutes.
 * No invalidation: like `useSetOfficeDefaultTemplate`, there is no separate GET for this
 * value cached anywhere in the frontend, so there is no query to keep in sync.
 */

import { useMutation } from '@tanstack/react-query'

import { api } from '@/lib/api'

export function useSetLeaveDay() {
  return useMutation<{ id: string; minutes_per_leave_day: number }, unknown, { office_id: string; minutes_per_leave_day: number }>({
    mutationFn: ({ office_id, minutes_per_leave_day }) => api.leave.setLeaveDay(office_id, minutes_per_leave_day),
  })
}
