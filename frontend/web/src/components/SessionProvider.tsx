'use client'

/**
 * Runs the ONE `GET /me` the whole app shares. Every `useSession()` consumer reads this
 * context instead of issuing its own query — that is what turns "several components
 * mount at once" into one request rather than N.
 */

import { createContext, useEffect } from 'react'
import type { ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'

import type { Session } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'
import { getToken, onLogout } from '@/lib/session'

export type SessionContextValue = {
  session: Session | null
  isLoading: boolean
}

export const SessionContext = createContext<SessionContextValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: keys.session(),
    queryFn: api.me,
    // No token means there is nothing to ask the server about — an anonymous /me would
    // just 401 and immediately clear a token that was never there.
    enabled: getToken() !== null,
  })

  useEffect(() => {
    // A 401 anywhere in the app clears the token and fires this. The session must not
    // linger in the cache after that — every consumer has to see "logged out" at once.
    return onLogout(() => {
      queryClient.setQueryData(keys.session(), null)
    })
  }, [queryClient])

  const value: SessionContextValue = {
    session: data ?? null,
    isLoading,
  }

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}
