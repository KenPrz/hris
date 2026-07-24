'use client'

/**
 * Clock in/out. The backend requires an `Idempotency-Key` on every punch — a double-tap
 * or a flaky connection must replay the first response rather than write a second punch.
 *
 * The key is minted once per *attempt* (one call to `mutate()`) and reused across every
 * retry of that same attempt: `mutationFn` only mints a fresh key when the ref is empty,
 * and the ref is only cleared in `onSettled`, which TanStack Query fires exactly once per
 * `mutate()` call — after retries are exhausted, not after each individual try. A small
 * automatic retry (`retry: 1`, no backoff) is configured here for exactly that flaky-
 * connection case; `retryDelay: 0` keeps it from stalling the UI or slowing tests.
 *
 * On success, invalidates the *current* office-local month — not necessarily whatever
 * month happens to be on screen. A punch always records "now," so it always lands in
 * `currentMonth(OFFICE_TIME_ZONE)`; invalidating the viewed month instead would miss the
 * punch's real effect whenever someone punches while browsing a past month.
 */

import { useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { AttendanceLog, PunchDirection } from '@/lib/api'
import { api } from '@/lib/api'
import { currentMonth } from '@/lib/date'
import { keys } from '@/lib/keys'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'

export function usePunch() {
  const queryClient = useQueryClient()
  const idempotencyKeyRef = useRef<string | null>(null)

  return useMutation<AttendanceLog, unknown, PunchDirection>({
    retry: 1,
    retryDelay: 0,
    mutationFn: (direction) => {
      if (idempotencyKeyRef.current === null) {
        idempotencyKeyRef.current = crypto.randomUUID()
      }
      return api.punch(direction, idempotencyKeyRef.current)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.attendance.month(currentMonth(OFFICE_TIME_ZONE)) })
    },
    onSettled: () => {
      // The attempt (including any retries) is fully done — the next `mutate()` call is a
      // new attempt and earns a new key.
      idempotencyKeyRef.current = null
    },
  })
}
