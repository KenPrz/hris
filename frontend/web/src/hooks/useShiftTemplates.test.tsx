import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ShiftTemplate } from '@/lib/api'
import { keys } from '@/lib/keys'

import {
  useCreateShiftTemplate,
  useDeleteShiftTemplate,
  useShiftTemplates,
  useUpdateShiftTemplate,
} from './useShiftTemplates'

afterEach(() => {
  vi.unstubAllGlobals()
})

function template(overrides: Partial<ShiftTemplate> = {}): ShiftTemplate {
  return {
    id: 't1',
    office_id: 'o1',
    name: 'Day Shift',
    days: [
      { weekday: 0, is_rest: true, start_minute: null, end_minute: null, break_minutes: null },
      { weekday: 1, is_rest: false, start_minute: 480, end_minute: 1020, break_minutes: 60 },
      { weekday: 2, is_rest: false, start_minute: 480, end_minute: 1020, break_minutes: 60 },
      { weekday: 3, is_rest: false, start_minute: 480, end_minute: 1020, break_minutes: 60 },
      { weekday: 4, is_rest: false, start_minute: 480, end_minute: 1020, break_minutes: 60 },
      { weekday: 5, is_rest: false, start_minute: 480, end_minute: 1020, break_minutes: 60 },
      { weekday: 6, is_rest: true, start_minute: null, end_minute: null, break_minutes: null },
    ],
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

describe('useShiftTemplates — read', () => {
  it('fetches keys.schedules.templates(officeId) via GET /office/shift-templates', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [template()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useShiftTemplates('o1'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([template()])
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/office/shift-templates?office=o1', expect.anything())
  })

  it('never fetches when officeId is null', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderHook(() => useShiftTemplates(null), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('useShiftTemplates — mutations invalidate keys.schedules.templates(officeId)', () => {
  it('useCreateShiftTemplate invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: template() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateShiftTemplate('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', name: 'Day Shift', days: template().days })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/shift-templates',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.templates('o1') })
  })

  it('useUpdateShiftTemplate sends { name, days } (no office_id) and invalidates', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: template({ name: 'Renamed' }) }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateShiftTemplate('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 't1', body: { name: 'Renamed', days: template().days } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const call = fetchMock.mock.calls[0]
    expect(call[0]).toBe('/api/v1/office/shift-templates/t1')
    const init = call[1] as { method?: string; body?: string }
    expect(init.method).toBe('PATCH')
    expect(JSON.parse(init.body ?? '{}')).toEqual({ name: 'Renamed', days: template().days })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.templates('o1') })
  })

  it('useDeleteShiftTemplate invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: async () => null })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeleteShiftTemplate('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('t1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/shift-templates/t1',
      expect.objectContaining({ method: 'DELETE' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.schedules.templates('o1') })
  })

  it('does not invalidate when a mutation fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateShiftTemplate('o1'), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', name: 'Day Shift', days: template().days })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
