import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { CutoffPeriod } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    cutoffs: {
      reopen: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useReopenCutoff } from './useReopenCutoff'

afterEach(() => {
  vi.clearAllMocks()
})

function openPeriod(overrides: Partial<CutoffPeriod> = {}): CutoffPeriod {
  return {
    id: 'p1',
    office_id: 'o1',
    start_date: '2026-07-16',
    end_date: '2026-07-31',
    state: 'open',
    closed_by: null,
    closed_at: null,
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

describe('useReopenCutoff', () => {
  it('calls api.cutoffs.reopen with id + reason and invalidates the list + both approval queues', async () => {
    vi.mocked(api.cutoffs.reopen).mockResolvedValue(openPeriod())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useReopenCutoff('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'p1', reason: 'Correcting a late punch' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.cutoffs.reopen).toHaveBeenCalledWith('p1', { reason: 'Correcting a late punch' })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.cutoffs.list('o1') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.teamApprovals() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.officeApprovals() })
  })

  it('does not invalidate anything when the reopen fails', async () => {
    vi.mocked(api.cutoffs.reopen).mockRejectedValue(new Error('boom'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useReopenCutoff('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'p1', reason: 'anything' })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
