import { render, screen, waitFor } from '@testing-library/react'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile, EmployeeProfileSummary, ProfileCatalog, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

// Mirrors src/app/(app)/me/profile/profile.test.tsx: AppShell calls useRouter/usePathname
// (next/navigation) — outside a real Next app-router tree that throws. useParams
// additionally needs a stub here because this page reads the [employee] route param, which
// next/navigation only supplies inside a real route.
vi.mock('next/navigation', () => ({
  useParams: () => ({ employee: 'e1' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/employees/e1/profile',
}))

// Mirrors src/app/(app)/admin/employees/[employee]/employee-detail.test.tsx's `stubSession`
// pattern: the page (and AppShell, underneath it) both read `useSession()` directly, and
// the "is this viewer looking at their own record" check needs to drive `session.employee.id`
// explicitly per test — going through the real SessionProvider (an unauthenticated `/me`
// query in this test environment) can never produce that.
vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))

import { useSession } from '@/hooks/useSession'

import EmployeeProfilePage from './page'

const mockedUseSession = vi.mocked(useSession)

function session(overrides: Partial<Session> = {}): Session {
  return {
    user: { id: 'u1', email: 'admin@x.com', name: 'Admin' },
    employee: null,
    is_system_admin: false,
    has_reports: false,
    hr_offices: ['o1'],
    permissions: [],
    ...overrides,
  }
}

/** Defaults to a viewer who is NOT the employee this page is showing ('e1') — most tests
 * are about an HR Admin or manager viewing someone else's record. */
function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView, and
// ProfileForm's Selects (Radix) call both on open — mirrors ProfileForm.test.tsx's own
// workaround; without it, mounting ProfileForm (the full-read branch renders it) throws.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

const fullProfile: EmployeeProfile = {
  employee_id: 'e1',
  employee_no: '2506366',
  full_name: 'Ken Daryl Austero Perez',
  details: {
    salutation: 'Mr.', first_name: 'Ken Daryl', middle_name: 'Austero',
    last_name: 'Perez', name_suffix: null, nickname: 'KENPE',
  },
  contact: {
    home_address: 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
    personal_email: null, phone: null, fax: null,
    mobile: '09166229187', emergency_contact: null,
  },
  personal: {
    gender: 'male', birth_date: '2002-01-23', age: 24, birthplace: null,
    marital_status: 'single', citizenship: 'Filipino',
    religion: 'Roman Catholic', blood_type: null,
  },
  assignment: {
    designation: 'Backend Software Developer',
    business_unit: 'Management Information System',
    reports_to: 'Castillo, Mark Jerome L.',
    employment_status: 'regular', location: 'Cebu', region: 'VII',
    labor_type: 'direct', hired_at: '2025-06-16',
    work_shift: '8:00 Am To 6:00 Pm - Rest Sat & Sun',
  },
  dependents: [],
  identifications: [],
}

const redactedProfile: EmployeeProfileSummary = {
  employee_id: 'e1',
  employee_no: '2506366',
  full_name: 'Ken Daryl Austero Perez',
  contact: { personal_email: 'ken@example.test', phone: null, mobile: '09166229187' },
  assignment: fullProfile.assignment,
}

const catalog: ProfileCatalog = {
  relationships: [{ id: 'rel-1', code: 'spouse', description: 'Spouse' }],
  identification_categories: [{ id: 'cat-1', code: 'TIN', name: 'TIN', description: null }],
}

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      profile: {
        forEmployee: vi.fn(),
        redacted: vi.fn(),
        catalog: vi.fn(),
        save: vi.fn(),
        saveDependents: vi.fn(),
        saveIdentification: vi.fn(),
        deleteIdentification: vi.fn(),
      },
    },
  }
})

const { api, ApiError } = await import('@/lib/api')

function apiNotFound(): InstanceType<typeof ApiError> {
  return new ApiError('not_found', 'Not found.', 404)
}

function renderPage() {
  return render(
    <Providers>
      <EmployeeProfilePage />
    </Providers>,
  )
}

beforeEach(() => {
  vi.mocked(api.profile.forEmployee).mockReset()
  vi.mocked(api.profile.redacted).mockReset()
  vi.mocked(api.profile.catalog).mockReset()
  vi.mocked(api.profile.catalog).mockResolvedValue(catalog)
  stubSession()
})

