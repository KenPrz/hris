'use client'

/**
 * The admin Profile tab's three write paths, bound to one employee id (`useSaveProfile(id)`
 * — the id is fixed at hook-call time, mirroring `useMyProfile`'s single-subject shape
 * rather than taking `{ id, body }` per call like the roster's mutations do). Each
 * mutation invalidates BOTH `keys.profile.forEmployee(id)` and `keys.profile.mine()`: an
 * HR admin editing their OWN profile through this same admin screen must see `/me/profile`
 * update too, and there is no cheap way to know from here whether `id` happens to be the
 * caller's own employee id.
 *
 * `saveIdentification`'s variable type is lifted off `api.profile.saveIdentification`
 * itself (`Parameters<...>[1]`) rather than redeclared, so the two can never drift.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { DependentWrite, EmployeeProfile, ProfileWriteBody } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export type IdentificationFields = Parameters<typeof api.profile.saveIdentification>[1]

export function useSaveProfile(employeeId: string) {
  const queryClient = useQueryClient()

  function invalidate(): void {
    // Both keys: an HR admin editing their OWN profile must see /me/profile update too.
    void queryClient.invalidateQueries({ queryKey: keys.profile.forEmployee(employeeId) })
    void queryClient.invalidateQueries({ queryKey: keys.profile.mine() })
  }

  const saveProfile = useMutation<EmployeeProfile, unknown, ProfileWriteBody>({
    mutationFn: (body) => api.profile.save(employeeId, body),
    onSuccess: invalidate,
  })

  const saveDependents = useMutation<EmployeeProfile, unknown, DependentWrite[]>({
    mutationFn: (dependents) => api.profile.saveDependents(employeeId, dependents),
    onSuccess: invalidate,
  })

  const saveIdentification = useMutation<EmployeeProfile, unknown, IdentificationFields>({
    mutationFn: (fields) => api.profile.saveIdentification(employeeId, fields),
    onSuccess: invalidate,
  })

  const deleteIdentification = useMutation<EmployeeProfile, unknown, string>({
    mutationFn: (identificationId) => api.profile.deleteIdentification(employeeId, identificationId),
    onSuccess: invalidate,
  })

  return { saveProfile, saveDependents, saveIdentification, deleteIdentification }
}
