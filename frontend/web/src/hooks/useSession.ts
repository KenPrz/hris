/**
 * The one and only source of role/scope truth in the app. Reads the session the
 * SessionProvider already fetched — never issues its own request. A consumer mounted
 * outside the provider is a bug (it would silently render as logged-out otherwise), so
 * this throws instead.
 */

import { useContext } from 'react'

import type { Session } from '@/lib/api'
import { SessionContext } from '@/components/SessionProvider'

export type UseSessionResult = {
  session: Session | null
  isLoading: boolean
  isAuthenticated: boolean
}

export function useSession(): UseSessionResult {
  const context = useContext(SessionContext)

  if (context === null) {
    throw new Error('useSession() must be used within a <SessionProvider>.')
  }

  return {
    session: context.session,
    isLoading: context.isLoading,
    isAuthenticated: context.session !== null,
  }
}
