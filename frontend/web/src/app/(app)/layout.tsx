'use client'

/**
 * The authenticated app shell. This is a UX convenience, not a security boundary — the
 * server already refused any request that mattered; redirecting here just spares the
 * user a screen full of components that would 401 anyway.
 */

import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { useRouter } from 'next/navigation'

import { Providers } from '@/components/Providers'
import { Skeleton } from '@/components/ui/Skeleton'
import { useSession } from '@/hooks/useSession'

function AppShellSkeleton() {
  return (
    <div className="p-6 space-y-4">
      <Skeleton height="2rem" width="12rem" />
      <Skeleton height="1rem" />
      <Skeleton height="1rem" />
    </div>
  )
}

function AppGuard({ children }: { children: ReactNode }) {
  const router = useRouter()
  const { isLoading, isAuthenticated } = useSession()

  useEffect(() => {
    // No token, or a resolved-but-failed /me — either way there is no session to show.
    if (!isLoading && !isAuthenticated) {
      router.replace('/login')
    }
  }, [isLoading, isAuthenticated, router])

  if (isLoading || !isAuthenticated) {
    return <AppShellSkeleton />
  }

  return <>{children}</>
}

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <Providers>
      <AppGuard>{children}</AppGuard>
    </Providers>
  )
}
