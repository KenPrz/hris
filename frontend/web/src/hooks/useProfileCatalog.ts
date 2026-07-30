'use client'

import { useQuery } from '@tanstack/react-query'

import type { ProfileCatalog } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

/** Static reference data — nothing writes it, so it is refetched rarely rather than per mount. */
export function useProfileCatalog() {
  return useQuery<ProfileCatalog>({
    queryKey: keys.profile.catalog(),
    queryFn: () => api.profile.catalog(),
    staleTime: 60 * 60 * 1000,
  })
}
