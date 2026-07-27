import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { LeaveRequestInput, RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      submitRequest: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSubmitLeaveRequest } from './useSubmitLeaveRequest'

afterEach(() => {
  vi.clearAllMocks()
})

function requestRecord(overrides: Partial<RequestRecord> = {}): RequestRecord {
  return {
    id: 'r1',
    type: 'leave',
    state: 'pending',
    note: 'Family emergency',
    employee_id: 'e1',
    detail: {
      leave_type_id: 'lt1',
      start_date: '2026-08-01',
      end_date: '2026-08-01',
      day_part: 'full',
      amount_minutes: 480,
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

const input: LeaveRequestInput = {
  leave_type_id: 'lt1',
  start_date: '2026-08-01',
  end_date: '2026-08-01',
  day_part: 'full',
  note: 'Family emergency',
}

describe('useSubmitLeaveRequest', () => {
  it('calls api.leave.submitRequest with the input and invalidates mine() + myBalances() on success', async () => {
    vi.mocked(api.leave.submitRequest).mockResolvedValue(requestRecord())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitLeaveRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.leave.submitRequest).toHaveBeenCalledWith(input)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.leave.myBalances() })
  })

  it('does not invalidate anything when the submit fails', async () => {
    vi.mocked(api.leave.submitRequest).mockRejectedValue(new Error('rejected'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitLeaveRequest(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
