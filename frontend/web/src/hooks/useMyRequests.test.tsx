import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    requests: {
      mine: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useMyRequests } from './useMyRequests'

function requestRecord(overrides: Partial<RequestRecord> = {}): RequestRecord {
  return {
    id: 'r1',
    type: 'attendance_adjustment',
    state: 'pending',
    note: 'Forgot to punch out',
    employee_id: 'e1',
    detail: { operation: 'add', target_log_id: null, direction: 'out', punched_at: '2026-07-24T18:00:00Z' },
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

describe('useMyRequests', () => {
  it('fetches keys.requests.mine() via api.requests.mine', async () => {
    vi.mocked(api.requests.mine).mockResolvedValue([requestRecord()])

    const client = newClient()
    const { result } = renderHook(() => useMyRequests(), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([requestRecord()])
    expect(api.requests.mine).toHaveBeenCalledTimes(1)
    expect(client.getQueryData(keys.requests.mine())).toEqual([requestRecord()])
  })
})
