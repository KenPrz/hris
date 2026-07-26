import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: {
    leave: {
      setLeaveDay: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSetLeaveDay } from './useSetLeaveDay'

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('useSetLeaveDay', () => {
  it('calls api.leave.setLeaveDay(office_id, minutes_per_leave_day)', async () => {
    vi.mocked(api.leave.setLeaveDay).mockResolvedValue({ id: 'o1', minutes_per_leave_day: 480 })

    const { result } = renderHook(() => useSetLeaveDay(), { wrapper: makeWrapper(newClient()) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', minutes_per_leave_day: 480 })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.leave.setLeaveDay).toHaveBeenCalledWith('o1', 480)
    expect(result.current.data).toEqual({ id: 'o1', minutes_per_leave_day: 480 })
  })
})
