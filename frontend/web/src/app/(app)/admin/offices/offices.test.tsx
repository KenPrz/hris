import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { Office, Organization, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/offices',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminOrgTree', () => ({
  useOrganizations: vi.fn(),
  useOffices: vi.fn(),
  useCreateOffice: vi.fn(),
  useUpdateOffice: vi.fn(),
  useArchiveOffice: vi.fn(),
  useUnarchiveOffice: vi.fn(),
}))

import {
  useArchiveOffice,
  useCreateOffice,
  useOffices,
  useOrganizations,
  useUnarchiveOffice,
  useUpdateOffice,
} from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import OfficesPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseOrganizations = vi.mocked(useOrganizations)
const mockedUseOffices = vi.mocked(useOffices)
const mockedUseCreateOffice = vi.mocked(useCreateOffice)
const mockedUseUpdateOffice = vi.mocked(useUpdateOffice)
const mockedUseArchiveOffice = vi.mocked(useArchiveOffice)
const mockedUseUnarchiveOffice = vi.mocked(useUnarchiveOffice)

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

function organization(overrides: Partial<Organization> = {}): Organization {
  return { id: 'org-1', name: 'Acme Corp', legal_name: null, tin: null, timezone: 'Asia/Manila', ...overrides }
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

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubOrganizations(data: Organization[] = [organization()]): void {
  mockedUseOrganizations.mockReturnValue({
    data,
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useOrganizations>)
}

function stubOffices(overrides: Partial<ReturnType<typeof useOffices>> = {}): void {
  mockedUseOffices.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useOffices>)
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
      <OfficesPage />
    </Providers>,
  )
}

describe('/admin/offices — list', () => {
  it('renders each office with its name, code, and timezone', () => {
    stubSession()
    stubOrganizations()
    stubOffices({ data: [office({ name: 'Manila HQ', code: 'MNL' })] })
    stubMutation(mockedUseCreateOffice)
    stubMutation(mockedUseUpdateOffice)
    stubMutation(mockedUseArchiveOffice)
    stubMutation(mockedUseUnarchiveOffice)

    renderPage()

    expect(screen.getByText('Manila HQ')).toBeInTheDocument()
    expect(screen.getByText(/MNL/)).toBeInTheDocument()
    expect(screen.getByText(/Asia\/Manila/)).toBeInTheDocument()
  })

  it('hides archived offices until the show-archived toggle is on, badging them when shown', () => {
    stubSession()
    stubOrganizations()
    stubOffices({
      data: [
        office({ id: 'off-active', name: 'Manila HQ', archived_at: null }),
        office({ id: 'off-archived', name: 'Cebu Annex', archived_at: '2026-01-01T00:00:00+08:00' }),
      ],
    })
    stubMutation(mockedUseCreateOffice)
    stubMutation(mockedUseUpdateOffice)
    stubMutation(mockedUseArchiveOffice)
    stubMutation(mockedUseUnarchiveOffice)

    renderPage()

    // Archived hidden by default.
    expect(screen.getByText('Manila HQ')).toBeInTheDocument()
    expect(screen.queryByText('Cebu Annex')).not.toBeInTheDocument()
    expect(screen.queryByText('Archived')).not.toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Show archived'))

    expect(screen.getByText('Cebu Annex')).toBeInTheDocument()
    expect(screen.getByText('Archived')).toBeInTheDocument()
  })
})

describe('/admin/offices — archive', () => {
  it('the Archive action on an active office calls the archive mutation with its id', () => {
    stubSession()
    stubOrganizations()
    stubOffices({ data: [office({ id: 'off-active', archived_at: null })] })
    stubMutation(mockedUseCreateOffice)
    stubMutation(mockedUseUpdateOffice)
    const archive = stubMutation(mockedUseArchiveOffice)
    stubMutation(mockedUseUnarchiveOffice)

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Archive' }))

    expect(archive).toHaveBeenCalledWith('off-active')
  })

  it('the Unarchive action on an archived office calls the unarchive mutation with its id', () => {
    stubSession()
    stubOrganizations()
    stubOffices({ data: [office({ id: 'off-archived', archived_at: '2026-01-01T00:00:00+08:00' })] })
    stubMutation(mockedUseCreateOffice)
    stubMutation(mockedUseUpdateOffice)
    stubMutation(mockedUseArchiveOffice)
    const unarchive = stubMutation(mockedUseUnarchiveOffice)

    renderPage()

    // Archived rows need the toggle on to be visible.
    fireEvent.click(screen.getByLabelText('Show archived'))
    fireEvent.click(screen.getByRole('button', { name: 'Unarchive' }))

    expect(unarchive).toHaveBeenCalledWith('off-archived')
  })
})

describe('/admin/offices — create', () => {
  it('submitting the create form calls the create mutation with the office body', () => {
    stubSession()
    stubOrganizations([organization({ id: 'org-1', name: 'Acme Corp' })])
    stubOffices({ data: [] })
    const create = stubMutation(mockedUseCreateOffice)
    stubMutation(mockedUseUpdateOffice)
    stubMutation(mockedUseArchiveOffice)
    stubMutation(mockedUseUnarchiveOffice)

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'New office' }))
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Davao Branch' } })
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'DVO' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(create).toHaveBeenCalledTimes(1)
    const [body] = create.mock.calls[0]
    expect(body).toMatchObject({
      organization_id: 'org-1',
      name: 'Davao Branch',
      code: 'DVO',
      timezone: 'Asia/Manila',
      geofence_lat: null,
      ip_allowlist: null,
      default_shift_template_id: null,
    })
  })
})
