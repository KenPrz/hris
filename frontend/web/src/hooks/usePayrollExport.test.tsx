import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { PayrollExport } from '@/lib/api'
import { keys } from '@/lib/keys'

import { usePayrollExport } from './usePayrollExport'

afterEach(() => {
  vi.unstubAllGlobals()
})

function exportData(): PayrollExport {
  return {
    period: { id: 'period-1', office_id: 'office-1', start_date: '2026-07-01', end_date: '2026-07-15', state: 'closed' },
    employees: [
      {
        employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
        base_rate_segments: [{ effective_from: '2026-07-01', base_rate_cents: 50000 }],
        totals: { worked_minutes: 4800, late_minutes: 0, undertime_minutes: 0, unpaid_overtime_minutes: 0 },
        lines: [{ kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 4800 }],
        has_incomplete_days: false,
      },
    ],
  }
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('usePayrollExport', () => {
  it('fetches keys.payrollExport.forPeriod(periodId) via GET /office/cutoffs/{id}/export', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: exportData() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePayrollExport('period-1'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual(exportData())
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/office/cutoffs/period-1/export', expect.anything())
  })

  it('is keyed by keys.payrollExport.forPeriod(periodId)', () => {
    expect(keys.payrollExport.forPeriod('period-1')).toEqual(['payroll-export', 'period-1'])
  })

  it('is disabled when periodId is null', () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePayrollExport(null), { wrapper: makeWrapper(newClient()) })

    expect(result.current.isFetching).toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
