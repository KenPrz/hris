import type { ReactNode } from 'react'

/**
 * The public-auth route group. Deliberately bare — no `Providers`, no `AppGuard`. The
 * `(app)` layout redirects an unauthenticated visitor to `/login`; wrapping `/login`
 * itself in that guard would loop it straight back to itself.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
  return <>{children}</>
}
