'use client'

/**
 * A closed cutoff period's payroll export (`GET /office/cutoffs/{period}/export`). Thin,
 * like `useCutoffs` — the query key comes from `keys.ts` so nothing else can drift from
 * it. `periodId` is nullable because the screen may not have a period selected yet; the
 * query stays disabled rather than firing a request no period can answer.
 */

import { useQuery } from '@tanstack/react-query'

import type { PayrollExport } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function usePayrollExport(periodId: string | null) {
  return useQuery<PayrollExport>({
    queryKey: keys.payrollExport.forPeriod(periodId ?? ''),
    queryFn: () => api.cutoffs.export(periodId as string),
    enabled: periodId !== null,
  })
}
