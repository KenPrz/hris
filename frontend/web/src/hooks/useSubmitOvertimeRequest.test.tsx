import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { OvertimeRequestInput, RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    overtime: {
      submitRequest: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSubmitOvertimeRequest } from './useSubmitOvertimeRequest'

afterEach(() => {
  vi.clearAllMocks()
})

function requestRecord(overrides: Partial<RequestRecord> = {}): RequestRecord {
  return {
    id: 'r1',
    type: 'overtime',
    state: 'pending',
    note: 'Month-end close',
    employee_id: 'e1',
    detail: {
      date: '2026-08-01',
      minutes: 120,
    },
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

const input: OvertimeRequestInput = {
  date: '2026-08-01',
  hours: 2,
  note: 'Month-end close',
}

describe('useSubmitOvertimeRequest', () => {
  it('calls api.overtime.submitRequest with the input and invalidates mine() on success', async () => {
    vi.mocked(api.overtime.submitRequest).mockResolvedValue(requestRecord())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitOvertimeRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.overtime.submitRequest).toHaveBeenCalledWith(input)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
  })

  it('does not invalidate anything when the submit fails', async () => {
    vi.mocked(api.overtime.submitRequest).mockRejectedValue(new Error('rejected'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitOvertimeRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
