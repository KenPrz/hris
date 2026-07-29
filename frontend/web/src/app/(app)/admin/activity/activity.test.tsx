import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { ActivityEntry, ActivityPage, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/activity',
}))

vi.mock('@/hooks/useSession', () => ({ useSession: vi.fn() }))
vi.mock('@/hooks/useActivityLog', () => ({ useActivityLog: vi.fn() }))

import { useActivityLog } from '@/hooks/useActivityLog'
import { useSession } from '@/hooks/useSession'

import ActivityLogPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseActivityLog = vi.mocked(useActivityLog)

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

function entry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
    id: 'a1',
    log_name: 'office',
    description: 'office created',
    event: 'created',
    subject_type: 'App\\Models\\Office',
    subject_id: 'o1',
    causer_id: 'u1',
    properties: { name: 'Manila HQ' },
    created_at: '2026-07-01T00:00:00+00:00',
    ...overrides,
  }
}

function page(overrides: Partial<ActivityPage> = {}): ActivityPage {
  return {
    data: [entry()],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 50 },
    ...overrides,
  }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubActivityLog(overrides: Partial<ReturnType<typeof useActivityLog>> = {}): void {
  mockedUseActivityLog.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useActivityLog>)
}

function renderPage() {
  return render(
    <Providers>
      <ActivityLogPage />
    </Providers>,
  )
}

describe('/admin/activity — access', () => {
  it('a non-sysadmin sees the gated message, not the filters or table', () => {
    stubSession({ is_system_admin: false })
    stubActivityLog()

    renderPage()

    expect(screen.getByText(/system-admin-only/i)).toBeInTheDocument()
    expect(screen.queryByLabelText('Log name')).not.toBeInTheDocument()
  })
})

describe('/admin/activity — list', () => {
  it('shows a loading skeleton', () => {
    stubSession()
    stubActivityLog({ isLoading: true })

    renderPage()

    expect(screen.getByRole('heading', { name: 'Activity log' })).toBeInTheDocument()
  })

  it('shows an error notification', () => {
    stubSession()
    stubActivityLog({ isError: true })

    renderPage()

    expect(screen.getByText(/couldn't load the activity log/i)).toBeInTheDocument()
  })

  it('shows an empty state when there is no activity', () => {
    stubSession()
    stubActivityLog({ data: page({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 50 } }) })

    renderPage()

    expect(screen.getByText('No activity to show')).toBeInTheDocument()
  })

  it('renders rows from the query: created_at, causer, event, log name, description, subject type, a properties peek', () => {
    stubSession()
    stubActivityLog({ data: page() })

    renderPage()

    expect(screen.getByText('u1')).toBeInTheDocument()
    expect(screen.getByText('created')).toBeInTheDocument()
    expect(screen.getByText('office')).toBeInTheDocument()
    expect(screen.getByText('App\\Models\\Office')).toBeInTheDocument()
    expect(screen.getByText('office created')).toBeInTheDocument()
    expect(screen.getByText(/Manila HQ/)).toBeInTheDocument()
  })
})

describe('/admin/activity — filters', () => {
  it('changing the log_name filter re-queries useActivityLog with that filter (and resets page to 1)', () => {
    stubSession()
    stubActivityLog({ data: page() })

    renderPage()

    fireEvent.click(screen.getByRole('combobox', { name: 'Log name' }))
    fireEvent.click(screen.getByRole('option', { name: 'Office' }))

    expect(mockedUseActivityLog).toHaveBeenLastCalledWith(
      expect.objectContaining({ log_name: 'office', page: 1 }),
    )
  })

  it('changing the event filter re-queries useActivityLog with that filter', () => {
    stubSession()
    stubActivityLog({ data: page() })

    renderPage()

    fireEvent.click(screen.getByRole('combobox', { name: 'Event' }))
    fireEvent.click(screen.getByRole('option', { name: 'Created' }))

    expect(mockedUseActivityLog).toHaveBeenLastCalledWith(
      expect.objectContaining({ event: 'created', page: 1 }),
    )
  })

  it('changing the from date re-queries useActivityLog with that date', () => {
    stubSession()
    stubActivityLog({ data: page() })

    renderPage()

    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-07-01' } })

    expect(mockedUseActivityLog).toHaveBeenLastCalledWith(
      expect.objectContaining({ from: '2026-07-01', page: 1 }),
    )
  })
})

describe('/admin/activity — pagination', () => {
  it('Next advances the page', () => {
    stubSession()
    stubActivityLog({ data: page({ meta: { current_page: 1, last_page: 3, total: 120, per_page: 50 } }) })

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    expect(mockedUseActivityLog).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
  })

  it('Prev is disabled on the first page', () => {
    stubSession()
    stubActivityLog({ data: page({ meta: { current_page: 1, last_page: 3, total: 120, per_page: 50 } }) })

    renderPage()

    expect(screen.getByRole('button', { name: 'Prev' })).toBeDisabled()
  })

  it('Next is disabled on the last page', () => {
    stubSession()
    stubActivityLog({ data: page({ meta: { current_page: 3, last_page: 3, total: 120, per_page: 50 } }) })

    renderPage()

    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  })
})
