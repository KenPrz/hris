import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { clearToken, getToken } from '@/lib/session'

const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace }),
}))

import LoginPage from './page'

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

function fillAndSubmit(email: string, password: string) {
  fireEvent.change(screen.getByLabelText('Work email'), { target: { value: email } })
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('/login — form fields', () => {
  it('exposes work email and password as real labelled inputs', () => {
    render(<LoginPage />)

    const email = screen.getByLabelText('Work email')
    const password = screen.getByLabelText('Password')

    expect(email).toHaveAttribute('type', 'email')
    expect(email).toHaveAttribute('autoComplete', 'username')
    expect(password).toHaveAttribute('type', 'password')
    expect(password).toHaveAttribute('autoComplete', 'current-password')
  })

  it('submits on Enter — a real <form> with a submit-type button, not a click handler only', () => {
    render(<LoginPage />)

    const button = screen.getByRole('button', { name: 'Sign in' })
    expect(button).toHaveAttribute('type', 'submit')
    expect(button.closest('form')).not.toBeNull()
  })
})

describe('/login — valid credentials', () => {
  it('calls api.login, stores the token, and navigates to /me/attendance', async () => {
    stubFetch(200, { data: { token: 'abc-token', user: { id: 'u1', email: 'a@b.com', name: 'A' } } })

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'correct-horse')

    await waitFor(() => {
      expect(replace).toHaveBeenCalledWith('/me/attendance')
    })

    expect(getToken()).toBe('abc-token')
  })
})

describe('/login — rejected credentials', () => {
  it('renders exactly "That email and password don\'t match." and stores no token', async () => {
    stubFetch(401, {
      error: { code: 'invalid_credentials', message: 'The email or password is incorrect.', details: {} },
    })

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'wrong-password')

    expect(await screen.findByText("That email and password don't match.")).toBeInTheDocument()
    expect(getToken()).toBeNull()
    expect(replace).not.toHaveBeenCalled()
  })

  it('shows the same message for an unknown email as for a wrong password — no enumeration leak', async () => {
    // M2 makes both cases return the identical `invalid_credentials` shape on purpose.
    // The UI must not know or care which one happened.
    stubFetch(401, {
      error: { code: 'invalid_credentials', message: 'The email or password is incorrect.', details: {} },
    })

    render(<LoginPage />)
    fillAndSubmit('unknown@nowhere.com', 'anything')

    expect(await screen.findByText("That email and password don't match.")).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })

  it('shows the same message even if a different auth-failure code came back from the server', async () => {
    // Proves the copy is not branching on `code` — any non-network auth failure renders
    // the one fixed string, regardless of what the server called it.
    stubFetch(401, {
      error: { code: 'unauthenticated', message: 'Different wording entirely.', details: {} },
    })

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'wrong-password')

    expect(await screen.findByText("That email and password don't match.")).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })
})

describe('/login — rate limited', () => {
  it('shows a distinct "too many attempts" message for a 429, never the credentials copy', async () => {
    // FINDING 5: `POST /login` is throttled 5/min per email+IP and returns 429
    // `too_many_requests`. The limiter keys on the submitted email regardless of
    // whether the account exists, so surfacing this distinctly leaks nothing about who
    // has an account — it only stops a rate limit from being misread as a wrong
    // password, which would just drive more attempts and extend the lockout.
    stubFetch(429, {
      error: { code: 'too_many_requests', message: 'Too many requests.', details: {} },
    })

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'wrong-password')

    expect(await screen.findByText('Too many attempts. Wait a minute and try again.')).toBeInTheDocument()
    expect(screen.queryByText("That email and password don't match.")).not.toBeInTheDocument()
    expect(getToken()).toBeNull()
  })
})

describe('/login — in-flight state', () => {
  it('disables the submit button while the request is pending, preventing a double submit', async () => {
    let resolveFetch: (value: unknown) => void = () => {}
    const pending = new Promise((resolve) => {
      resolveFetch = resolve
    })
    vi.stubGlobal(
      'fetch',
      vi.fn().mockReturnValue(pending),
    )

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'correct-horse')

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled()
    })

    resolveFetch({
      ok: true,
      status: 200,
      json: async () => ({ data: { token: 'abc', user: { id: 'u1', email: 'a@b.com', name: 'A' } } }),
    })

    await waitFor(() => {
      expect(replace).toHaveBeenCalledWith('/me/attendance')
    })
  })
})

describe('/login — network failure', () => {
  it('shows a distinct message for an unreachable server rather than the credentials copy', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('ECONNREFUSED')))

    render(<LoginPage />)
    fillAndSubmit('a@b.com', 'correct-horse')

    // Assert the message is actually rendered, not merely that the credentials copy is
    // absent — a regression that swallowed the error into a blank screen would satisfy
    // an absence-only assertion.
    expect(
      await screen.findByText('Cannot reach the server. Check your connection and try again.'),
    ).toBeInTheDocument()
    expect(screen.queryByText("That email and password don't match.")).not.toBeInTheDocument()
    expect(getToken()).toBeNull()
  })
})
