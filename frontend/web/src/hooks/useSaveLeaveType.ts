'use client'

/**
 * Create-or-update for a single leave type, the way `useDecideRequest` folds several
 * actions into one mutation: `{ body }` alone hits `POST /office/leave-types` (create),
 * `{ id, body }` hits `PATCH /office/leave-types/{id}` (update — no `office_id` in the
 * body; the route-bound type fixes it, see `LeaveTypeInput`). Always invalidates the SAME
 * office's type list the caller is viewing (`keys.leave.types(officeId)`), not a global
 * key — matching `useCreateHoliday`/`useUpdateHoliday`'s per-office invalidation.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { LeaveType, LeaveTypeInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export type SaveLeaveTypeVariables = { id?: string; body: LeaveTypeInput }

export function useSaveLeaveType(officeId: string | null) {
  const queryClient = useQueryClient()

  return useMutation<LeaveType, unknown, SaveLeaveTypeVariables>({
    mutationFn: ({ id, body }) => (id !== undefined ? api.leave.updateType(id, body) : api.leave.createType(body)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.leave.types(officeId ?? '') })
    },
  })
}
