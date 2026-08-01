'use client'

import { useQuery } from '@tanstack/react-query'

import type { ProfileCatalog } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

/**
 * Static reference data — nothing writes it, so it is refetched rarely rather than per
 * mount. `enabled` defaults to `true` for any caller that always needs it, but
 * `/employees/[employee]/profile` passes `false` for a manager's redacted view (and for a
 * viewer looking at their own record — see that page), which never renders a dropdown and
 * so never needs this catalog at all: firing it anyway would be a wasted authenticated
 * request, not a disclosure, but a wasted one all the same.
 */
export function useProfileCatalog(enabled: boolean = true) {
  return useQuery<ProfileCatalog>({
    queryKey: keys.profile.catalog(),
    queryFn: () => api.profile.catalog(),
    staleTime: 60 * 60 * 1000,
    enabled,
  })
}
