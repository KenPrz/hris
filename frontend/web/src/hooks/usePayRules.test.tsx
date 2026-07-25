import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { PayRule } from '@/lib/api'
import { keys } from '@/lib/keys'

import { useCreatePayRule, useDeletePayRule, usePayRules } from './usePayRules'

afterEach(() => {
  vi.unstubAllGlobals()
})

function payRule(overrides: Partial<PayRule> = {}): PayRule {
  return {
    id: 'pr1',
    effective_from: '2026-01-01',
    overtime_ordinary_bp: 12500,
    overtime_premium_bp: 13000,
    night_diff_bp: 11000,
    note: null,
    day_rates: [
      // Includes an `ordinary` row — a pay rule prices every day type, unlike a holiday.
      { day_type: 'ordinary', worked_bp: 10000, worked_rest_bp: 13000, unworked_bp: 0 },
      { day_type: 'regular_holiday', worked_bp: 20000, worked_rest_bp: 26000, unworked_bp: 10000 },
    ],
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

describe('usePayRules — read', () => {
  it('fetches keys.payRules.all() via GET /admin/pay-rules', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [payRule()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => usePayRules(), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([payRule()])
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/admin/pay-rules', expect.anything())
  })
})

describe('usePayRules — mutations invalidate keys.payRules.all()', () => {
  it('useCreatePayRule posts the body and invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: payRule() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreatePayRule(), { wrapper: makeWrapper(client) })

    const input = {
      effective_from: '2026-01-01',
      overtime_ordinary_bp: 12500,
      overtime_premium_bp: 13000,
      night_diff_bp: 11000,
      day_rates: [
        { day_type: 'regular_holiday' as const, worked_bp: 20000, worked_rest_bp: 26000, unworked_bp: 10000 },
      ],
    }

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/admin/pay-rules',
      expect.objectContaining({ method: 'POST' }),
    )
    const call = fetchMock.mock.calls[0]
    const init = call[1] as { body?: string }
    expect(JSON.parse(init.body ?? '{}')).toEqual(input)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.payRules.all() })
  })

  it('useDeletePayRule invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: async () => null })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeletePayRule(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('pr1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/admin/pay-rules/pr1',
      expect.objectContaining({ method: 'DELETE' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.payRules.all() })
  })

  it('does not invalidate when a mutation fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeletePayRule(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('pr1')
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
