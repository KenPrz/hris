import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { AdminEmployee, Office, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/employees',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminEmployees', () => ({ useAdminEmployees: vi.fn() }))
vi.mock('@/hooks/useAdminOrgTree', () => ({ useOffices: vi.fn() }))

import { useAdminEmployees } from '@/hooks/useAdminEmployees'
import { useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import EmployeesPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseAdminEmployees = vi.mocked(useAdminEmployees)
const mockedUseOffices = vi.mocked(useOffices)

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

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubOffices(data: Office[] = [office()]): void {
  mockedUseOffices.mockReturnValue({ data, isLoading: false, isError: false } as unknown as ReturnType<
    typeof useOffices
  >)
}

function stubEmployees(overrides: Partial<ReturnType<typeof useAdminEmployees>> = {}): void {
  mockedUseAdminEmployees.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useAdminEmployees>)
}

function renderPage() {
  return render(
    <Providers>
      <EmployeesPage />
    </Providers>,
  )
}

describe('/admin/employees — list', () => {
  it('renders each employee with their name, employee number, office, and a login badge', () => {
    stubSession()
    stubOffices([office({ id: 'o1', name: 'Manila HQ' })])
    stubEmployees({
      data: [
        employee({ id: 'e1', full_name: 'Ada Lovelace', employee_no: 'E-001', current_office_id: 'o1', has_user: true }),
        employee({ id: 'e2', full_name: 'Grace Hopper', employee_no: 'E-002', current_office_id: 'o1', has_user: false }),
      ],
    })

    renderPage()

    expect(screen.getByRole('link', { name: 'Ada Lovelace' })).toHaveAttribute('href', '/admin/employees/e1')
    expect(screen.getByText('E-001')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Grace Hopper' })).toHaveAttribute('href', '/admin/employees/e2')
    expect(screen.getAllByText('Manila HQ').length).toBeGreaterThan(0)
    expect(screen.getByText('Has login')).toBeInTheDocument()
    expect(screen.getByText('No login')).toBeInTheDocument()
  })

  it('links "New employee" to the create wizard', () => {
    stubSession()
    stubOffices([])
    stubEmployees({ data: [] })

    renderPage()

    expect(screen.getByRole('link', { name: 'New employee' })).toHaveAttribute('href', '/admin/employees/new')
  })

  it('shows a loading skeleton', () => {
    stubSession()
    stubOffices([])
    stubEmployees({ isLoading: true })

    renderPage()

    expect(screen.getByRole('heading', { name: 'Employees' })).toBeInTheDocument()
  })

  it('shows an error notification when the list fails to load', () => {
    stubSession()
    stubOffices([])
    stubEmployees({ isError: true })

    renderPage()

    expect(screen.getByText(/Couldn't load employees/i)).toBeInTheDocument()
  })

  it('shows an empty state when there are no employees', () => {
    stubSession()
    stubOffices([])
    stubEmployees({ data: [] })

    renderPage()

    expect(screen.getByText(/No employees to show/i)).toBeInTheDocument()
  })

  it('a non-sysadmin sees a refusal notice instead of the roster', () => {
    stubSession({ is_system_admin: false })
    stubOffices([])
    stubEmployees({ data: [] })

    renderPage()

    expect(screen.getByText("This account can't administer employees.")).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'New employee' })).not.toBeInTheDocument()
  })

  it('filtering by office passes the office param to useAdminEmployees', () => {
    stubSession()
    stubOffices([office({ id: 'o1', name: 'Manila HQ' }), office({ id: 'o2', name: 'Cebu Annex' })])
    stubEmployees({ data: [] })

    renderPage()

    fireEvent.click(screen.getByLabelText('Office'))
    fireEvent.click(screen.getByRole('option', { name: 'Cebu Annex' }))

    expect(mockedUseAdminEmployees).toHaveBeenLastCalledWith({ office: 'o2' })
  })
})
