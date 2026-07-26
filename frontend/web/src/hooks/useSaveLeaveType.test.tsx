import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { LeaveType, LeaveTypeInput } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      createType: vi.fn(),
      updateType: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSaveLeaveType } from './useSaveLeaveType'

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

function input(overrides: Partial<LeaveTypeInput> = {}): LeaveTypeInput {
  return {
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

describe('useSaveLeaveType — create (no id)', () => {
  it('calls api.leave.createType(body) and invalidates keys.leave.types(officeId)', async () => {
    vi.mocked(api.leave.createType).mockResolvedValue(leaveType())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveLeaveType('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ body: input({ office_id: 'o1' }) })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.leave.createType).toHaveBeenCalledWith(input({ office_id: 'o1' }))
    expect(api.leave.updateType).not.toHaveBeenCalled()
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.leave.types('o1') })
  })
})

describe('useSaveLeaveType — update (id present)', () => {
  it('calls api.leave.updateType(id, body) and invalidates keys.leave.types(officeId)', async () => {
    vi.mocked(api.leave.updateType).mockResolvedValue(leaveType({ name: 'Renamed' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveLeaveType('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'lt1', body: input({ name: 'Renamed' }) })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.leave.updateType).toHaveBeenCalledWith('lt1', input({ name: 'Renamed' }))
    expect(api.leave.createType).not.toHaveBeenCalled()
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.leave.types('o1') })
  })

  it('does not invalidate when the mutation fails', async () => {
    vi.mocked(api.leave.updateType).mockRejectedValue(new Error('validation failed'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveLeaveType('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'lt1', body: input() })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
