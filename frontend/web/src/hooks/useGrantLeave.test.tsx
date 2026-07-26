import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'

import type { LeaveGrantInput, LeaveLedgerEntry } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      grant: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useGrantLeave } from './useGrantLeave'

function ledgerEntry(overrides: Partial<LeaveLedgerEntry> = {}): LeaveLedgerEntry {
  return {
    id: 'll1',
    employee_id: 'e1',
    leave_type_id: 'lt1',
    entry_type: 'credit',
    minutes: 480,
    reason: 'Manual grant',
    source: 'manual_grant',
    created_by: 'u1',
    created_at: '2026-07-26T00:00:00Z',
    ...overrides,
  }
}

function grantInput(overrides: Partial<LeaveGrantInput> = {}): LeaveGrantInput {
  return {
    employee_id: 'e1',
    leave_type_id: 'lt1',
    amount: 1,
    unit: 'day',
    reason: 'Manual grant',
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

describe('useGrantLeave', () => {
  it('calls api.leave.grant(body) and invalidates the employee balance key + myBalances()', async () => {
    vi.mocked(api.leave.grant).mockResolvedValue(ledgerEntry())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useGrantLeave(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(grantInput())
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.leave.grant).toHaveBeenCalledWith(grantInput())
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.leave.employeeBalances('e1') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.leave.myBalances() })
  })

  it('does not invalidate when the grant fails', async () => {
    vi.mocked(api.leave.grant).mockRejectedValue(new Error('leave type not grantable'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useGrantLeave(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(grantInput())
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
