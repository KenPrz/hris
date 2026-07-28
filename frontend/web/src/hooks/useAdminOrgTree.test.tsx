import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Department, Office, Organization } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    admin: {
      organizations: { list: vi.fn(), create: vi.fn(), update: vi.fn() },
      offices: { list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(), unarchive: vi.fn() },
      departments: { list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(), unarchive: vi.fn() },
    },
  },
}))

import { api } from '@/lib/api'

import {
  useArchiveDepartment,
  useArchiveOffice,
  useCreateDepartment,
  useCreateOffice,
  useCreateOrganization,
  useDepartments,
  useOffices,
  useOrganizations,
  useUnarchiveDepartment,
  useUnarchiveOffice,
  useUpdateDepartment,
  useUpdateOffice,
  useUpdateOrganization,
} from './useAdminOrgTree'

afterEach(() => {
  vi.clearAllMocks()
})

function organization(overrides: Partial<Organization> = {}): Organization {
  return { id: 'org1', name: 'Acme', legal_name: null, tin: null, timezone: 'Asia/Manila', ...overrides }
}

function office(overrides: Partial<Office> = {}): Office {
  return {
    id: 'o1',
    organization_id: 'org1',
    name: 'Main Branch',
    code: 'MB-01',
    timezone: 'Asia/Manila',
    geofence_lat: null,
    geofence_lng: null,
    geofence_radius_m: null,
    ip_allowlist: null,
    default_shift_template_id: null,
    archived_at: null,
    ...overrides,
  }
}

function department(overrides: Partial<Department> = {}): Department {
  return { id: 'd1', office_id: 'o1', name: 'Payroll', code: 'PAY', archived_at: null, ...overrides }
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

describe('useOrganizations — read', () => {
  it('fetches keys.admin.organizations() via api.admin.organizations.list', async () => {
    vi.mocked(api.admin.organizations.list).mockResolvedValue([organization()])

    const { result } = renderHook(() => useOrganizations(), { wrapper: makeWrapper(newClient()) })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([organization()])
    expect(api.admin.organizations.list).toHaveBeenCalledWith()
  })
})

describe('useOffices — read', () => {
  it('fetches keys.admin.offices(params) via api.admin.offices.list, passing params through', async () => {
    vi.mocked(api.admin.offices.list).mockResolvedValue([office()])

    const { result } = renderHook(() => useOffices({ organization: 'org1' }), {
      wrapper: makeWrapper(newClient()),
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([office()])
    expect(api.admin.offices.list).toHaveBeenCalledWith({ organization: 'org1' })
  })
})

describe('useDepartments — read', () => {
  it('fetches keys.admin.departments(params) via api.admin.departments.list, passing params through', async () => {
    vi.mocked(api.admin.departments.list).mockResolvedValue([department()])

    const { result } = renderHook(() => useDepartments({ office: 'o1' }), {
      wrapper: makeWrapper(newClient()),
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(result.current.data).toEqual([department()])
    expect(api.admin.departments.list).toHaveBeenCalledWith({ office: 'o1' })
  })
})

describe('organizations — mutations invalidate keys.admin.organizations()', () => {
  it('useCreateOrganization invalidates on success', async () => {
    vi.mocked(api.admin.organizations.create).mockResolvedValue(organization())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateOrganization(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ name: 'Acme', timezone: 'Asia/Manila' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.organizations.create).toHaveBeenCalledWith({ name: 'Acme', timezone: 'Asia/Manila' })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.organizations() })
  })

  it('useUpdateOrganization invalidates on success', async () => {
    vi.mocked(api.admin.organizations.update).mockResolvedValue(organization({ name: 'Renamed' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateOrganization(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'org1', body: { name: 'Renamed', timezone: 'Asia/Manila' } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.organizations.update).toHaveBeenCalledWith('org1', {
      name: 'Renamed',
      timezone: 'Asia/Manila',
    })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.organizations() })
  })

  it('does not invalidate when the create is refused', async () => {
    vi.mocked(api.admin.organizations.create).mockRejectedValue(new Error('refused'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateOrganization(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ name: 'Acme', timezone: 'Asia/Manila' })
    })

    await waitFor(() => expect(result.current.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})

describe('offices — mutations invalidate keys.admin.offices()', () => {
  it('useCreateOffice invalidates on success', async () => {
    vi.mocked(api.admin.offices.create).mockResolvedValue(office())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateOffice(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ organization_id: 'org1', name: 'Main Branch', code: 'MB-01', timezone: 'Asia/Manila' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.offices() })
  })

  it('useUpdateOffice invalidates on success', async () => {
    vi.mocked(api.admin.offices.update).mockResolvedValue(office({ name: 'New Name' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateOffice(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({
        id: 'o1',
        body: { organization_id: 'org1', name: 'New Name', code: 'MB-01', timezone: 'Asia/Manila' },
      })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.offices.update).toHaveBeenCalledWith('o1', {
      organization_id: 'org1',
      name: 'New Name',
      code: 'MB-01',
      timezone: 'Asia/Manila',
    })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.offices() })
  })

  it('useArchiveOffice invalidates on success', async () => {
    vi.mocked(api.admin.offices.archive).mockResolvedValue(office({ archived_at: '2026-07-28T00:00:00Z' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useArchiveOffice(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('o1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.offices.archive).toHaveBeenCalledWith('o1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.offices() })
  })

  it('useUnarchiveOffice invalidates on success', async () => {
    vi.mocked(api.admin.offices.unarchive).mockResolvedValue(office())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUnarchiveOffice(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('o1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.offices.unarchive).toHaveBeenCalledWith('o1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.offices() })
  })
})

describe('departments — mutations invalidate keys.admin.departments()', () => {
  it('useCreateDepartment invalidates on success', async () => {
    vi.mocked(api.admin.departments.create).mockResolvedValue(department())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useCreateDepartment(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ office_id: 'o1', name: 'Payroll', code: 'PAY' })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.departments() })
  })

  it('useUpdateDepartment invalidates on success', async () => {
    vi.mocked(api.admin.departments.update).mockResolvedValue(department({ name: 'Renamed' }))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUpdateDepartment(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate({ id: 'd1', body: { office_id: 'o1', name: 'Renamed', code: 'PAY' } })
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.departments.update).toHaveBeenCalledWith('d1', {
      office_id: 'o1',
      name: 'Renamed',
      code: 'PAY',
    })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.departments() })
  })

  it('useArchiveDepartment invalidates on success', async () => {
    vi.mocked(api.admin.departments.archive).mockResolvedValue(
      department({ archived_at: '2026-07-28T00:00:00Z' }),
    )

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useArchiveDepartment(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('d1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.departments.archive).toHaveBeenCalledWith('d1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.departments() })
  })

  it('useUnarchiveDepartment invalidates on success', async () => {
    vi.mocked(api.admin.departments.unarchive).mockResolvedValue(department())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useUnarchiveDepartment(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.mutate('d1')
    })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    expect(api.admin.departments.unarchive).toHaveBeenCalledWith('d1')
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.admin.departments() })
  })
})
