'use client'

/**
 * Root of the client-side app tree: one QueryClient, one SessionProvider, per mount —
 * never module-scope, or server-rendered requests and different browser tabs would
 * share cached data that belongs to one session only.
 */

import { useState } from 'react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { SessionProvider } from './SessionProvider'

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // A 401/403/422 is a decision, not a hiccup — retrying it only delays the
            // redirect to /login for no benefit.
            retry: false,
          },
        },
      }),
  )

  return (
    <QueryClientProvider client={queryClient}>
      <SessionProvider>{children}</SessionProvider>
    </QueryClientProvider>
  )
}
