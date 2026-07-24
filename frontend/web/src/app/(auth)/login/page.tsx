'use client'

/**
 * The split-canvas login: a charcoal brand panel beside a white form. The one screen
 * every user sees before the product has anything else to show them, so it gets the
 * one piece of restrained visual voice the rest of the app deliberately doesn't spend.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { useRouter } from 'next/navigation'

import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { TextInput } from '@/components/ui/TextInput'
import { ApiError, api } from '@/lib/api'
import { PRODUCT_NAME } from '@/lib/brand'
import { setToken } from '@/lib/session'

// M2 made a wrong password and an unknown email deliberately indistinguishable —
// identical response shape, constant-time hashing against a dummy hash — so the API
// cannot be used to discover who has an account. This copy must never branch on the
// error `code` to say which one it was: one message, always.
const INVALID_CREDENTIALS_MESSAGE = "That email and password don't match."
const NETWORK_ERROR_MESSAGE = 'Cannot reach the server. Check your connection and try again.'
// `POST /login` is throttled per email+IP (5/min) and returns 429 `too_many_requests`.
// The limiter keys on the submitted email regardless of whether that account exists, so
// surfacing this distinctly leaks nothing about who has an account — it only stops a
// rate limit from being misread as "your password is wrong," which just drives more
// attempts and extends the lockout.
const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many attempts. Wait a minute and try again.'

export default function LoginPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      const { token } = await api.login(email, password)
      setToken(token)
      // The token alone does not make SessionProvider re-fetch — it gates its query on
      // getToken() read at render time. Only a fresh mount of Providers re-reads it, and
      // navigating into an (app) route is what mounts one. Do not remove this.
      router.replace('/me/attendance')
    } catch (err) {
      const isNetworkFailure = err instanceof ApiError && err.code === 'network_unreachable'
      const isRateLimited = err instanceof ApiError && err.status === 429

      if (isNetworkFailure) {
        setError(NETWORK_ERROR_MESSAGE)
      } else if (isRateLimited) {
        setError(TOO_MANY_ATTEMPTS_MESSAGE)
      } else {
        // Every other auth failure — wrong password, unknown email, anything else the
        // server calls it — gets this one fixed message. Never branch on `code` here.
        setError(INVALID_CREDENTIALS_MESSAGE)
      }

      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen flex-col md:flex-row">
      <section
        className="flex flex-col justify-center md:w-1/2"
        style={{
          background: 'var(--inverse-canvas)',
          padding: 'var(--sp-xl) var(--sp-xl)',
          gap: 'var(--sp-md)',
        }}
      >
        <h1 style={{ font: 'var(--t-display-md)', color: 'var(--inverse-ink)', margin: 0 }}>
          {PRODUCT_NAME}
        </h1>
        <div aria-hidden="true" style={{ width: '48px', height: '2px', background: 'var(--blue)' }} />
        <p
          style={{
            font: 'var(--t-body)',
            letterSpacing: 'var(--ls-body)',
            color: 'var(--inverse-ink-muted)',
            margin: 0,
          }}
        >
          Attendance, schedules, and leave — the hours, not the gross-to-net.
        </p>
      </section>

      <section
        className="flex flex-1 flex-col justify-center"
        style={{ background: 'var(--canvas)', padding: 'var(--sp-xl)' }}
      >
        <div
          className="w-full"
          style={{
            maxWidth: '360px',
            margin: '0 auto',
            display: 'flex',
            flexDirection: 'column',
            gap: 'var(--sp-lg)',
          }}
        >
          <h2 style={{ font: 'var(--t-headline)', color: 'var(--ink)', margin: 0 }}>Sign in</h2>

          <form
            onSubmit={(event) => {
              void handleSubmit(event)
            }}
            noValidate
            className="flex flex-col"
            style={{ gap: 'var(--sp-md)' }}
          >
            <TextInput
              id="email"
              label="Work email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={setEmail}
              required
            />
            <TextInput
              id="password"
              label="Password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={setPassword}
              required
            />

            {error ? <InlineNotification kind="error" title={error} /> : null}

            <Button type="submit" disabled={isSubmitting} loading={isSubmitting}>
              Sign in
            </Button>
          </form>
        </div>
      </section>
    </div>
  )
}
