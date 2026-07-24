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

  it('has_reports adds Team', () => {
    const groups = navEntriesFor(buildSession({ has_reports: true }))

    expect(groups.map((g) => g.key)).toEqual(['me', 'team'])
  })

  it('a non-empty hr_offices adds Office', () => {
    const groups = navEntriesFor(buildSession({ hr_offices: ['office-1'] }))

    expect(groups.map((g) => g.key)).toEqual(['me', 'office'])
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

  it('Team/Office/Admin resolve to zero items in M3.5 — only Me has a shipped route', () => {
    const groups = navEntriesFor(
      buildSession({ is_system_admin: true, has_reports: true, hr_offices: ['office-1'] }),
    )

    const byKey = Object.fromEntries(groups.map((g) => [g.key, g]))
    expect(byKey.me?.items.length).toBeGreaterThan(0)
    expect(byKey.team?.items).toEqual([])
    expect(byKey.office?.items).toEqual([])
    expect(byKey.admin?.items).toEqual([])
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

  it('a manager sees NO empty "Team" heading — the anti-dead-end property', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ has_reports: true }))

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

  it('an HR admin sees NO empty "Office" heading, and a system admin sees NO empty "Admin" heading', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody({ hr_offices: ['office-1'], is_system_admin: true }))

    render(
      <Providers>
        <SideNav />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Attendance' })).toBeInTheDocument()
    })

    expect(screen.queryByText('Office')).not.toBeInTheDocument()
    expect(screen.queryByText('Admin')).not.toBeInTheDocument()
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
