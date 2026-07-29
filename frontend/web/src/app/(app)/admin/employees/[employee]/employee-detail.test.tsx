import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { AdminEmployee, AdminEmployeeDetail, Department, Office, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useParams: () => ({ employee: 'e1' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => '/admin/employees/e1',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminEmployees', () => ({
  useAdminEmployee: vi.fn(),
  useAdminEmployees: vi.fn(),
  useUpdateEmployee: vi.fn(),
  useRecordEmployment: vi.fn(),
  useProvisionUser: vi.fn(),
}))
vi.mock('@/hooks/useAdminOrgTree', () => ({
  useOffices: vi.fn(),
  useDepartments: vi.fn(),
}))

import {
  useAdminEmployee,
  useAdminEmployees,
  useProvisionUser,
  useRecordEmployment,
  useUpdateEmployee,
} from '@/hooks/useAdminEmployees'
import { useDepartments, useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import EmployeeDetailPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseAdminEmployee = vi.mocked(useAdminEmployee)
const mockedUseAdminEmployees = vi.mocked(useAdminEmployees)
const mockedUseUpdateEmployee = vi.mocked(useUpdateEmployee)
const mockedUseRecordEmployment = vi.mocked(useRecordEmployment)
const mockedUseProvisionUser = vi.mocked(useProvisionUser)
const mockedUseOffices = vi.mocked(useOffices)
const mockedUseDepartments = vi.mocked(useDepartments)

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
    id: 'o1',
    organization_id: 'org1',
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
  return { id: 'd1', office_id: 'o1', name: 'Payroll', code: 'PAY', archived_at: null, ...overrides }
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
    current_employment: {
      office_id: 'o1',
      department_id: 'd1',
      employment_type: 'regular',
      is_art82_exempt: false,
      base_rate_cents: 5000000,
      reports_to_id: null,
      effective_from: '2026-01-01',
    },
    ...overrides,
  }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubDetail(overrides: Partial<ReturnType<typeof useAdminEmployee>> = {}): void {
  mockedUseAdminEmployee.mockReturnValue({
    data: detail(),
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useAdminEmployee>)
}

function stubSupportingData(overrides: { offices?: Office[]; departments?: Department[]; employees?: AdminEmployee[] } = {}): void {
  mockedUseOffices.mockReturnValue({
    data: overrides.offices ?? [office()],
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useOffices>)
  mockedUseDepartments.mockReturnValue({
    data: overrides.departments ?? [department()],
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useDepartments>)
  mockedUseAdminEmployees.mockReturnValue({
    data: overrides.employees ?? [],
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useAdminEmployees>)
}

function stubMutation(
  mocked: { mockReturnValue: (value: never) => unknown },
  overrides: Record<string, unknown> = {},
): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mocked.mockReturnValue({ mutate, isPending: false, isError: false, ...overrides } as never)
  return mutate
}

function renderPage() {
  return render(
    <Providers>
      <EmployeeDetailPage />
    </Providers>,
  )
}

describe('/admin/employees/[employee] — detail', () => {
  it('renders the name, employee number, and current employment', () => {
    stubSession()
    stubDetail()
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.getByRole('heading', { name: 'Ada Lovelace' })).toBeInTheDocument()
    expect(screen.getByText(/Employee no\. E-001/)).toBeInTheDocument()
    expect(screen.getAllByText('Manila HQ').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Payroll').length).toBeGreaterThan(0)
    expect(screen.getByText('₱50,000.00')).toBeInTheDocument()
  })

  it('shows a loading skeleton while the detail query is pending', () => {
    stubSession()
    stubDetail({ data: undefined, isLoading: true })
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.getByRole('heading', { name: 'Employee' })).toBeInTheDocument()
  })

  it('shows an error notification when the detail fails to load', () => {
    stubSession()
    stubDetail({ data: undefined, isError: true })
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.getByText(/Couldn't load this employee/i)).toBeInTheDocument()
  })

  it('submitting the edit-name form calls useUpdateEmployee with the id and the new name', () => {
    stubSession()
    stubDetail()
    stubSupportingData()
    const update = stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Augusta' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save name' }))

    expect(update).toHaveBeenCalledWith({
      id: 'e1',
      body: { first_name: 'Augusta', middle_name: null, last_name: 'Lovelace', name_suffix: null },
    })
  })

  it('shows the provision-login form only when the employee has no user account', () => {
    stubSession()
    stubDetail({ data: detail({ has_user: false }) })
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.getByRole('heading', { name: 'Provision login' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })

  it('hides the provision-login form once the employee already has a user account', () => {
    stubSession()
    stubDetail({ data: detail({ has_user: true }) })
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.queryByRole('heading', { name: 'Provision login' })).not.toBeInTheDocument()
    expect(screen.getByText('Has login')).toBeInTheDocument()
  })

  it('submitting the provision form calls useProvisionUser with the id and the login body', () => {
    stubSession()
    stubDetail({ data: detail({ has_user: false }) })
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    const provision = stubMutation(mockedUseProvisionUser)

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'ada@x.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Provision login' }))

    expect(provision).toHaveBeenCalledWith({
      id: 'e1',
      body: { email: 'ada@x.com', password: 'secret123', name: 'Ada Lovelace' },
    })
  })

  it('a non-sysadmin sees a refusal notice instead of the profile', () => {
    stubSession({ is_system_admin: false })
    stubDetail()
    stubSupportingData()
    stubMutation(mockedUseUpdateEmployee)
    stubMutation(mockedUseRecordEmployment)
    stubMutation(mockedUseProvisionUser)

    renderPage()

    expect(screen.getByText("This account can't administer employees.")).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save name' })).not.toBeInTheDocument()
  })
})
