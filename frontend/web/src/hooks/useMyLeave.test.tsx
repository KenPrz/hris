import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'

import type { LeaveBalance } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      myBalances: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useMyLeave } from './useMyLeave'

function balance(overrides: Partial<LeaveBalance> = {}): LeaveBalance {
  return {
    leave_type: {
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
    },
    balance_minutes: 2400,
    balance_readable: { days: 5, hours: 0, minutes: 0 },
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

describe('useMyLeave', () => {
  it('fetches keys.leave.myBalances() via api.leave.myBalances', async () => {
    vi.mocked(api.leave.myBalances).mockResolvedValue([balance()])

    const client = newClient()
    const { result } = renderHook(() => useMyLeave(), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([balance()])
    expect(api.leave.myBalances).toHaveBeenCalledTimes(1)
    expect(client.getQueryData(keys.leave.myBalances())).toEqual([balance()])
  })
})