describe('/employees/[employee]/profile', () => {
  it('renders the full personnel file (and its edit form) for an authorized viewer', async () => {
    vi.mocked(api.profile.forEmployee).mockResolvedValue(fullProfile)
    renderPage()

    // Wait on the save button, not the heading: the heading resolves from `fullQuery`
    // alone, but `useProfileCatalog` only starts fetching once `fullQuery.isSuccess` (Item
    // 3's gate), so the form — which needs BOTH queries — settles a tick later than the
    // heading does. Waiting on the heading here would let the assertions below race a
    // still-loading catalog.
    expect(await screen.findByRole('button', { name: /save profile/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Ken Daryl Austero Perez' })).toBeInTheDocument()
    // ProfileSections (read view) — a personal-block field the redacted resource never has.
    expect(screen.getByText('Roman Catholic')).toBeInTheDocument()
    expect(screen.getByText('KENPE')).toBeInTheDocument()

    expect(api.profile.redacted).not.toHaveBeenCalled()
  })

  it('hides the edit form and shows a separation-of-duties notice when the viewer is looking at their own profile', async () => {
    // `EmployeePolicy::viewFullProfile` admits self (so this viewer still gets the full
    // read) but `updateProfile` denies self even for an HR Admin — a deliberate rule, not a
    // bug — so this must render the read view only, never a form whose every submit 403s.
    stubSession({ employee: { id: 'e1', employee_no: '2506366', current_office_id: 'o1', current_department_id: null } })
    vi.mocked(api.profile.forEmployee).mockResolvedValue(fullProfile)
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Ken Daryl Austero Perez' })).toBeInTheDocument()
    // Read view still shows.
    expect(screen.getByText('Roman Catholic')).toBeInTheDocument()
    expect(screen.getByText('KENPE')).toBeInTheDocument()
    // No save controls anywhere on the page — not the personal-details form, not
    // dependents, not identifications.
    expect(screen.queryByRole('button', { name: /save profile/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /save dependents/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /save identification/i })).not.toBeInTheDocument()
    // A notice explains the rule instead of leaving self to guess from a 403.
    expect(screen.getByText(/can.t edit your own personnel file/i)).toBeInTheDocument()

    // The catalog this viewer can never use (there's no form to populate) is never fetched.
    expect(api.profile.catalog).not.toHaveBeenCalled()
  })

  it('falls back to the redacted shape for a manager, without the sections it has no data for', async () => {
    vi.mocked(api.profile.forEmployee).mockRejectedValue(apiNotFound())
    vi.mocked(api.profile.redacted).mockResolvedValue(redactedProfile)
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Ken Daryl Austero Perez' })).toBeInTheDocument()
    expect(screen.getByText('ken@example.test')).toBeInTheDocument()
    expect(screen.getByText('Backend Software Developer')).toBeInTheDocument()

    // No personal block, no dependents, no national IDs, no edit form — the redacted
    // resource never carries them.
    expect(screen.queryByText('Roman Catholic')).not.toBeInTheDocument()
    expect(screen.queryByText('KENPE')).not.toBeInTheDocument()
    expect(screen.queryByText(/national ids/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /save profile/i })).not.toBeInTheDocument()

    // A manager never sees a dropdown on this view — GET /profile/catalog is
    // authenticated-but-unscoped, so firing it anyway would be a wasted request, not a
    // disclosure, but `useProfileCatalog` is gated on `fullQuery.isSuccess` specifically so
    // it never fires at all for a viewer stuck on the redacted fallback.
    expect(api.profile.catalog).not.toHaveBeenCalled()
  })

  it('shows a not-found state when neither the full nor the redacted read succeeds', async () => {
    vi.mocked(api.profile.forEmployee).mockRejectedValue(apiNotFound())
    vi.mocked(api.profile.redacted).mockRejectedValue(apiNotFound())
    renderPage()

    expect(await screen.findByText(/no personnel file to show/i)).toBeInTheDocument()
    await waitFor(() => expect(api.profile.redacted).toHaveBeenCalled())
  })

  it('shows an error notification when the full read fails for a reason other than 404', async () => {
    vi.mocked(api.profile.forEmployee).mockRejectedValue(new ApiError('server_error', 'Boom.', 500))
    renderPage()

    expect(await screen.findByText(/couldn't load this employee's personnel file/i)).toBeInTheDocument()
    expect(api.profile.redacted).not.toHaveBeenCalled()
  })
})
