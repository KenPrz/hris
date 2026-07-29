import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ActivityEntry, ActivityPage } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  api: {
    admin: {
      activity: {
        list: vi.fn(),
      },
    },
  },
}))

import { api } from '@/lib/api'

import { useActivityLog } from './useActivityLog'

afterEach(() => {
  vi.clearAllMocks()
})

function entry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
    id: 'a1',
    log_name: 'office',
    description: 'created',
    event: 'created',
    subject_type: 'App\\Models\\Office',
    subject_id: 'o1',
    causer_id: 'u1',
    properties: {},
    created_at: '2026-07-01T00:00:00+00:00',
    ...overrides,
  }
}

function page(overrides: Partial<ActivityPage> = {}): ActivityPage {
  return {
    data: [entry()],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 50 },
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

describe('useActivityLog', () => {
  it('fetches keys.admin.activity(filters) via api.admin.activity.list, passing filters through', async () => {
    vi.mocked(api.admin.activity.list).mockResolvedValue(page())

    const { result } = renderHook(() => useActivityLog({ log_name: 'office' }), {
      wrapper: makeWrapper(newClient()),
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual(page())
    expect(api.admin.activity.list).toHaveBeenCalledWith({ log_name: 'office' })
  })

  it('re-queries when a filter changes', async () => {
    vi.mocked(api.admin.activity.list).mockResolvedValue(page())

    const client = newClient()
    const { result, rerender } = renderHook(({ filters }) => useActivityLog(filters), {
      wrapper: makeWrapper(client),
      initialProps: { filters: { log_name: 'office' } },
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(api.admin.activity.list).toHaveBeenCalledTimes(1)

    rerender({ filters: { log_name: 'department' } })

    await waitFor(() => expect(api.admin.activity.list).toHaveBeenCalledTimes(2))
    expect(api.admin.activity.list).toHaveBeenLastCalledWith({ log_name: 'department' })
  })

  it('re-queries when only the page changes', async () => {
    vi.mocked(api.admin.activity.list).mockResolvedValue(page())

    const client = newClient()
    const { result, rerender } = renderHook(({ filters }) => useActivityLog(filters), {
      wrapper: makeWrapper(client),
      initialProps: { filters: { page: 1 } },
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(api.admin.activity.list).toHaveBeenCalledTimes(1)

    rerender({ filters: { page: 2 } })

    await waitFor(() => expect(api.admin.activity.list).toHaveBeenCalledTimes(2))
    expect(api.admin.activity.list).toHaveBeenLastCalledWith({ page: 2 })
  })
})
