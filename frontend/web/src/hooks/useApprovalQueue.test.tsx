import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    requests: {
      teamApprovals: vi.fn(),
      officeApprovals: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useApprovalQueue } from './useApprovalQueue'

afterEach(() => {
  vi.clearAllMocks()
})

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

describe('useApprovalQueue', () => {
  it("scope 'team' fetches keys.requests.teamApprovals() via api.requests.teamApprovals", async () => {
    vi.mocked(api.requests.teamApprovals).mockResolvedValue([requestRecord()])

    const client = newClient()
    const { result } = renderHook(() => useApprovalQueue('team'), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([requestRecord()])
    expect(api.requests.teamApprovals).toHaveBeenCalledTimes(1)
    expect(api.requests.officeApprovals).not.toHaveBeenCalled()
    expect(client.getQueryData(keys.requests.teamApprovals())).toEqual([requestRecord()])
  })

  it("scope 'office' fetches keys.requests.officeApprovals() via api.requests.officeApprovals", async () => {
    vi.mocked(api.requests.officeApprovals).mockResolvedValue([requestRecord({ id: 'r2' })])

    const client = newClient()
    const { result } = renderHook(() => useApprovalQueue('office'), { wrapper: makeWrapper(client) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([requestRecord({ id: 'r2' })])
    expect(api.requests.officeApprovals).toHaveBeenCalledTimes(1)
    expect(api.requests.teamApprovals).not.toHaveBeenCalled()
    expect(client.getQueryData(keys.requests.officeApprovals())).toEqual([requestRecord({ id: 'r2' })])
  })
})
