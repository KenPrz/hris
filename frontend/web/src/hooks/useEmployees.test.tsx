import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Employee } from '@/lib/api'

import { useEmployees } from './useEmployees'

afterEach(() => {
  vi.unstubAllGlobals()
})

function employee(overrides: Partial<Employee> = {}): Employee {
  return {
    id: 'e1',
    employee_no: 'E-001',
    current_office_id: 'o1',
    current_department_id: null,
    current_reports_to_id: null,
    hired_at: '2024-01-01',
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

describe('useEmployees', () => {
  it('fetches GET /employees with no query params', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [employee()] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const { result } = renderHook(() => useEmployees(), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([employee()])
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/employees', expect.anything())
  })
})
