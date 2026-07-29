import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AdminEmployee, AdminEmployeeDetail, CreatedEmployee, EmploymentRecord, ProvisionedUser } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    admin: {
      employees: {
        list: vi.fn(),
        show: vi.fn(),
        create: vi.fn(),
        update: vi.fn(),
        provisionUser: vi.fn(),
        recordEmployment: vi.fn(),
      },
    },
  },
}))

import { api } from '@/lib/api'

import {
  useAdminEmployee,
  useAdminEmployees,
  useCreateEmployee,
  useProvisionUser,
  useRecordEmployment,
  useUpdateEmployee,
} from './useAdminEmployees'

afterEach(() => {
  vi.clearAllMocks()
})

function employee(overrides: Partial<AdminEmployee> = {}): AdminEmployee {
  return {
    id: 'e1',
    employee_no: 'E-001',
    first_name: 'Ada',
    middle_name: null,
    last_name: 'Lovelace',
    name_suffix: null,
    full_name: 'Ada Lovelace',
    current_office_id: 'o1',
    current_department_id: 'd1',
    has_user: false,
    ...overrides,
  }
}

function detail(overrides: Partial<AdminEmployeeDetail> = {}): AdminEmployeeDetail {
  return {
    id: 'e1',
    employee_no: 'E-001',
    first_name: 'Ada',
    middle_name: null,
    last_name: 'Lovelace',
    name_suffix: null,
    full_name: 'Ada Lovelace',
    hired_at: '2026-01-01',
    has_user: false,
    current_employment: null,
    hr_admin_office_ids: [],
    roles: [],
    ...overrides,
  }
}

function created(overrides: Partial<CreatedEmployee> = {}): CreatedEmployee {
  return {
    id: 'e1',
    employee_no: 'E-001',
    first_name: 'Ada',
    middle_name: null,
    last_name: 'Lovelace',
    name_suffix: null,
    full_name: 'Ada Lovelace',
    current_office_id: 'o1',
    current_department_id: 'd1',
    current_reports_to_id: null,
    hired_at: '2026-01-01',
    ...overrides,
  }
}

const CREATE_BODY = {
  employee_no: 'E-001',
  organization_id: 'org1',
  hired_at: '2026-01-01',
  first_name: 'Ada',
  last_name: 'Lovelace',
  employment: {
    effective_from: '2026-01-01',
    office_id: 'o1',
    department_id: 'd1',
    employment_type: 'regular',
    is_art82_exempt: false,
    base_rate_cents: 5000000,
  },
} as const

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('useAdminEmployees — read', () => {
  it('fetches keys.admin.employees(params) via api.admin.employees.list, passing params through', async () => {
    vi.mocked(api.admin.employees.list).mockResolvedValue([employee()])

    const { result } = renderHook(() => useAdminEmployees({ office: 'o1' }), {
      wrapper: makeWrapper(newClient()),
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([employee()])
    expect(api.admin.employees.list).toHaveBeenCalledWith({ office: 'o1' })
  })
})

describe('useAdminEmployee — read', () => {
  it('fetches keys.admin.employee(id) via api.admin.employees.show', async () => {
    vi.mocked(api.admin.employees.show).mockResolvedValue(detail())

    const { result } = renderHook(() => useAdminEmployee('e1'), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual(detail())
    expect(api.admin.employees.show).toHaveBeenCalledWith('e1')
  })

  it('stays disabled for a null id', () => {
    const { result } = renderHook(() => useAdminEmployee(null), { wrapper: makeWrapper(newClient()) })

    expect(result.current.fetchStatus).toBe('idle')
    expect(api.admin.employees.show).not.toHaveBeenCalled()
  })
})

describe('useCreateEmployee', () => {
  it('invalidates keys.admin.employees() on success', async () => {
    vi.mocked(api.admin.employees.create).mockResolvedValue(created())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateEmployee(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(CREATE_BODY)
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.employees.create).toHaveBeenCalledWith(CREATE_BODY)
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employees() })
  })

  it('does not invalidate when the create is refused', async () => {
    vi.mocked(api.admin.employees.create).mockRejectedValue(new Error('refused'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateEmployee(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate(CREATE_BODY)
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})

describe('useUpdateEmployee', () => {
  it('invalidates the list and the detail on success', async () => {
    vi.mocked(api.admin.employees.update).mockResolvedValue(created({ first_name: 'Augusta' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateEmployee(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'e1', body: { first_name: 'Augusta', last_name: 'Lovelace' } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.employees.update).toHaveBeenCalledWith('e1', { first_name: 'Augusta', last_name: 'Lovelace' })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employees() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employee('e1') })
  })
})

describe('useProvisionUser', () => {
  it('invalidates the list and the detail on success', async () => {
    const user: ProvisionedUser = { id: 'u1', name: 'Ada Lovelace', email: 'ada@x.com' }
    vi.mocked(api.admin.employees.provisionUser).mockResolvedValue(user)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useProvisionUser(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'e1', body: { email: 'ada@x.com', password: 'secret123', name: 'Ada Lovelace' } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.employees.provisionUser).toHaveBeenCalledWith('e1', {
      email: 'ada@x.com',
      password: 'secret123',
      name: 'Ada Lovelace',
    })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employees() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employee('e1') })
  })
})

describe('useRecordEmployment', () => {
  it('invalidates the list and the detail on success', async () => {
    const record: EmploymentRecord = {
      id: 'emp1',
      employee_id: 'e1',
      effective_from: '2026-02-01',
      office_id: 'o1',
      department_id: 'd1',
      reports_to_id: null,
      employment_type: 'regular',
      is_art82_exempt: false,
      base_rate_cents: 6000000,
    }
    vi.mocked(api.admin.employees.recordEmployment).mockResolvedValue(record)

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useRecordEmployment(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({
        id: 'e1',
        body: {
          effective_from: '2026-02-01',
          office_id: 'o1',
          department_id: 'd1',
          employment_type: 'regular',
          is_art82_exempt: false,
          base_rate_cents: 6000000,
        },
      })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.employees.recordEmployment).toHaveBeenCalledWith('e1', {
      effective_from: '2026-02-01',
      office_id: 'o1',
      department_id: 'd1',
      employment_type: 'regular',
      is_art82_exempt: false,
      base_rate_cents: 6000000,
    })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employees() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.employee('e1') })
  })
})
