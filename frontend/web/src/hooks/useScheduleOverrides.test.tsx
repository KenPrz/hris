import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ScheduleOverride } from '@/lib/api'
import { keys } from '@/lib/keys'

import {
  useCreateScheduleOverride,
  useDeleteScheduleOverride,
  useScheduleOverrides,
  useUpdateScheduleOverride,
} from './useScheduleOverrides'

afterEach(() => {
  vi.unstubAllGlobals()
})

function override(overrides: Partial<ScheduleOverride> = {}): ScheduleOverride {
  return {
    id: 'ov1',
    employee_id: 'e1',
    date: '2026-01-15',
    is_rest: false,
    start_minute: 480,
    end_minute: 1020,
    break_minutes: 60,
    note: null,
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

describe('useScheduleOverrides — read', () => {
  it('fetches keys.schedules.overrides(employeeId, month) via GET /office/schedule-overrides', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [override()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useScheduleOverrides('o1', 'e1', '2026-01'), {
      wrapper: makeWrapper(newClient()),
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([override()])
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-overrides?office=o1&employee=e1&month=2026-01',
      expect.anything(),
    )
  })

  it('never fetches when employeeId is null', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderHook(() => useScheduleOverrides('o1', null, '2026-01'), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('useScheduleOverrides — mutations invalidate BOTH overrides and resolved keys', () => {
  it('useCreateScheduleOverride invalidates overrides and resolved on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: override() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateScheduleOverride('e1', '2026-01'), {
      wrapper: makeWrapper(client),
    })

    await act(async () => {
      result.current.mutate({ employee_id: 'e1', date: '2026-01-15', is_rest: true })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-overrides',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.overrides('e1', '2026-01') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.resolved('e1', '2026-01') })
    expect(invalidateSpy).toHaveBeenCalledTimes(2)
  })

  it('useUpdateScheduleOverride sends { is_rest, ... } (no employee_id/date) and invalidates both keys', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: override({ is_rest: true }) }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateScheduleOverride('e1', '2026-01'), {
      wrapper: makeWrapper(client),
    })

    await act(async () => {
      result.current.mutate({ id: 'ov1', body: { is_rest: true } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const call = fetchMock.mock.calls[0]
    expect(call[0]).toBe('/api/v1/office/schedule-overrides/ov1')
    const init = call[1] as { method?: string; body?: string }
    expect(init.method).toBe('PATCH')
    expect(JSON.parse(init.body ?? '{}')).toEqual({ is_rest: true })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.overrides('e1', '2026-01') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.resolved('e1', '2026-01') })
  })

  it('useDeleteScheduleOverride invalidates both keys on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: async () => null })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeleteScheduleOverride('e1', '2026-01'), {
      wrapper: makeWrapper(client),
    })

    await act(async () => {
      result.current.mutate('ov1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/schedule-overrides/ov1',
      expect.objectContaining({ method: 'DELETE' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.overrides('e1', '2026-01') })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.resolved('e1', '2026-01') })
  })

  it('does not invalidate when a mutation fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateScheduleOverride('e1', '2026-01'), {
      wrapper: makeWrapper(client),
    })

    await act(async () => {
      result.current.mutate({ employee_id: 'e1', date: '2026-01-15', is_rest: true })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
