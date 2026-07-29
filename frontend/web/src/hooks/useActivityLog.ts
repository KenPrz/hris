'use client'

/**
 * The audit viewer's data source (`GET /admin/activity`, M8c). Thin, like the org tree's
 * read hooks: the query key comes from `keys.ts` so distinct filter sets (page included)
 * land in distinct cache entries. Read-only — there is no mutation here to invalidate
 * against, mirroring the endpoint itself (sysadmin-gated, nothing writes through it).
 */

import { useQuery } from '@tanstack/react-query'

import type { ActivityFilters, ActivityPage } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useActivityLog(filters: ActivityFilters) {
  return useQuery<ActivityPage>({
    queryKey: keys.admin.activity(filters),
    queryFn: () => api.admin.activity.list(filters),
  })
}
