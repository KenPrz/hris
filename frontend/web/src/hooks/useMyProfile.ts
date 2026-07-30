'use client'

/**
 * The current employee's own personnel file (`GET /me/profile`) — full, including national
 * IDs. Thin on purpose, like useMyLeave: the key comes from keys.ts so an admin write's
 * invalidation can never drift from this hook's fetch.
 */

import { useQuery } from '@tanstack/react-query'

import type { EmployeeProfile } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyProfile() {
  return useQuery<EmployeeProfile>({
    queryKey: keys.profile.mine(),
    queryFn: () => api.profile.mine(),
  })
}
