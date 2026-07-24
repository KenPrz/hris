import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { Providers } from '@/components/Providers'
import { PRODUCT_NAME } from '@/lib/brand'
import { clearToken, getToken, setToken } from '@/lib/session'

const push = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push }),
  usePathname: () => '/me/attendance',
}))

import { AppShell } from './AppShell'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
})

const sessionBody = {
  data: {
    user: { id: 'u1', email: 'a@b.com', name: 'Ada Employee' },
    employee: null,
    is_system_admin: false,
    has_reports: false,
    hr_offices: [],
    permissions: [],
  },
}

type RouteHandler = { status: number; body?: unknown; reject?: boolean }

function stubFetch(handlers: Record<string, RouteHandler>): ReturnType<typeof vi.fn> {
  const fn = vi.fn((input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : input.toString()
    const key = Object.keys(handlers).find((path) => url.includes(path))
    const handler = key ? handlers[key] : undefined

    if (!handler) {
      return Promise.reject(new Error(`unhandled fetch in test: ${url}`))
    }
    if (handler.reject) {
      return Promise.reject(new Error('network unreachable'))
    }
    return Promise.resolve({
      ok: handler.status >= 200 && handler.status < 300,
      status: handler.status,
      json: async () => handler.body,
    })
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

async function openMenuAndSignOut() {
  const trigger = await screen.findByRole('button', { name: 'Account menu' })
  fireEvent.click(trigger)
  const signOut = await screen.findByRole('menuitem', { name: 'Sign out' })
  fireEvent.click(signOut)
}

describe('AppShell — chrome', () => {
  it('renders the product name in the charcoal header', async () => {
    setToken('sekrit')
    stubFetch({ '/me': { status: 200, body: sessionBody } })

    render(
      <Providers>
        <AppShell>
          <div>content</div>
        </AppShell>
      </Providers>,
    )

    expect(await screen.findByText(PRODUCT_NAME)).toBeInTheDocument()
  })

  it('renders its children in the main content region', async () => {
    setToken('sekrit')
    stubFetch({ '/me': { status: 200, body: sessionBody } })

    render(
      <Providers>
        <AppShell>
          <div>page content</div>
        </AppShell>
      </Providers>,
    )

    expect(await screen.findByText('page content')).toBeInTheDocument()
    expect(screen.getByRole('main')).toHaveTextContent('page content')
  })
})

describe('AppShell — sign out', () => {
  it('calls api.logout(), clears the token, and redirects to /login', async () => {
    setToken('sekrit')
    const fetchMock = stubFetch({
      '/me': { status: 200, body: sessionBody },
      '/logout': { status: 204 },
    })

    render(
      <Providers>
        <AppShell>
          <div>content</div>
        </AppShell>
      </Providers>,
    )

    await openMenuAndSignOut()

    await waitFor(() => {
      expect(push).toHaveBeenCalledWith('/login')
    })
    expect(getToken()).toBeNull()
    expect(fetchMock.mock.calls.some((call) => String(call[0]).includes('/logout'))).toBe(true)
  })

  it('still clears the token and redirects to /login when api.logout() rejects', async () => {
    setToken('sekrit')
    stubFetch({
      '/me': { status: 200, body: sessionBody },
      '/logout': { status: 0, reject: true },
    })

    render(
      <Providers>
        <AppShell>
          <div>content</div>
        </AppShell>
      </Providers>,
    )

    await openMenuAndSignOut()

    await waitFor(() => {
      expect(push).toHaveBeenCalledWith('/login')
    })
    expect(getToken()).toBeNull()
  })
})
