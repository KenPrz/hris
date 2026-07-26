import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    requests: {
      approve: vi.fn(),
      reject: vi.fn(),
      cancel: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useDecideRequest } from './useDecideRequest'

afterEach(() => {
  vi.clearAllMocks()
})

function requestRecord(overrides: Partial<RequestRecord> = {}): RequestRecord {
  return {
    id: 'r1',
    type: 'attendance_adjustment',
    state: 'pending',
    note: 'Forgot to punch out',
    employee_id: 'e1',
    detail: { operation: 'add', target_log_id: null, direction: 'out', punched_at: '2026-07-24T18:00:00Z' },
    decided_by: null,
    decided_at: null,
    decision_note: null,
    has_attachment: false,
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

describe('useDecideRequest — non-optimistic, used by /me/requests', () => {
  it('action "approve" calls api.requests.approve(id) and invalidates keys.requests.mine()', async () => {
    vi.mocked(api.requests.approve).mockResolvedValue(requestRecord({ state: 'approved' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDecideRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'r1', action: 'approve' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.requests.approve).toHaveBeenCalledWith('r1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
  })

  it('action "reject" calls api.requests.reject(id, note) and invalidates keys.requests.mine()', async () => {
    vi.mocked(api.requests.reject).mockResolvedValue(requestRecord({ state: 'rejected' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDecideRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'r1', action: 'reject', note: 'Not enough evidence' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.requests.reject).toHaveBeenCalledWith('r1', 'Not enough evidence')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
  })

  it('action "cancel" calls api.requests.cancel(id) and invalidates keys.requests.mine()', async () => {
    vi.mocked(api.requests.cancel).mockResolvedValue(requestRecord({ state: 'cancelled' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDecideRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'r1', action: 'cancel' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.requests.cancel).toHaveBeenCalledWith('r1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
  })

  it('does not invalidate anything when the decision fails', async () => {
    vi.mocked(api.requests.cancel).mockRejectedValue(new Error('already decided'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDecideRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'r1', action: 'cancel' })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
