'use client'

/**
 * "My profile" — the employee's own personnel file, read-only (`GET /me/profile`,
 * `useMyProfile`). Same loading/error shape as the other `/me/*` screens: a skeleton while
 * pending, an inline error notification on failure, the real content once the query
 * resolves. `ProfileSections` is shared with the admin employee Profile tab (Task 14), so
 * this page only owns the query and the shell around it.
 */

import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { ProfileSections } from '@/components/domain/ProfileSections'
import { useMyProfile } from '@/hooks/useMyProfile'

export default function ProfilePage() {
  const { data, isLoading, isError } = useMyProfile()

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Me" title="Profile" level={1} />

        {isLoading ? (
          <div data-testid="profile-skeleton" className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
            <Skeleton height="12rem" />
          </div>
        ) : isError ? (
          <InlineNotification kind="error" title="Couldn't load your profile.">
            Check your connection and try again.
          </InlineNotification>
        ) : data ? (
          <ProfileSections profile={data} />
        ) : null}
      </div>
    </AppShell>
  )
}
