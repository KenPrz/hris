'use client'

/**
 * An office's cutoff periods (`GET /office/cutoffs`). Thin, like `useLeaveTypes` — the query
 * key comes from `keys.ts` so `useCloseCutoff`/`useReopenCutoff`'s invalidation can never
 * drift from this hook's fetch. The list includes stored rows plus one synthetic
 * current-window entry (`id: null`); see `CutoffPeriod`.
 *
 * `officeId` is nullable because the screen may not have resolved one yet (session still
 * loading, or the account administers no office) — the query stays disabled rather than
 * firing a request no office can answer.
 */

import { useQuery } from '@tanstack/react-query'

import type { CutoffPeriod } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useCutoffs(officeId: string | null) {
  return useQuery<CutoffPeriod[]>({
    queryKey: keys.cutoffs.list(officeId ?? ''),
    queryFn: () => api.cutoffs.list(officeId as string),
    enabled: officeId !== null,
  })
}
