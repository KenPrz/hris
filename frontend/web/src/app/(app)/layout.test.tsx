import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { clearToken, setToken } from '@/lib/session'

const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace }),
}))

import AppLayout from './layout'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  replace.mockClear()
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

describe('(app)/layout guard', () => {
  it('redirects to /login when there is no token', async () => {
    stubFetch(200, sessionBody)

    render(
      <AppLayout>
        <div data-testid="protected">secret</div>
      </AppLayout>,
    )

    await waitFor(() => {
      expect(replace).toHaveBeenCalledWith('/login')
    })

    expect(screen.queryByTestId('protected')).not.toBeInTheDocument()
  })

  it('redirects to /login when /me fails', async () => {
    setToken('sekrit')
    stubFetch(401, { error: { code: 'unauthenticated', message: 'Nope.', details: {} } })

    render(
      <AppLayout>
        <div data-testid="protected">secret</div>
      </AppLayout>,
    )

    await waitFor(() => {
      expect(replace).toHaveBeenCalledWith('/login')
    })

    expect(screen.queryByTestId('protected')).not.toBeInTheDocument()
  })

  it('renders children once the session resolves', async () => {
    setToken('sekrit')
    stubFetch(200, sessionBody)

    render(
      <AppLayout>
        <div data-testid="protected">secret</div>
      </AppLayout>,
    )

    await waitFor(() => {
      expect(screen.getByTestId('protected')).toBeInTheDocument()
    })

    expect(replace).not.toHaveBeenCalled()
  })
})
