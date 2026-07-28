import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { CutoffPeriod, Session } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/office/cutoffs',
}))

vi.mock('@/hooks/useSession', () => ({
  useSession: vi.fn(),
}))

vi.mock('@/hooks/useCutoffs', () => ({
  useCutoffs: vi.fn(),
}))

vi.mock('@/hooks/useCloseCutoff', () => ({
  useCloseCutoff: vi.fn(),
}))

vi.mock('@/hooks/useReopenCutoff', () => ({
  useReopenCutoff: vi.fn(),
}))

import { useCloseCutoff } from '@/hooks/useCloseCutoff'
import { useCutoffs } from '@/hooks/useCutoffs'
import { useReopenCutoff } from '@/hooks/useReopenCutoff'
import { useSession } from '@/hooks/useSession'

import CutoffsPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseCutoffs = vi.mocked(useCutoffs)
const mockedUseCloseCutoff = vi.mocked(useCloseCutoff)
const mockedUseReopenCutoff = vi.mocked(useReopenCutoff)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView — Radix
// Select/Dialog call both. Same stubs as the leave-types suite.
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
    user: { id: 'u1', email: 'hr@x.com', name: 'HR' },
    employee: { id: 'e-hr', employee_no: 'E-000', current_office_id: 'o1', current_department_id: null },
    is_system_admin: false,
    has_reports: false,
    hr_offices: ['o1'],
    permissions: [],
    ...overrides,
  }
}

function period(overrides: Partial<CutoffPeriod> = {}): CutoffPeriod {
  return {
    id: 'p1',
    office_id: 'o1',
    start_date: '2026-07-01',
    end_date: '2026-07-15',
    state: 'closed',
    closed_by: 'u1',
    closed_at: '2026-07-16T00:00:00+08:00',
    ...overrides,
  }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubCutoffs(overrides: Partial<ReturnType<typeof useCutoffs>> = {}): void {
  mockedUseCutoffs.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useCutoffs>)
}

function stubCloseCutoff(overrides: Partial<ReturnType<typeof useCloseCutoff>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseCloseCutoff.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    isSuccess: false,
    error: null,
    variables: undefined,
    ...overrides,
  } as unknown as ReturnType<typeof useCloseCutoff>)
  return mutate
}

function stubReopenCutoff(overrides: Partial<ReturnType<typeof useReopenCutoff>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseReopenCutoff.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useReopenCutoff>)
  return mutate
}

function renderPage() {
  return render(
    <Providers>
      <CutoffsPage />
    </Providers>,
  )
}

describe('/office/cutoffs — list', () => {
  it('shows a loading skeleton', () => {
    stubSession()
    stubCutoffs({ isLoading: true })
    stubCloseCutoff()
    stubReopenCutoff()

    renderPage()

    expect(screen.getByRole('heading', { name: 'Cutoffs' })).toBeInTheDocument()
  })

  it('shows an empty state when the office has no periods', () => {
    stubSession()
    stubCutoffs({ data: [] })
    stubCloseCutoff()
    stubReopenCutoff()

    renderPage()

    expect(screen.getByText(/no cutoff periods/i)).toBeInTheDocument()
  })

  it('renders a closed period with a Closed tag and a Reopen action, and an open one with a Close action', () => {
    stubSession()
    stubCutoffs({
      data: [
        period({ id: 'p-closed', state: 'closed' }),
        // The synthetic current window: id null, still open.
        period({ id: null, start_date: '2026-07-16', end_date: '2026-07-31', state: 'open', closed_by: null, closed_at: null }),
      ],
    })
    stubCloseCutoff()
    stubReopenCutoff()

    renderPage()

    expect(screen.getByText('Closed')).toBeInTheDocument()
    expect(screen.getByText('Open')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reopen' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Close period' })).toBeInTheDocument()
  })
})

describe('/office/cutoffs — close', () => {
  it('closing the open (synthetic, null-id) window calls useCloseCutoff with office_id + its period_start', () => {
    stubSession()
    stubCutoffs({
      data: [
        period({ id: null, start_date: '2026-07-16', end_date: '2026-07-31', state: 'open', closed_by: null, closed_at: null }),
      ],
    })
    const mutate = stubCloseCutoff()
    stubReopenCutoff()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Close period' }))

    expect(mutate).toHaveBeenCalledWith({ office_id: 'o1', period_start: '2026-07-16' })
  })

  it('surfaces the blocking exceptions from a cutoff_has_unresolved_exceptions 422', () => {
    stubSession()
    stubCutoffs({
      data: [
        period({ id: null, start_date: '2026-07-16', end_date: '2026-07-31', state: 'open', closed_by: null, closed_at: null }),
      ],
    })
    stubCloseCutoff({
      isError: true,
      error: new ApiError(
        'cutoff_has_unresolved_exceptions',
        'This cutoff period has unresolved exceptions and cannot be closed.',
        422,
        { incomplete_dates: ['2026-07-20', '2026-07-22'], pending_request_ids: ['r1', 'r2', 'r3'] },
      ),
    })
    stubReopenCutoff()

    renderPage()

    expect(screen.getByText(/can't be closed yet/i)).toBeInTheDocument()
    expect(screen.getByText(/2026-07-20, 2026-07-22/)).toBeInTheDocument()
    expect(screen.getByText(/3 pending requests/i)).toBeInTheDocument()
  })
})

describe('/office/cutoffs — reopen', () => {
  it('reopening a closed period prompts for a reason and calls useReopenCutoff with the period id + reason', async () => {
    stubSession()
    stubCutoffs({ data: [period({ id: 'p-closed', state: 'closed' })] })
    stubCloseCutoff()
    const mutate = stubReopenCutoff()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'Reopen' }))

    const reasonInput = await screen.findByLabelText('Reason for reopening')

    // Confirm is disabled until a reason is typed — no bare reopen.
    expect(screen.getByRole('button', { name: 'Reopen period' })).toBeDisabled()

    fireEvent.change(reasonInput, { target: { value: 'Correcting a late punch' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reopen period' }))

    await waitFor(() => {
      expect(mutate).toHaveBeenCalledWith(
        { id: 'p-closed', reason: 'Correcting a late punch' },
        expect.anything(),
      )
    })
  })
})
