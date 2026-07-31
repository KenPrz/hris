'use client'

/**
 * The manager-facing redacted read (`GET /employees/{id}/profile`) — contact and
 * assignment only, `EmployeeProfileSummaryResource` on the wire. Mirrors
 * `useEmployeeProfile`'s shape but hits the redacted route and carries an explicit
 * `enabled` so a caller can defer firing it until it knows the full read isn't the right
 * one for this viewer (see `/employees/[employee]/profile/page.tsx`, which tries the full
 * read first and falls back to this hook on a 404).
 */

import { useQuery } from '@tanstack/react-query'

import type { EmployeeProfileSummary } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useRedactedProfile(id: string, enabled: boolean) {
  return useQuery<EmployeeProfileSummary>({
    queryKey: keys.profile.redacted(id),
    queryFn: () => api.profile.redacted(id),
    enabled: enabled && id !== '',
  })
}
