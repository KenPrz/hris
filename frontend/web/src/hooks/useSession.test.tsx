import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { Providers } from '@/components/Providers'
import { clearToken, emitLogout, setToken } from '@/lib/session'

import { useSession } from './useSession'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
})

function stubFetch(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fn = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

const sessionBody = {
  data: {
    user: { id: 'u1', email: 'a@b.com', name: 'A' },
    employee: null,
    is_system_admin: false,
    has_reports: false,
    hr_offices: [],
    permissions: [],
  },
}

/** Renders three independent consumers of useSession(), each showing its own status. */
function Consumer({ label }: { label: string }) {
  const { session, isLoading, isAuthenticated } = useSession()
  return (
    <div data-testid={label}>
      {isLoading ? 'loading' : isAuthenticated ? `authed:${session?.user.email}` : 'anon'}
    </div>
  )
}

function ThreeConsumers() {
  return (
    <>
      <Consumer label="a" />
      <Consumer label="b" />
      <Consumer label="c" />
    </>
  )
}

describe('useSession — one /me for the whole tree', () => {
  it('issues exactly one GET /me even with three consumers mounted', async () => {
    setToken('sekrit')
    const fetchMock = stubFetch(200, sessionBody)

    render(
      <Providers>
        <ThreeConsumers />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByTestId('a')).toHaveTextContent('authed:a@b.com')
    })

    expect(screen.getByTestId('b')).toHaveTextContent('authed:a@b.com')
    expect(screen.getByTestId('c')).toHaveTextContent('authed:a@b.com')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('exposes isAuthenticated true and the session data after a successful /me', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody)

    render(
      <Providers>
        <Consumer label="solo" />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByTestId('solo')).toHaveTextContent('authed:a@b.com')
    })
  })

  it('never calls /me when there is no stored token', async () => {
    const fetchMock = stubFetch(200, sessionBody)

    render(
      <Providers>
        <Consumer label="solo" />
      </Providers>,
    )

    // Give any accidental async fetch a chance to fire before asserting it did not.
    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(screen.getByTestId('solo')).toHaveTextContent('anon')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('clears the session from context when emitLogout fires', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody)

    render(
      <Providers>
        <Consumer label="solo" />
      </Providers>,
    )

    await waitFor(() => {
      expect(screen.getByTestId('solo')).toHaveTextContent('authed:a@b.com')
    })

    emitLogout()

    await waitFor(() => {
      expect(screen.getByTestId('solo')).toHaveTextContent('anon')
    })
  })

  it('throws a clear error when used outside SessionProvider', () => {
    // Swallow the expected React error-boundary console noise for this one assertion.
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})

    expect(() => render(<Consumer label="orphan" />)).toThrow(/useSession/)

    consoleError.mockRestore()
  })
})
