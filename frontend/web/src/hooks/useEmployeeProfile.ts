'use client'

/**
 * An HR admin's full read of one employee's personnel file (`GET
 * /admin/employees/{id}/profile`) — mirrors `useMyProfile` but scoped to an arbitrary
 * employee id rather than the caller. `enabled: id !== ''` mirrors `useAdminEmployee`'s
 * guard against firing before the route param has resolved.
 */

import { useQuery } from '@tanstack/react-query'

import type { EmployeeProfile } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useEmployeeProfile(id: string) {
  return useQuery<EmployeeProfile>({
    queryKey: keys.profile.forEmployee(id),
    queryFn: () => api.profile.forEmployee(id),
    enabled: id !== '',
  })
}
