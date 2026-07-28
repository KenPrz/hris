import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Session } from '@/lib/api'
import { Providers } from '@/components/Providers'
import { clearToken, setToken } from '@/lib/session'

let currentPathname = '/me/attendance'

vi.mock('next/navigation', () => ({
  usePathname: () => currentPathname,
}))

import { navEntriesFor, SideNav } from './SideNav'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  currentPathname = '/me/attendance'
})

function buildSession(overrides: Partial<Session> = {}): Session {
  return {
    user: { id: 'u1', email: 'a@b.com', name: 'A' },
    employee: null,
    is_system_admin: false,
    has_reports: false,
    hr_offices: [],
    permissions: [],
    ...overrides,
  }
}

function stubFetch(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fn = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

function sessionBody(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      user: { id: 'u1', email: 'a@b.com', name: 'A' },
      employee: null,
      is_system_admin: false,
      has_reports: false,
      hr_offices: [],
      permissions: [],
      ...overrides,
    },
  }
}

describe('navEntriesFor — the scope rules (pure, no rendering)', () => {
  it('a plain employee yields only Me', () => {
    const groups = navEntriesFor(buildSession())

    expect(groups.map((g) => g.key)).toEqual(['me'])
  })

  it('every user\'s Me group includes /me/requests — filing and reviewing your own requests is universal', () => {
    const groups = navEntriesFor(buildSession())
    const me = groups.find((g) => g.key === 'me')

    expect(me?.items).toContainEqual({ href: '/me/requests', label: 'My requests' })
  })

  it('every user\'s Me group includes /me/leave (M6b-a) — filing and checking your own leave balance is universal', () => {
    const groups = navEntriesFor(buildSession())
    const me = groups.find((g) => g.key === 'me')

    expect(me?.items).toContainEqual({ href: '/me/leave', label: 'Leave' })
  })

  it('has_reports adds Team', () => {
    const groups = navEntriesFor(buildSession({ has_reports: true }))

    expect(groups.map((g) => g.key)).toEqual(['me', 'team'])
  })

  it('a has_reports user\'s Team group includes /team/approvals', () => {
    const groups = navEntriesFor(buildSession({ has_reports: true }))
    const team = groups.find((g) => g.key === 'team')

    expect(team?.items).toContainEqual({ href: '/team/approvals', label: 'Approvals' })
  })

  it('a non-empty hr_offices adds Office', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: ['office-1'] }))

    expect(groups.map((g) => g.key)).toEqual(['me', 'office'])
  })

  it('an hr_offices user\'s Office group includes /office/approvals', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: ['office-1'] }))
    const office = groups.find((g) => g.key === 'office')

    expect(office?.items).toContainEqual({ href: '/office/approvals', label: 'Approvals' })
  })

  it('an hr_offices user\'s Office group includes /office/leave-types (M6b-a)', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: ['office-1'] }))
    const office = groups.find((g) => g.key === 'office')

    expect(office?.items).toContainEqual({ href: '/office/leave-types', label: 'Leave types' })
  })

  it('an hr_offices user\'s Office group includes /office/cutoffs (M7a)', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: ['office-1'] }))
    const office = groups.find((g) => g.key === 'office')

    expect(office?.items).toContainEqual({ href: '/office/cutoffs', label: 'Cutoffs' })
  })

  it('an empty hr_offices does NOT add Office', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: [] }))

    expect(groups.map((g) => g.key)).not.toContain('office')
  })

  it('is_system_admin adds Admin', () => {
    const groups = navEntriesFor(buildSession({ is_system_admin: true }))

    expect(groups.map((g) => g.key)).toEqual(['me', 'admin'])
  })

  it('a system admin with reports and offices yields all four groups', () => {
    const groups = navEntriesFor(
      buildSession({ is_system_admin: true, has_reports: true, hr_offices: ['office-1'] }),
    )

    expect(groups.map((g) => g.key)).toEqual(['me', 'team', 'office', 'admin'])
  })

  it('a null session (not yet loaded) still yields Me', () => {
    const groups = navEntriesFor(null)

    expect(groups.map((g) => g.key)).toEqual(['me'])
  })

  it('a user with every scope sees every ROUTES entry, group by group (M6a: Team and Office both ship an Approvals link)', () => {
    const groups = navEntriesFor(
      buildSession({ is_system_admin: true, has_reports: true, hr_offices: ['office-1'] }),
    )

    const byKey = Object.fromEntries(groups.map((g) => [g.key, g]))
    expect(byKey.me?.items).toEqual([
      { href: '/me/attendance', label: 'Attendance' },
      { href: '/me/requests', label: 'My requests' },
      { href: '/me/leave', label: 'Leave' },
    ])
    expect(byKey.team?.items).toEqual([{ href: '/team/approvals', label: 'Approvals' }])
    expect(byKey.office?.items).toEqual([
      { href: '/office/holidays', label: 'Holidays' },
      { href: '/office/schedules', label: 'Schedules' },
      { href: '/office/leave-types', label: 'Leave types' },
      { href: '/office/cutoffs', label: 'Cutoffs' },
      { href: '/office/approvals', label: 'Approvals' },
    ])
    expect(byKey.admin?.items).toEqual([{ href: '/admin/pay-rules', label: 'Pay rules' }])
  })
})

