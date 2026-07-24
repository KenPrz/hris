import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ResolvedMonth } from '@/lib/api'

import { useResolvedMonth } from './useResolvedMonth'

afterEach(() => {
  vi.unstubAllGlobals()
})

function resolvedMonth(): ResolvedMonth {
  return {
    '2026-01-15': {
      is_rest: false,
      start_minute: 480,
      end_minute: 1020,
      break_minutes: 60,
      scheduled_minutes: 480,
      source: 'employee',
    },
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

describe('useResolvedMonth', () => {
  it('fetches keys.schedules.resolved(employeeId, month) via GET /office/schedule/resolved', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: resolvedMonth() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useResolvedMonth('e1', '2026-01'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual(resolvedMonth())
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule/resolved?employee=e1&month=2026-01',
      expect.anything(),
    )
  })

  it('never fetches when employeeId is null', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderHook(() => useResolvedMonth(null, '2026-01'), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(fetchMock).not.toHaveBeenCalled()
  })
})
