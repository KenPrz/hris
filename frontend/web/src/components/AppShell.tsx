'use client'

/**
 * The authenticated chrome: a charcoal top header over a scope-grouped `SideNav` and a
 * main content region. Sign-out is written so a dead network or an already-expired token
 * can never strand the user signed in locally — the token is cleared and the redirect
 * fires in `finally`, regardless of how `api.logout()` resolves.
 */

import { useState } from 'react'
import type { ReactNode } from 'react'
import { useRouter } from 'next/navigation'

import { api } from '@/lib/api'
import { PRODUCT_NAME } from '@/lib/brand'
import { clearToken } from '@/lib/session'
import { useSession } from '@/hooks/useSession'
import { SideNav } from './SideNav'

export interface AppShellProps {
  children: ReactNode
}

export function AppShell({ children }: AppShellProps) {
  const router = useRouter()
  const { session } = useSession()
  const [menuOpen, setMenuOpen] = useState(false)

  async function handleSignOut() {
    try {
      await api.logout()
    } catch {
      // Network down, or the token was already dead server-side — either way there is
      // nothing more the server can tell us, and the user must not be left stuck signed
      // in locally because of it.
    } finally {
      clearToken()
      router.push('/login')
    }
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header
        className="flex items-center justify-between"
        style={{
          background: 'var(--inverse-canvas)',
          color: 'var(--inverse-ink)',
          height: 'var(--sp-xxl)',
          padding: '0 var(--sp-md)',
          flexShrink: 0,
        }}
      >
        <div className="flex items-center" style={{ gap: 'var(--sp-md)' }}>
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)' }}>{PRODUCT_NAME}</span>
          {/*
            Office context is intentionally absent. The session carries only
            `current_office_id` — a uuid — and a bare uuid in the product header reads as
            broken chrome. It returns when the session (or a lookup) carries a real office
            name; showing nothing is better than showing an id.
          */}
        </div>

        <div className="relative">
          <button
            type="button"
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            aria-label="Account menu"
            onClick={() => setMenuOpen((open) => !open)}
            className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
            style={{
              background: 'transparent',
              border: 'none',
              color: 'var(--inverse-ink)',
              font: 'var(--t-body-sm)',
              letterSpacing: 'var(--ls-body)',
              cursor: 'pointer',
              padding: 'var(--sp-xxs) var(--sp-xs)',
            }}
          >
            {session?.user.name ?? 'Account'}
          </button>
          {menuOpen ? (
            <div
              role="menu"
              style={{
                position: 'absolute',
                right: 0,
                top: '100%',
                marginTop: 'var(--sp-xxs)',
                background: 'var(--inverse-surface-1)',
                border: '1px solid var(--ink-subtle)',
                borderRadius: 'var(--radius)',
                minWidth: '8rem',
                zIndex: 10,
              }}
            >
              <button
                type="button"
                role="menuitem"
                onClick={handleSignOut}
                className="w-full text-left focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--blue)]"
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: 'var(--inverse-ink)',
                  font: 'var(--t-body-sm)',
                  letterSpacing: 'var(--ls-body)',
                  cursor: 'pointer',
                  padding: 'var(--sp-sm) var(--sp-md)',
                }}
              >
                Sign out
              </button>
            </div>
          ) : null}
        </div>
      </header>

      <div className="flex flex-1">
        <SideNav />
        <main className="flex-1" style={{ background: 'var(--canvas)', padding: 'var(--sp-lg)' }}>
          {children}
        </main>
      </div>
    </div>
  )
}
