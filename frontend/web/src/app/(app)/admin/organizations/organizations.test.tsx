import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { Organization, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/organizations',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useAdminOrgTree', () => ({
  useOrganizations: vi.fn(),
  useCreateOrganization: vi.fn(),
  useUpdateOrganization: vi.fn(),
}))

import { useCreateOrganization, useOrganizations, useUpdateOrganization } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'

import OrganizationsPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseOrganizations = vi.mocked(useOrganizations)
const mockedUseCreateOrganization = vi.mocked(useCreateOrganization)
const mockedUseUpdateOrganization = vi.mocked(useUpdateOrganization)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView — Radix
// Dialog calls both.
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
  return {
    id: 'org-1',
    name: 'Acme Corp',
    legal_name: 'Acme Corporation Inc.',
    tin: '123-456-789',
    timezone: 'Asia/Manila',
    ...overrides,
  }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubOrganizations(overrides: Partial<ReturnType<typeof useOrganizations>> = {}): void {
  mockedUseOrganizations.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useOrganizations>)
}

function stubCreate(overrides: Partial<ReturnType<typeof useCreateOrganization>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseCreateOrganization.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useCreateOrganization>)
  return mutate
}

function stubUpdate(overrides: Partial<ReturnType<typeof useUpdateOrganization>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseUpdateOrganization.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useUpdateOrganization>)
  return mutate
}

function renderPage() {
  return render(
    <Providers>
      <OrganizationsPage />
    </Providers>,
  )
}

describe('/admin/organizations — list', () => {
  it('renders each organization with its name, legal name, tin, and timezone', () => {
    stubSession()
    stubOrganizations({ data: [organization({ id: 'o1', name: 'Acme Corp', tin: '123-456-789' })] })
    stubCreate()
    stubUpdate()

    renderPage()

    expect(screen.getByRole('heading', { name: 'Organizations' })).toBeInTheDocument()
    expect(screen.getByText('Acme Corp')).toBeInTheDocument()
    expect(screen.getByText('Acme Corporation Inc.')).toBeInTheDocument()
    expect(screen.getByText('123-456-789')).toBeInTheDocument()
    expect(screen.getByText('Asia/Manila')).toBeInTheDocument()
  })

  it('shows an empty state when there are no organizations', () => {
    stubSession()
    stubOrganizations({ data: [] })
    stubCreate()
    stubUpdate()

    renderPage()

    expect(screen.getByText(/no organizations yet/i)).toBeInTheDocument()
  })

  it('gates the screen behind is_system_admin', () => {
    stubSession({ is_system_admin: false })
    stubOrganizations({ data: [] })
    stubCreate()
    stubUpdate()

    renderPage()

    expect(screen.getByText(/system-admin-only/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New organization' })).not.toBeInTheDocument()
  })
})

describe('/admin/organizations — create', () => {
  it('submitting the create form calls the create mutation with the typed body, blank optionals as null', () => {
    stubSession()
    stubOrganizations({ data: [] })
    const mutate = stubCreate()
    stubUpdate()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'New organization' }))
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Globex' } })
    fireEvent.change(screen.getByLabelText('Timezone'), { target: { value: 'Asia/Cebu' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(mutate).toHaveBeenCalledWith(
      { name: 'Globex', legal_name: null, tin: null, timezone: 'Asia/Cebu' },
      expect.anything(),
    )
  })
})
