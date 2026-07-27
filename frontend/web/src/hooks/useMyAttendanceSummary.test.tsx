import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { DailySummary } from '@/lib/api'
import { keys } from '@/lib/keys'

import { useMyAttendanceSummary } from './useMyAttendanceSummary'

afterEach(() => {
  vi.unstubAllGlobals()
})

function summaries(): DailySummary[] {
  return [
    {
      date: '2026-01-15',
      day_type: 'ordinary',
      is_rest_day: false,
      scheduled_minutes: 480,
      is_art82_exempt: false,
      worked_minutes: 480,
      late_minutes: 0,
      undertime_minutes: 0,
      unpaid_overtime_minutes: 0,
      status: 'final',
      is_incomplete: false,
      rule_version_id: 'rv1',
      lines: [{ kind: 'regular_day', minutes: 480, applied_bp: 10000 }],
    },
  ]
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('useMyAttendanceSummary', () => {
  it('fetches keys.attendance.summary(month) via GET /me/attendance/summary?month=', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: summaries() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useMyAttendanceSummary('2026-01'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual(summaries())
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/me/attendance/summary?month=2026-01', expect.anything())
  })

  it('is keyed by keys.attendance.summary(month)', () => {
    expect(keys.attendance.summary('2026-01')).toEqual(['attendance', 'summary', '2026-01'])
  })
})
