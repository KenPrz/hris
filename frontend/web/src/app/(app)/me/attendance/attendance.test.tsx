import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AttendanceLog, AttendanceMonth } from '@/lib/api'
import { addMonths, currentMonth, monthLabel, todayInZone } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

const push = vi.fn()
const replace = vi.fn()
let searchParams = new URLSearchParams()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => searchParams,
  usePathname: () => '/me/attendance',
}))

import AttendancePage from './page'

const THIS_MONTH = currentMonth(OFFICE_TIME_ZONE)
const TODAY = todayInZone(OFFICE_TIME_ZONE)

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
  searchParams = new URLSearchParams()
})

function punch(overrides: Partial<AttendanceLog> = {}): AttendanceLog {
  return {
    id: 'p1',
    employee_id: 'e1',
    office_id: 'o1',
    punched_at: `${TODAY}T08:02:00+08:00`,
    direction: 'in',
    source: 'web',
    verification: 'verified',
    flag_reason: null,
    ...overrides,
  }
}

const sessionBody = {
  data: {
    user: { id: 'u1', email: 'a@b.com', name: 'A' },
    employee: {
      id: 'e1',
      employee_no: 'E-001',
      current_office_id: 'o1',
      current_department_id: null,
    },
    is_system_admin: false,
    has_reports: false,
    hr_offices: [],
    permissions: [],
  },
}

/** Routes GET /me, GET /me/attendance?month=..., and POST /attendance/punch off one mock. */
function stubApi(options: {
  attendanceByMonth?: Record<string, AttendanceMonth>
  punchResult?: AttendanceLog | (() => AttendanceLog)
}): ReturnType<typeof vi.fn> {
  const attendanceByMonth = options.attendanceByMonth ?? {}

  const fn = vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'

    if (url === '/api/v1/me' && method === 'GET') {
      return { ok: true, status: 200, json: async () => sessionBody }
    }

    if (url.startsWith('/api/v1/me/attendance') && method === 'GET') {
      const month = new URL(url, 'http://x').searchParams.get('month') ?? ''
      const data = attendanceByMonth[month] ?? {}
      return { ok: true, status: 200, json: async () => ({ data }) }
    }

    if (url === '/api/v1/attendance/punch' && method === 'POST') {
      const result =
        typeof options.punchResult === 'function' ? options.punchResult() : (options.punchResult ?? punch())
      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    throw new Error(`Unhandled fetch in test: ${method} ${url}`)
  })

  vi.stubGlobal('fetch', fn)
  return fn
}

function renderPage() {
  setToken('sekrit')
  return render(
    <Providers>
      <AttendancePage />
    </Providers>,
  )
}

