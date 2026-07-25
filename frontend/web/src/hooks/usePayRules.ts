'use client'

/**
 * Pay rules — a company singleton list, not scoped by office (there is nothing to
 * enumerate: `PayRuleResource`'s routes are gated by `is_system_admin`, not
 * `OfficeScope`). Thin on purpose, like `useHolidays` — the query key comes from
 * `keys.ts` so every mutation's invalidation can never drift from this hook's fetch.
 *
 * Versions are immutable: there is no update mutation, deliberately, matching the
 * backend's read-and-delete-only routes. A correction is a new version, never an edit
 * in place.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { PayRule, PayRuleCreateInput } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function usePayRules() {
  return useQuery<PayRule[]>({
    queryKey: keys.payRules.all(),
    queryFn: () => api.payRules.list(),
  })
}

function useInvalidatePayRules() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: keys.payRules.all() })
}

export function useCreatePayRule() {
  const invalidate = useInvalidatePayRules()

  return useMutation<PayRule, unknown, PayRuleCreateInput>({
    mutationFn: (body) => api.payRules.create(body),
    onSuccess: () => {
      void invalidate()
    },
  })
}

export function useDeletePayRule() {
  const invalidate = useInvalidatePayRules()

  return useMutation<undefined, unknown, string>({
    mutationFn: (id) => api.payRules.delete(id),
    onSuccess: () => {
      void invalidate()
    },
  })
}
