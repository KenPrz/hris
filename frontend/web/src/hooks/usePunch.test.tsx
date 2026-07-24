import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AttendanceLog } from '@/lib/api'
import { currentMonth } from '@/lib/date'
import { keys } from '@/lib/keys'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'

import { usePunch } from './usePunch'

afterEach(() => {
  vi.unstubAllGlobals()
})

function punchResponse(overrides: Partial<AttendanceLog> = {}): AttendanceLog {
  return {
    id: 'p1',
    employee_id: 'e1',
    office_id: 'o1',
    punched_at: '2026-07-24T08:00:00+08:00',
    direction: 'in',
    source: 'web',
    verification: 'verified',
    flag_reason: null,
    ...overrides,
  }
}

function headerOf(call: unknown, name: string): string | undefined {
  const init = (call as unknown[])[1] as { headers?: Record<string, string> } | undefined
  return init?.headers?.[name]
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('usePunch — idempotency key', () => {
  it('sends an Idempotency-Key header on the request', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: punchResponse() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePunch(), { wrapper: makeWrapper(newClient()) })

    await act(async () => {
      result.current.mutate('in')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const key = headerOf(fetchMock.mock.calls[0], 'Idempotency-Key')
    expect(typeof key).toBe('string')
    expect(key!.length).toBeGreaterThan(0)
  })

  it('reuses the SAME key across a retry of the same attempt — the load-bearing property', async () => {
    let callCount = 0
    const fetchMock = vi.fn().mockImplementation(async () => {
      callCount += 1
      if (callCount === 1) {
        throw new Error('flaky connection')
      }
      return {
        ok: true,
        status: 200,
        json: async () => ({ data: punchResponse() }),
      }
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePunch(), { wrapper: makeWrapper(newClient()) })

    await act(async () => {
      result.current.mutate('in')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    // Proves the retry actually happened (two real requests), not just one lucky call.
    expect(fetchMock).toHaveBeenCalledTimes(2)

    const firstKey = headerOf(fetchMock.mock.calls[0], 'Idempotency-Key')
    const secondKey = headerOf(fetchMock.mock.calls[1], 'Idempotency-Key')

    expect(firstKey).toBeDefined()
    expect(secondKey).toBe(firstKey)
  })

  it('mints a fresh key for a separate, later attempt', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: punchResponse() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePunch(), { wrapper: makeWrapper(newClient()) })

    await act(async () => {
      result.current.mutate('in')
    })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    await act(async () => {
      result.current.mutate('out')
    })
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

    const firstKey = headerOf(fetchMock.mock.calls[0], 'Idempotency-Key')
    const secondKey = headerOf(fetchMock.mock.calls[1], 'Idempotency-Key')

    expect(secondKey).not.toBe(firstKey)
  })
})

describe('usePunch — cache invalidation', () => {
  it('invalidates the current month attendance query on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: punchResponse() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => usePunch(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('in')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: keys.attendance.month(currentMonth(OFFICE_TIME_ZONE)),
    })
  })

  it('does not invalidate anything when the punch ultimately fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => usePunch(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('in')
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
