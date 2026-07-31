import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile } from '@/lib/api'
import { Providers } from '@/components/Providers'

// The page mirrors every other `(app)` route and wraps its content in `AppShell` (see
// src/app/(app)/me/leave/page.tsx) — `AppShell` calls `useRouter`/`usePathname` and
// `useSession`. Outside a real Next app-router tree, `useRouter`/`usePathname` throw
// "invariant expected app router to be mounted"; outside a `<SessionProvider>`,
// `useSession` throws its own guard error. Every other page test in this codebase that
// renders an `AppShell`-wrapped page (`leave.test.tsx`, `requests.test.tsx`,
// `team/approvals/approvals.test.tsx`) mocks `next/navigation` and renders through
// `<Providers>` (which supplies `QueryClientProvider` + `SessionProvider`) for the same
// reason — no token is set, so `SessionProvider`'s `GET /me` stays disabled and
// `useSession()` resolves to a `null` session, same as a not-yet-loaded page.
vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/me/profile',
}))

import ProfilePage from './page'

const profile: EmployeeProfile = {
  employee_id: 'emp-1',
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
  identifications: [
    {
      id: 'id-1', category_code: 'TIN', category_name: 'TIN',
      number: '653536955000', issued_on: null, expires_on: null,
      notes: null, has_scan: false,
    },
  ],
}

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { profile: { mine: vi.fn() } },
}))

const { api } = await import('@/lib/api')

function renderPage() {
  return render(
    <Providers>
      <ProfilePage />
    </Providers>,
  )
}

beforeEach(() => {
  vi.mocked(api.profile.mine).mockReset()
})

describe('/me/profile', () => {
  it('renders every section of the personnel file', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText('2506366')).toBeInTheDocument()
    expect(screen.getByText('KENPE')).toBeInTheDocument()
    expect(screen.getByText('09166229187')).toBeInTheDocument()
    expect(screen.getByText('Backend Software Developer')).toBeInTheDocument()
    expect(screen.getByText('8:00 Am To 6:00 Pm - Rest Sat & Sun')).toBeInTheDocument()
    expect(screen.getByText('653536955000')).toBeInTheDocument()
  })

  it('renders age as a readable label, not a bare number', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText('24 Years Old')).toBeInTheDocument()
  })

  it('renders an em dash for an empty field rather than blank space', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    // birthplace, phone, fax, emergency_contact, blood_type, name_suffix are all null.
    expect(await screen.findByText('Birthplace')).toBeInTheDocument()
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('shows an empty state when the employee has no dependents', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText(/no dependents/i)).toBeInTheDocument()
  })

  it('shows a skeleton while loading', () => {
    vi.mocked(api.profile.mine).mockReturnValue(new Promise(() => {}))
    const { container } = renderPage()

    expect(container.querySelector('[data-testid="profile-skeleton"]')).not.toBeNull()
  })
})
