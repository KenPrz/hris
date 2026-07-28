import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { Department, Office, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/departments',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminOrgTree', () => ({
  useOffices: vi.fn(),
  useDepartments: vi.fn(),
  useCreateDepartment: vi.fn(),
  useUpdateDepartment: vi.fn(),
  useArchiveDepartment: vi.fn(),
  useUnarchiveDepartment: vi.fn(),
}))

import {
  useArchiveDepartment,
  useCreateDepartment,
  useDepartments,
  useOffices,
  useUnarchiveDepartment,
  useUpdateDepartment,
} from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import DepartmentsPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseOffices = vi.mocked(useOffices)
const mockedUseDepartments = vi.mocked(useDepartments)
const mockedUseCreateDepartment = vi.mocked(useCreateDepartment)
const mockedUseUpdateDepartment = vi.mocked(useUpdateDepartment)
const mockedUseArchiveDepartment = vi.mocked(useArchiveDepartment)
const mockedUseUnarchiveDepartment = vi.mocked(useUnarchiveDepartment)

beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  vi.clearAllMocks()
})

function session(overrides: Partial<Session> = {}): Session {
  return {
    user: { id: 'u1', email: 'admin@x.com', name: 'Admin' },
    employee: null,
    is_system_admin: true,
    has_reports: false,
    hr_offices: [],
    permissions: [],
    ...overrides,
  }
}

function office(overrides: Partial<Office> = {}): Office {
  return {
    id: 'off-1',
    organization_id: 'org-1',
    name: 'Manila HQ',
    code: 'MNL',
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
  return { id: 'dep-1', office_id: 'off-1', name: 'Engineering', code: 'ENG', archived_at: null, ...overrides }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubOffices(data: Office[] = [office()]): void {
  mockedUseOffices.mockReturnValue({
    data,
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useOffices>)
}

function stubDepartments(overrides: Partial<ReturnType<typeof useDepartments>> = {}): void {
  mockedUseDepartments.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useDepartments>)
}

function stubMutation(
  mocked: { mockReturnValue: (value: never) => unknown },
  overrides: Record<string, unknown> = {},
): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mocked.mockReturnValue({ mutate, isPending: false, isError: false, variables: undefined, ...overrides } as never)
  return mutate
}

function renderPage() {
  return render(
    <Providers>
      <DepartmentsPage />
    </Providers>,
  )
}

describe('/admin/departments — list', () => {
  it('renders each department with its name and code', () => {
    stubSession()
    stubOffices()
    stubDepartments({ data: [department({ name: 'Engineering', code: 'ENG' })] })
    stubMutation(mockedUseCreateDepartment)
    stubMutation(mockedUseUpdateDepartment)
    stubMutation(mockedUseArchiveDepartment)
    stubMutation(mockedUseUnarchiveDepartment)

    renderPage()

    expect(screen.getByText('Engineering')).toBeInTheDocument()
    expect(screen.getByText('ENG')).toBeInTheDocument()
  })

  it('hides archived departments until the show-archived toggle is on', () => {
    stubSession()
    stubOffices()
    stubDepartments({
      data: [
        department({ id: 'dep-active', name: 'Engineering', archived_at: null }),
        department({ id: 'dep-archived', name: 'Facilities', archived_at: '2026-01-01T00:00:00+08:00' }),
      ],
    })
    stubMutation(mockedUseCreateDepartment)
    stubMutation(mockedUseUpdateDepartment)
    stubMutation(mockedUseArchiveDepartment)
    stubMutation(mockedUseUnarchiveDepartment)

    renderPage()

    expect(screen.getByText('Engineering')).toBeInTheDocument()
    expect(screen.queryByText('Facilities')).not.toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Show archived'))

    expect(screen.getByText('Facilities')).toBeInTheDocument()
    expect(screen.getByText('Archived')).toBeInTheDocument()
  })
})

describe('/admin/departments — archive', () => {
  it('the Archive action on an active department calls the archive mutation with its id', () => {
    stubSession()
    stubOffices()
    stubDepartments({ data: [department({ id: 'dep-active', archived_at: null })] })
    stubMutation(mockedUseCreateDepartment)
    stubMutation(mockedUseUpdateDepartment)
    const archive = stubMutation(mockedUseArchiveDepartment)
    stubMutation(mockedUseUnarchiveDepartment)

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Archive' }))

    expect(archive).toHaveBeenCalledWith('dep-active')
  })
})

describe('/admin/departments — create', () => {
  it('submitting the create form calls the create mutation with the department body', () => {
    stubSession()
    stubOffices([office({ id: 'off-1', name: 'Manila HQ' })])
    stubDepartments({ data: [] })
    const create = stubMutation(mockedUseCreateDepartment)
    stubMutation(mockedUseUpdateDepartment)
    stubMutation(mockedUseArchiveDepartment)
    stubMutation(mockedUseUnarchiveDepartment)

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'New department' }))
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Sales' } })
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'SAL' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(create).toHaveBeenCalledWith({ office_id: 'off-1', name: 'Sales', code: 'SAL' }, expect.anything())
  })
})