describe('/me/attendance — punch hero', () => {
  it('renders "Clock in" and "Clocked out" when there are no punches today', async () => {
    stubApi({ attendanceByMonth: { [THIS_MONTH]: { [TODAY]: [] } } })

    renderPage()

    expect(await screen.findByRole('button', { name: 'Clock in' })).toBeInTheDocument()
    expect(screen.getByText('Clocked out')).toBeInTheDocument()
  })

  it('renders "Clock in" for today even when other days this month have punches (direction is derived from TODAY only)', async () => {
    const otherDay = THIS_MONTH === '2026-07' ? '2026-07-02' : `${THIS_MONTH}-02`
    stubApi({
      attendanceByMonth: {
        [THIS_MONTH]: {
          [otherDay]: [punch({ punched_at: `${otherDay}T08:00:00+08:00` })],
        },
      },
    })

    renderPage()

    expect(await screen.findByRole('button', { name: 'Clock in' })).toBeInTheDocument()
  })

  it('renders "Clock out" and a "Clocked in since" state when the last punch today is an in (odd count)', async () => {
    stubApi({
      attendanceByMonth: {
        [THIS_MONTH]: { [TODAY]: [punch({ direction: 'in', punched_at: `${TODAY}T08:02:00+08:00` })] },
      },
    })

    renderPage()

    expect(await screen.findByRole('button', { name: 'Clock out' })).toBeInTheDocument()
    expect(screen.getByText('Clocked in since 08:02')).toBeInTheDocument()
  })

  it('renders "Clock in" (never "Clock out") when today has a single, odd-count `out` punch — a night-shift close-out from yesterday', async () => {
    // FINDING 1: an odd punch count must NOT imply "next is out." Today's only punch is
    // an `out` (e.g. clocking out at 00:12 for a shift that started yesterday) — the
    // *last* punch is `out`, so the next action is `in`, even though the count (1) is
    // odd. A parity-based rule gets this backwards and would let the button write a
    // second consecutive `out` into the append-only ledger.
    stubApi({
      attendanceByMonth: {
        [THIS_MONTH]: { [TODAY]: [punch({ direction: 'out', punched_at: `${TODAY}T00:12:00+08:00` })] },
      },
    })

    renderPage()

    expect(await screen.findByRole('button', { name: 'Clock in' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clock out' })).not.toBeInTheDocument()
    // The status line must agree with the button — both derive from the same last punch.
    expect(screen.getByText('Clocked out since 00:12')).toBeInTheDocument()
    expect(screen.queryByText(/Clocked in/)).not.toBeInTheDocument()
  })

  it('refetches the month after a successful punch', async () => {
    const fetchMock = stubApi({
      attendanceByMonth: { [THIS_MONTH]: { [TODAY]: [] } },
      punchResult: () => punch({ id: 'p2', direction: 'in', punched_at: `${TODAY}T09:00:00+08:00` }),
    })

    renderPage()

    const button = await screen.findByRole('button', { name: 'Clock in' })

    const attendanceCallsBefore = fetchMock.mock.calls.filter((call) =>
      String(call[0]).startsWith('/api/v1/me/attendance'),
    ).length

    fireEvent.click(button)

    await waitFor(() => {
      const attendanceCallsAfter = fetchMock.mock.calls.filter((call) =>
        String(call[0]).startsWith('/api/v1/me/attendance'),
      ).length
      expect(attendanceCallsAfter).toBeGreaterThan(attendanceCallsBefore)
    })
  })
})

describe('/me/attendance — live "Today" total', () => {
  it('adds elapsed time since an open clock-in — the total climbs while still clocked in, never stuck at 0m', async () => {
    // FINDING 3: `completedMinutesSoFar` only counted CLOSED pairs, so the tile read
    // "0m" from the moment of clock-in until clock-out — no live total, contradicting
    // the spec and features.md. Clocked in 1h05m ago with no clock-out yet: the total
    // must include that open, still-running segment.
    const openedAt = new Date(Date.now() - 65 * 60_000).toISOString()
    stubApi({
      attendanceByMonth: {
        [THIS_MONTH]: { [TODAY]: [punch({ direction: 'in', punched_at: openedAt })] },
      },
    })

    renderPage()

    expect(await screen.findByText('1h 5m')).toBeInTheDocument()
    expect(screen.queryByText('0m')).not.toBeInTheDocument()
  })
})

describe('/me/attendance — no linked employee record', () => {
  it('renders an explanatory empty state — never the "check your connection" copy — when the session has no employee', async () => {
    // FINDING 4: a bare System Admin account (session.employee === null) has no other
    // route to go to; the screen must explain the situation, not misreport it as a
    // connectivity problem.
    const fn = vi.fn().mockImplementation(async (url: string) => {
      if (url === '/api/v1/me') {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              user: { id: 'u1', email: 'admin@x.com', name: 'Admin' },
              employee: null,
              is_system_admin: true,
              has_reports: false,
              hr_offices: [],
              permissions: [],
            },
          }),
        }
      }

      if (url.startsWith('/api/v1/me/attendance')) {
        return {
          ok: false,
          status: 422,
          json: async () => ({
            error: { code: 'not_an_employee', message: 'Only an employee can do that.', details: {} },
          }),
        }
      }

      throw new Error(`Unhandled fetch in test: ${url}`)
    })
    vi.stubGlobal('fetch', fn)

    setToken('sekrit')
    render(
      <Providers>
        <AttendancePage />
      </Providers>,
    )

    expect(await screen.findByText(/isn't linked to an employee record/i)).toBeInTheDocument()
    expect(screen.queryByText(/check your connection/i)).not.toBeInTheDocument()
  })
})

describe('/me/attendance — month in the URL', () => {
  it('fetches the month named by ?month= rather than the current month', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    const fetchMock = stubApi({ attendanceByMonth: { '2026-05': { '2026-05-10': [] } } })

    renderPage()

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some((call) => String(call[0]).startsWith('/api/v1/me/attendance?month=2026-05')),
      ).toBe(true)
    })

    expect(screen.getByText(monthLabel('2026-05'))).toBeInTheDocument()
  })

  it('defaults to the current month when ?month= is absent', async () => {
    const fetchMock = stubApi({ attendanceByMonth: { [THIS_MONTH]: { [TODAY]: [] } } })

    renderPage()

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some((call) => String(call[0]).startsWith(`/api/v1/me/attendance?month=${THIS_MONTH}`)),
      ).toBe(true)
    })

    expect(screen.getByText(monthLabel(THIS_MONTH))).toBeInTheDocument()
  })

  it('navigates to next month via the router when "Next month" is clicked', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({ attendanceByMonth: { '2026-05': {} } })

    renderPage()

    const nextButton = await screen.findByRole('button', { name: 'Next month' })
    fireEvent.click(nextButton)

    expect(replace).toHaveBeenCalledWith(expect.stringContaining(`month=${addMonths('2026-05', 1)}`))
  })

  it('navigates to previous month via the router when "Previous month" is clicked', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({ attendanceByMonth: { '2026-05': {} } })

    renderPage()

    const prevButton = await screen.findByRole('button', { name: 'Previous month' })
    fireEvent.click(prevButton)

    expect(replace).toHaveBeenCalledWith(expect.stringContaining(`month=${addMonths('2026-05', -1)}`))
  })
})

describe('/me/attendance — empty state', () => {
  it('renders EmptyState instead of a bare grid when the viewed month has no punches at all', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({ attendanceByMonth: { '2026-05': {} } })

    renderPage()

    expect(await screen.findByText(/no punches/i)).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