describe('SideNav — rendered', () => {
  it('a plain employee sees the Me items', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody())

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Attendance' })).toBeInTheDocument()
    })
    expect(screen.getByText('Me')).toBeInTheDocument()
  })

  it('a manager sees a Team group with an Approvals link (M6a)', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ has_reports: true }))

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    const approvalsLink = await screen.findByRole('link', { name: 'Approvals' })
    expect(approvalsLink).toHaveAttribute('href', '/team/approvals')
    expect(screen.getByText('Team')).toBeInTheDocument()
  })

  it('a plain employee (no reports) sees no Team group at all — the anti-dead-end property', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody())

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Attendance' })).toBeInTheDocument()
    })

    expect(screen.queryByText('Team')).not.toBeInTheDocument()
  })

  it('a sysadmin sees the Admin group with a Pay rules link', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ is_system_admin: true }))

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    const payRulesLink = await screen.findByRole('link', { name: 'Pay rules' })
    expect(payRulesLink).toHaveAttribute('href', '/admin/pay-rules')
    expect(screen.getByText('Admin')).toBeInTheDocument()
  })

  it('a non-sysadmin does not see the Admin group or the Pay rules link', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ is_system_admin: false }))

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Attendance' })).toBeInTheDocument()
    })

    expect(screen.queryByText('Admin')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Pay rules' })).not.toBeInTheDocument()
  })

  it('an HR admin sees an Office group with Holidays AND Schedules links', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ hr_offices: ['office-1'] }))

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    // `findBy` polls, unlike the plain `Attendance` waitFor elsewhere in this file — Me
    // renders even before the session fetch resolves (navEntriesFor(null) already yields
    // Me), so only waiting on the thing that depends on the loaded session is a real wait.
    const holidaysLink = await screen.findByRole('link', { name: 'Holidays' })
    expect(holidaysLink).toHaveAttribute('href', '/office/holidays')

    const schedulesLink = await screen.findByRole('link', { name: 'Schedules' })
    expect(schedulesLink).toHaveAttribute('href', '/office/schedules')

    const leaveTypesLink = await screen.findByRole('link', { name: 'Leave types' })
    expect(leaveTypesLink).toHaveAttribute('href', '/office/leave-types')

    const approvalsLink = await screen.findByRole('link', { name: 'Approvals' })
    expect(approvalsLink).toHaveAttribute('href', '/office/approvals')

    expect(screen.getByText('Office')).toBeInTheDocument()
  })

  it('a plain employee (no reports, no hr_offices, not sysadmin) sees only Me — no Office group', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody())

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Attendance' })).toBeInTheDocument()
    })

    expect(screen.queryByText('Office')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Holidays' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Schedules' })).not.toBeInTheDocument()
  })

  it('marks the active route with aria-current="page" and a 3px --blue left border', async () => {
    currentPathname = '/me/attendance'
    setToken('sekrit')
    stubFetch(200, sessionBody())

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    const link = await screen.findByRole('link', { name: 'Attendance' })
    expect(link).toHaveAttribute('aria-current', 'page')
    // jsdom's CSSOM cannot resolve a `var()` inside the `border-left` shorthand (it
    // silently drops to `medium`/`none`), so `toHaveStyle` can't assert this reliably —
    // the raw inline `style` attribute is the honest thing to check here.
    expect(link.getAttribute('style')).toContain('border-left: 3px solid var(--blue)')
  })

  it('does not mark aria-current on an item that is not the active route', async () => {
    currentPathname = '/somewhere-else'
    setToken('sekrit')
    stubFetch(200, sessionBody())

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    const link = await screen.findByRole('link', { name: 'Attendance' })
    expect(link).not.toHaveAttribute('aria-current')
  })
})
