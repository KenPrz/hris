import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AttendanceLog, AttendanceMonth, DailySummary } from '@/lib/api'
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

/** Routes GET /me, GET /me/attendance?month=..., GET /me/attendance/summary?month=..., and
 * POST /attendance/punch off one mock. The summary check MUST come before the plain
 * attendance check — both paths start with `/api/v1/me/attendance`. */
function stubApi(options: {
  attendanceByMonth?: Record<string, AttendanceMonth>
  summariesByMonth?: Record<string, DailySummary[]>
  punchResult?: AttendanceLog | (() => AttendanceLog)
}): ReturnType<typeof vi.fn> {
  const attendanceByMonth = options.attendanceByMonth ?? {}
  const summariesByMonth = options.summariesByMonth ?? {}

  const fn = vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'

    if (url === '/api/v1/me' && method === 'GET') {
      return { ok: true, status: 200, json: async () => sessionBody }
    }

    if (url.startsWith('/api/v1/me/attendance/summary') && method === 'GET') {
      const month = new URL(url, 'http://x').searchParams.get('month') ?? ''
      const data = summariesByMonth[month] ?? []
      return { ok: true, status: 200, json: async () => ({ data }) }
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

  it('falls back to the current month when ?month= names an impossible month', async () => {
    // `2026-99` passes a shape-only check but is not a real month — it must not render
    // "undefined 2026" over an empty grid.
    searchParams = new URLSearchParams('month=2026-99')
    const fetchMock = stubApi({ attendanceByMonth: { [THIS_MONTH]: { [TODAY]: [] } } })

    renderPage()

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some((call) => String(call[0]).startsWith(`/api/v1/me/attendance?month=${THIS_MONTH}`)),
      ).toBe(true)
    })

    expect(screen.getByText(monthLabel(THIS_MONTH))).toBeInTheDocument()
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

describe('/me/attendance — the computed layer', () => {
  function summaryFor(date: string, overrides: Partial<DailySummary> = {}): DailySummary {
    return {
      date,
      day_type: 'ordinary',
      is_rest_day: false,
      scheduled_minutes: 480,
      is_art82_exempt: false,
      worked_minutes: 540,
      late_minutes: 0,
      undertime_minutes: 0,
      status: 'final',
      is_incomplete: false,
      rule_version_id: 'rv1',
      lines: [
        { kind: 'regular_day', minutes: 480, applied_bp: 10000 },
        { kind: 'overtime_day', minutes: 60, applied_bp: 12500 },
      ],
      ...overrides,
    }
  }

  it('shows the computed worked total, the OT badge, and the breakdown ALONGSIDE the still-visible raw punch times', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({
      attendanceByMonth: {
        '2026-05': {
          // Raw pairing totals 8h (480m) — deliberately DIFFERENT from the computed
          // worked_minutes below (540m/"9h"), so the two totals can be told apart in the
          // assertions instead of colliding on the same rendered text.
          '2026-05-10': [
            punch({ direction: 'in', punched_at: '2026-05-10T08:00:00+08:00' }),
            punch({ direction: 'out', punched_at: '2026-05-10T16:00:00+08:00' }),
          ],
        },
      },
      summariesByMonth: { '2026-05': [summaryFor('2026-05-10')] },
    })

    renderPage()

    // The raw ledger: DayCell's punch-span text, unmodified.
    expect(await screen.findByText('08:00–16:00')).toBeInTheDocument()

    // The computed layer, on the same calendar cell: the worked total, the OT badge
    // (an overtime_* line exists), and the priced breakdown lines — all additive to the
    // raw punch span above, never a replacement for it.
    expect(screen.getByText('9h')).toBeInTheDocument()
    expect(screen.getByText('OT')).toBeInTheDocument()
    expect(screen.getByText('Regular (day)')).toBeInTheDocument()
    expect(screen.getByText('Overtime (day)')).toBeInTheDocument()

    // Both coexist for the same day — the honest ledger stays visible.
    expect(screen.getByText('08:00–16:00')).toBeInTheDocument()
  })

  it('shows the premium badge when a line prices above 100% (applied_bp > 10000)', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({
      // A punch elsewhere in the month so the ledger isn't empty and the calendar (rather
      // than the EmptyState) renders — the premium badge lives on 2026-05-12's own cell,
      // which itself has no raw punches, only a computed summary.
      attendanceByMonth: {
        '2026-05': { '2026-05-01': [punch({ punched_at: '2026-05-01T08:00:00+08:00' })] },
      },
      summariesByMonth: {
        '2026-05': [
          summaryFor('2026-05-12', {
            lines: [{ kind: 'regular_night', minutes: 480, applied_bp: 11000 }],
          }),
        ],
      },
    })

    renderPage()

    expect(await screen.findByText('premium')).toBeInTheDocument()
  })

  it('renders nothing extra for a day with no computed summary — the raw punches are unaffected', async () => {
    searchParams = new URLSearchParams('month=2026-05')
    stubApi({
      attendanceByMonth: {
        '2026-05': {
          '2026-05-10': [
            punch({ direction: 'in', punched_at: '2026-05-10T08:00:00+08:00' }),
            punch({ direction: 'out', punched_at: '2026-05-10T17:00:00+08:00' }),
          ],
        },
      },
      summariesByMonth: { '2026-05': [] },
    })

    renderPage()

    expect(await screen.findByText('08:00–17:00')).toBeInTheDocument()
    expect(screen.queryByText('OT')).not.toBeInTheDocument()
    expect(screen.queryByText('incomplete')).not.toBeInTheDocument()
  })
})
