import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { CutoffPeriod } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    cutoffs: {
      close: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useCloseCutoff } from './useCloseCutoff'

afterEach(() => {
  vi.clearAllMocks()
})

function closedPeriod(overrides: Partial<CutoffPeriod> = {}): CutoffPeriod {
  return {
    id: 'p1',
    office_id: 'o1',
    start_date: '2026-07-16',
    end_date: '2026-07-31',
    state: 'closed',
    closed_by: 'u1',
    closed_at: '2026-08-01T00:00:00+08:00',
    ...overrides,
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

const input = { office_id: 'o1', period_start: '2026-07-16' }

describe('useCloseCutoff', () => {
  it('calls api.cutoffs.close and invalidates the cutoff list + both approval queues on success', async () => {
    vi.mocked(api.cutoffs.close).mockResolvedValue(closedPeriod())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCloseCutoff('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.cutoffs.close).toHaveBeenCalledWith(input)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.cutoffs.list('o1') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.teamApprovals() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.officeApprovals() })
  })

  it('does not invalidate anything when the close is refused', async () => {
    vi.mocked(api.cutoffs.close).mockRejectedValue(new Error('refused'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCloseCutoff('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
