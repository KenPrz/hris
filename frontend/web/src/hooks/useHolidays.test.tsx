import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Holiday } from '@/lib/api'
import { keys } from '@/lib/keys'

import {
  useCloneHolidays,
  useCreateHoliday,
  useDeleteHoliday,
  useHolidays,
  useUpdateHoliday,
} from './useHolidays'

afterEach(() => {
  vi.unstubAllGlobals()
})

function holiday(overrides: Partial<Holiday> = {}): Holiday {
  return {
    id: 'h1',
    office_id: 'o1',
    date: '2026-01-01',
    day_type: 'regular_holiday',
    name: "New Year's Day",
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

describe('useHolidays — read', () => {
  it('fetches keys.holidays.forOfficeYear(officeId, year) via GET /office/holidays', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [holiday()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useHolidays('o1', 2026), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([holiday()])
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/holidays?office=o1&year=2026',
      expect.anything(),
    )
  })

  it('never fetches when officeId is null', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    renderHook(() => useHolidays(null, 2026), { wrapper: makeWrapper(newClient()) })

    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('useHolidays — mutations invalidate keys.holidays.forOfficeYear(officeId, year)', () => {
  it('useCreateHoliday invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: holiday() }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateHoliday('o1', 2026), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', date: '2026-01-01', day_type: 'regular_holiday', name: 'X' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/holidays',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.holidays.forOfficeYear('o1', 2026) })
  })

  it('useUpdateHoliday sends only { day_type, name } (no date) and invalidates', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: holiday({ name: 'Renamed' }) }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateHoliday('o1', 2026), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'h1', body: { day_type: 'regular_holiday', name: 'Renamed' } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const call = fetchMock.mock.calls[0]
    expect(call[0]).toBe('/api/v1/office/holidays/h1')
    const init = call[1] as { method?: string; body?: string }
    expect(init.method).toBe('PATCH')
    expect(JSON.parse(init.body ?? '{}')).toEqual({ day_type: 'regular_holiday', name: 'Renamed' })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.holidays.forOfficeYear('o1', 2026) })
  })

  it('useDeleteHoliday invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, json: async () => null })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useDeleteHoliday('o1', 2026), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('h1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/holidays/h1',
      expect.objectContaining({ method: 'DELETE' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.holidays.forOfficeYear('o1', 2026) })
  })

  it('useCloneHolidays invalidates on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [holiday()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCloneHolidays('o1', 2026), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', from_year: 2025, to_year: 2026 })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/office/holidays/clone',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.holidays.forOfficeYear('o1', 2026) })
  })

  it('does not invalidate when a mutation fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('down for good'))
    vi.stubGlobal('fetch', fetchMock)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateHoliday('o1', 2026), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', date: '2026-01-01', day_type: 'regular_holiday', name: 'X' })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
