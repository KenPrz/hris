import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ScheduleAssignment } from '@/lib/api'
import { keys } from '@/lib/keys'

import { useCreateScheduleAssignment, useDeleteScheduleAssignment, useScheduleAssignments } from './useScheduleAssignments'

afterEach(() => {
  vi.unstubAllGlobals()
})

function assignment(overrides: Partial<ScheduleAssignment> = {}): ScheduleAssignment {
  return {
    id: 'a1',
    shift_template_id: 't1',
    employee_id: 'e1',
    department_id: null,
    effective_from: '2026-01-01',
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

describe('useScheduleAssignments — read', () => {
  it('fetches keys.schedules.assignments(officeId) via GET /office/schedule-assignments', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [assignment()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useScheduleAssignments('o1'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([assignment()])
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-assignments?office=o1',
      expect.anything(),
    )
  })

  it('never fetches when officeId is null', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderHook(() => useScheduleAssignments(null), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('useScheduleAssignments — mutations invalidate keys.schedules.assignments(officeId)', () => {
  it('useCreateScheduleAssignment invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: assignment() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateScheduleAssignment('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ shift_template_id: 't1', employee_id: 'e1', effective_from: '2026-01-01' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-assignments',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.assignments('o1') })
  })

  it('useDeleteScheduleAssignment invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: async () => null })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeleteScheduleAssignment('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('a1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-assignments/a1',
      expect.objectContaining({ method: 'DELETE' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.assignments('o1') })
  })

  it('does not invalidate when a mutation fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateScheduleAssignment('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ shift_template_id: 't1', employee_id: 'e1', effective_from: '2026-01-01' })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
