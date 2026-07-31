import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { AdminEmployee, Department, Office, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

const pushMock = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: pushMock, replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/employees/new',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminEmployees', () => ({
  useAdminEmployees: vi.fn(),
  useCreateEmployee: vi.fn(),
  useProvisionUser: vi.fn(),
}))
vi.mock('@/hooks/useAdminOrgTree', () => ({
  useOffices: vi.fn(),
  useDepartments: vi.fn(),
}))

import { useAdminEmployees, useCreateEmployee, useProvisionUser } from '@/hooks/useAdminEmployees'
import { useDepartments, useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import NewEmployeePage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseAdminEmployees = vi.mocked(useAdminEmployees)
const mockedUseCreateEmployee = vi.mocked(useCreateEmployee)
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
    region: null,
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

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubQuery<T>(data: T): { data: T; isLoading: boolean; isError: boolean } {
  return { data, isLoading: false, isError: false }
}

function renderPage() {
  return render(
    <Providers>
      <NewEmployeePage />
    </Providers>,
  )
}

async function chooseOption(labelText: string, optionName: string): Promise<void> {
  fireEvent.click(screen.getByLabelText(labelText))
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

describe('/admin/employees/new — create wizard', () => {
  it('walks identity → employment → login and calls create then provisionUser', async () => {
    const calls: string[] = []
    const createAsync = vi.fn(async () => {
      calls.push('create')
      return { id: 'e1' }
    })
    const provisionAsync = vi.fn(async () => {
      calls.push('provision')
      return { id: 'u1', name: 'Ada Lovelace', email: 'ada@x.com' }
    })

    stubSession()
    mockedUseOffices.mockReturnValue(stubQuery([office()]) as unknown as ReturnType<typeof useOffices>)
    mockedUseDepartments.mockReturnValue(stubQuery([department()]) as unknown as ReturnType<typeof useDepartments>)
    mockedUseAdminEmployees.mockReturnValue(
      stubQuery([] as AdminEmployee[]) as unknown as ReturnType<typeof useAdminEmployees>,
    )
    mockedUseCreateEmployee.mockReturnValue({
      mutateAsync: createAsync,
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useCreateEmployee>)
    mockedUseProvisionUser.mockReturnValue({
      mutateAsync: provisionAsync,
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useProvisionUser>)

    renderPage()

    // Step 1 — Identity.
    fireEvent.change(screen.getByLabelText('Employee number'), { target: { value: 'E-001' } })
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Ada' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Lovelace' } })
    fireEvent.change(screen.getByLabelText('Hire date'), { target: { value: '2026-01-01' } })

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    // Step 2 — Employment.
    await chooseOption('Office', 'Manila HQ')
    await chooseOption('Department', 'Payroll')
    fireEvent.change(screen.getByLabelText('Base rate (₱)'), { target: { value: '50000' } })

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    // Step 3 — Login (optional).
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'ada@x.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret123' } })

    fireEvent.click(screen.getByRole('button', { name: 'Finish' }))

    await waitFor(() => expect(createAsync).toHaveBeenCalledTimes(1))

    expect(createAsync).toHaveBeenCalledWith({
      employee_no: 'E-001',
      organization_id: 'org1',
      hired_at: '2026-01-01',
      first_name: 'Ada',
      middle_name: null,
      last_name: 'Lovelace',
      name_suffix: null,
      employment: {
        effective_from: '2026-01-01',
        office_id: 'o1',
        department_id: 'd1',
        reports_to_id: null,
        employment_type: 'regular',
        is_art82_exempt: false,
        base_rate_cents: 5000000,
      },
    })

    await waitFor(() => expect(provisionAsync).toHaveBeenCalledTimes(1))
    expect(provisionAsync).toHaveBeenCalledWith({
      id: 'e1',
      body: { email: 'ada@x.com', password: 'secret123', name: 'Ada Lovelace' },
    })

    // Create precedes provision, and a successful flow routes to the roster.
    expect(calls).toEqual(['create', 'provision'])
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith('/admin/employees'))
  })

  it('skips provisionUser when login is left blank', async () => {
    const createAsync = vi.fn(async () => ({ id: 'e1' }))
    const provisionAsync = vi.fn()

    stubSession()
    mockedUseOffices.mockReturnValue(stubQuery([office()]) as unknown as ReturnType<typeof useOffices>)
    mockedUseDepartments.mockReturnValue(stubQuery([department()]) as unknown as ReturnType<typeof useDepartments>)
    mockedUseAdminEmployees.mockReturnValue(
      stubQuery([] as AdminEmployee[]) as unknown as ReturnType<typeof useAdminEmployees>,
    )
    mockedUseCreateEmployee.mockReturnValue({
      mutateAsync: createAsync,
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useCreateEmployee>)
    mockedUseProvisionUser.mockReturnValue({
      mutateAsync: provisionAsync,
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useProvisionUser>)

    renderPage()

    fireEvent.change(screen.getByLabelText('Employee number'), { target: { value: 'E-002' } })
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Grace' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Hopper' } })
    fireEvent.change(screen.getByLabelText('Hire date'), { target: { value: '2026-03-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    await chooseOption('Office', 'Manila HQ')
    await chooseOption('Department', 'Payroll')
    fireEvent.change(screen.getByLabelText('Base rate (₱)'), { target: { value: '40000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    // Leave email/password blank.
    fireEvent.click(screen.getByRole('button', { name: 'Finish' }))

    await waitFor(() => expect(createAsync).toHaveBeenCalledTimes(1))
    expect(provisionAsync).not.toHaveBeenCalled()
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith('/admin/employees'))
  })

  it('refuses non-sysadmin accounts', () => {
    stubSession({ is_system_admin: false })
    mockedUseOffices.mockReturnValue(stubQuery([] as Office[]) as unknown as ReturnType<typeof useOffices>)
    mockedUseDepartments.mockReturnValue(stubQuery([] as Department[]) as unknown as ReturnType<typeof useDepartments>)
    mockedUseAdminEmployees.mockReturnValue(
      stubQuery([] as AdminEmployee[]) as unknown as ReturnType<typeof useAdminEmployees>,
    )
    mockedUseCreateEmployee.mockReturnValue({
      mutateAsync: vi.fn(),
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useCreateEmployee>)
    mockedUseProvisionUser.mockReturnValue({
      mutateAsync: vi.fn(),
      isPending: false,
      isError: false,
    } as unknown as ReturnType<typeof useProvisionUser>)

    renderPage()

    expect(screen.getByText("This account can't create employees.")).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument()
  })
})
