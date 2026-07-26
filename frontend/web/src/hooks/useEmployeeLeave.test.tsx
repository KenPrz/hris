import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { LeaveBalance } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      employeeBalances: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useEmployeeLeave } from './useEmployeeLeave'

afterEach(() => {
  vi.clearAllMocks()
})

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

describe('useEmployeeLeave', () => {
  it('fetches keys.leave.employeeBalances(employeeId) via api.leave.employeeBalances', async () => {
    vi.mocked(api.leave.employeeBalances).mockResolvedValue([balance()])

    const client = newClient()
    const { result } = renderHook(() => useEmployeeLeave('e1'), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([balance()])
    expect(api.leave.employeeBalances).toHaveBeenCalledWith('e1')
    expect(client.getQueryData(keys.leave.employeeBalances('e1'))).toEqual([balance()])
  })

  it('never fetches when employeeId is null', async () => {
    const { result } = renderHook(() => useEmployeeLeave(null), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(result.current.fetchStatus).toBe('idle')
    expect(api.leave.employeeBalances).not.toHaveBeenCalled()
  })
})
