import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { CorrectionInput, RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    adjustments: {
      submit: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSubmitCorrection } from './useSubmitCorrection'

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

const input: CorrectionInput = { operation: 'add', note: 'Forgot to punch out', direction: 'out' }

describe('useSubmitCorrection', () => {
  it('calls api.adjustments.submit with the input and invalidates mine() + attendance.all() on success', async () => {
    vi.mocked(api.adjustments.submit).mockResolvedValue(requestRecord())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitCorrection(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.adjustments.submit).toHaveBeenCalledWith(input)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.requests.mine() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.attendance.all() })
  })

  it('does not invalidate anything when the submit fails', async () => {
    vi.mocked(api.adjustments.submit).mockRejectedValue(new Error('rejected'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSubmitCorrection(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(input)
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
