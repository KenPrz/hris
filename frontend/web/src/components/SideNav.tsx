'use client'

/**
 * Scope rules (`navEntriesFor`) are split from rendering (`SideNav`) on purpose: the rules
 * are the durable thing later milestones extend as Team/Office/Admin screens ship, so they
 * get tested directly without rendering anything. The rendered nav stays honest about what
 * exists *today* — it skips any group whose `ROUTES` entry is still empty, so a manager
 * does not see a "Team" heading that dead-ends at nothing.
 */

import { usePathname } from 'next/navigation'

import type { Session } from '@/lib/api'
import { useSession } from '@/hooks/useSession'

export type NavItem = {
  href: string
  label: string
}

export type NavGroupKey = 'me' | 'team' | 'office' | 'admin'

export type NavGroup = {
  key: NavGroupKey
  label: string
  items: NavItem[]
}

/**
 * Routes that actually exist today. A group whose entry here is `[]` is a real scope the
 * session grants, with no shipped screen to send it to yet — `SideNav` hides it rather than
 * rendering a heading with nothing underneath.
 */
const ROUTES: Record<NavGroupKey, NavItem[]> = {
  me: [{ href: '/me/attendance', label: 'Attendance' }],
  team: [],
  office: [],
  admin: [],
}

const GROUP_LABEL: Record<NavGroupKey, string> = {
  me: 'Me',
  team: 'Team',
  office: 'Office',
  admin: 'Admin',
}

/**
 * Pure scope rules — no rendering, no hooks. `session === null` (not yet loaded, or
 * logged out) still yields Me: it is the one scope every authenticated user has, and the
 * session simply hasn't confirmed the others yet.
 *
 * This is convenience only — hiding a group here does not enforce anything; the server
 * already refused any request the underlying scope didn't grant.
 */
export function navEntriesFor(session: Session | null): NavGroup[] {
  const groups: NavGroup[] = [{ key: 'me', label: GROUP_LABEL.me, items: ROUTES.me }]

  if (session?.has_reports) {
    groups.push({ key: 'team', label: GROUP_LABEL.team, items: ROUTES.team })
  }

  if (session !== null && session.hr_offices.length > 0) {
    groups.push({ key: 'office', label: GROUP_LABEL.office, items: ROUTES.office })
  }

  if (session?.is_system_admin) {
    groups.push({ key: 'admin', label: GROUP_LABEL.admin, items: ROUTES.admin })
  }

  return groups
}

/** Real `<a>` tags, not `next/link` — every group but Me has zero routes today, and the
 * ones that do exist need nothing beyond a plain href; keeping this dependency-free also
 * keeps the component trivially renderable in isolation. */
export function SideNav() {
  const { session } = useSession()
  const pathname = usePathname()
  const groups = navEntriesFor(session).filter((group) => group.items.length > 0)

  return (
    <nav
      aria-label="Primary"
      style={{
        background: 'var(--surface-1)',
        borderRight: '1px solid var(--hairline)',
        minWidth: '12rem',
        padding: 'var(--sp-md) 0',
      }}
    >
      {groups.map((group) => (
        <div key={group.key} style={{ marginBottom: 'var(--sp-lg)' }}>
          <h2
            style={{
              font: 'var(--t-caption)',
              letterSpacing: 'var(--ls-caption)',
              color: 'var(--ink-subtle)',
              padding: '0 var(--sp-md)',
              marginBottom: 'var(--sp-xs)',
              textTransform: 'uppercase',
            }}
          >
            {group.label}
          </h2>
          <ul>
            {group.items.map((item) => {
              const isActive = pathname === item.href

              return (
                <li key={item.href}>
                  <a
                    href={item.href}
                    aria-current={isActive ? 'page' : undefined}
                    className="block focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--blue)]"
                    style={{
                      display: 'block',
                      color: 'var(--ink)',
                      font: 'var(--t-body-sm)',
                      letterSpacing: 'var(--ls-body)',
                      padding: 'var(--sp-xs) var(--sp-md)',
                      borderLeft: isActive ? '3px solid var(--blue)' : '3px solid transparent',
                      background: isActive ? 'var(--surface-2)' : 'transparent',
                      textDecoration: 'none',
                    }}
                  >
                    {item.label}
                  </a>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </nav>
  )
}
