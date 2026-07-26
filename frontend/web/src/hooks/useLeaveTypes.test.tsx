import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { LeaveType } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      types: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useLeaveTypes } from './useLeaveTypes'

afterEach(() => {
  vi.clearAllMocks()
})

function leaveType(overrides: Partial<LeaveType> = {}): LeaveType {
  return {
    id: 'lt1',
    office_id: 'o1',
    name: 'Vacation Leave',
    code: 'VL',
    is_paid: true,
    requires_attachment: false,
    deducts_balance: true,
    is_cash_convertible: true,
    max_carryover_minutes: null,
    is_active: true,
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

describe('useLeaveTypes', () => {
  it('fetches keys.leave.types(officeId) via api.leave.types', async () => {
    vi.mocked(api.leave.types).mockResolvedValue([leaveType()])

    const client = newClient()
    const { result } = renderHook(() => useLeaveTypes('o1'), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([leaveType()])
    expect(api.leave.types).toHaveBeenCalledWith('o1')
    expect(client.getQueryData(keys.leave.types('o1'))).toEqual([leaveType()])
  })

  it('never fetches when officeId is null', async () => {
    const { result } = renderHook(() => useLeaveTypes(null), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(result.current.fetchStatus).toBe('idle')
    expect(api.leave.types).not.toHaveBeenCalled()
  })
})
