'use client'

/**
 * The authenticated chrome: a charcoal top header over a scope-grouped `SideNav` and a
 * main content region. Sign-out is written so a dead network or an already-expired token
 * can never strand the user signed in locally — the token is cleared and the redirect
 * fires in `finally`, regardless of how `api.logout()` resolves.
 */

import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { usePathname, useRouter } from 'next/navigation'

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
  const pathname = usePathname()
  const { session } = useSession()
  const [menuOpen, setMenuOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const [navOpen, setNavOpen] = useState(false)
  const navRef = useRef<HTMLDivElement>(null)
  const navTriggerRef = useRef<HTMLButtonElement>(null)

  // Standard menu-button dismissal: Escape and a click outside both close it, and Escape
  // returns focus to the trigger so a keyboard user isn't dropped at the top of the page.
  useEffect(() => {
    if (!menuOpen) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setMenuOpen(false)
        triggerRef.current?.focus()
      }
    }

    function onPointerDown(event: PointerEvent) {
      if (menuRef.current !== null && !menuRef.current.contains(event.target as Node)) {
        setMenuOpen(false)
      }
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [menuOpen])

  // Same dismissal idiom as the account menu above, applied to the mobile nav overlay:
  // Escape closes and returns focus to the hamburger; a pointerdown outside both the nav
  // panel and its own trigger closes it too.
  useEffect(() => {
    if (!navOpen) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setNavOpen(false)
        navTriggerRef.current?.focus()
      }
    }

    function onPointerDown(event: PointerEvent) {
      const target = event.target as Node
      const insideNav = navRef.current !== null && navRef.current.contains(target)
      const insideTrigger = navTriggerRef.current !== null && navTriggerRef.current.contains(target)
      if (!insideNav && !insideTrigger) {
        setNavOpen(false)
      }
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [navOpen])

  // A route change means the user just navigated somewhere via the overlay — close it
  // rather than leaving it covering the freshly-loaded page. Gated on a ref holding the
  // previous pathname (rather than an unconditional `setNavOpen(false)`) so the effect
  // only ever touches state on an actual change, not on every render this effect runs.
  const prevPathnameRef = useRef(pathname)
  useEffect(() => {
    if (prevPathnameRef.current !== pathname) {
      prevPathnameRef.current = pathname
      setNavOpen(false)
    }
  }, [pathname])

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
          <button
            ref={navTriggerRef}
            type="button"
            aria-expanded={navOpen}
            aria-controls="primary-navigation"
            aria-label={navOpen ? 'Close navigation' : 'Open navigation'}
            onClick={() => setNavOpen((open) => !open)}
            className="md:hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
            style={{
              background: 'transparent',
              border: 'none',
              color: 'var(--inverse-ink)',
              font: 'var(--t-emphasis)',
              letterSpacing: 'var(--ls-body)',
              cursor: 'pointer',
              padding: 'var(--sp-xxs) var(--sp-xs)',
              lineHeight: 1,
            }}
          >
            <span aria-hidden="true">≡</span>
          </button>
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)' }}>{PRODUCT_NAME}</span>
          {/*
            Office context is intentionally absent. The session carries only
            `current_office_id` — a uuid — and a bare uuid in the product header reads as
            broken chrome. It returns when the session (or a lookup) carries a real office
            name; showing nothing is better than showing an id.
          */}
        </div>

        <div className="relative" ref={menuRef}>
          <button
            ref={triggerRef}
            type="button"
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            aria-controls="account-menu"
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
              id="account-menu"
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
        {/*
          One `<SideNav />` in the DOM, always — below `md` these classes make it a fixed,
          full-screen overlay only while `navOpen` (a scrim beside a full-height nav panel,
          both riding the flex row); at `md` and up the `md:` variants win the cascade and
          it reverts to the persistent rail it's always been. Rendering it twice (mobile
          copy + desktop copy) would put two `nav aria-label="Primary"` landmarks in the
          page at once, which is the bug this comment exists to prevent.
        */}
        <div
          id="primary-navigation"
          ref={navRef}
          className={`${navOpen ? 'fixed inset-0 z-20 flex' : 'hidden'} md:static md:inset-auto md:z-auto md:flex`}
        >
          {navOpen ? (
            <div
              aria-hidden="true"
              onClick={() => setNavOpen(false)}
              className="fixed inset-0 md:hidden"
              style={{ background: 'color-mix(in srgb, var(--ink) 50%, transparent)' }}
            />
          ) : null}
          <div className="relative h-full md:h-auto">
            <SideNav />
          </div>
        </div>
        <main className="flex-1" style={{ background: 'var(--canvas)', padding: 'var(--sp-lg)' }}>
          {children}
        </main>
      </div>
    </div>
  )
}
