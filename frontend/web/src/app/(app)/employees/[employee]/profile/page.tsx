'use client'

/**
 * The HR-reachable personnel file — an M10a fix-round-2 addition. Reads the `[employee]`
 * route param and renders one of two shapes, never both, depending on what the backend
 * actually authorizes THIS viewer to see for THIS employee:
 *
 *   - the FULL read (self, an HR Admin who administers this employee's current office, or
 *     a System Admin via `Gate::before`) — `GET /admin/employees/{id}/profile`
 *     (`useEmployeeProfile`), rendered with `ProfileSections` (read) + `ProfileForm` (the
 *     write half; `EmployeePolicy::updateProfile` denies self server-side, so an HR Admin
 *     viewing their own row still sees the form but a submit against their own row 403s —
 *     same as the page this replaces).
 *   - the REDACTED read (a manager of this employee, inside `EmployeeScope` but not the
 *     administering HR Admin) — `GET /employees/{id}/profile` (`useRedactedProfile`),
 *     rendered with `ProfileSummarySections`.
 *
 * DISCRIMINATOR: try the full read, fall back to the redacted read on a 404. Chosen over
 * asking the session for the viewer's role because authorization here is genuinely
 * per-EMPLOYEE, not per-viewer — an HR Admin of Cebu who also manages a Manila report gets
 * the redacted view of THAT report specifically (EmployeePolicy, spec decision 7). A
 * role-based frontend discriminator would have to reimplement the hr_admin_offices pivot
 * check the backend already owns; the backend's own 404-vs-200 on the full route already
 * IS that discriminator; a manager's fallback fetch is a single extra request, never a
 * spurious write attempt.
 *
 * This route replaces the Profile section that used to live on
 * `/admin/employees/{employee}`: that page gated the WHOLE screen on `is_system_admin` at
 * the frontend even though `viewFullProfile` already admitted HR Admins server-side, so
 * the entire M10a authorization model was unreachable in a browser. The heading comes from
 * whichever profile response loads (`full_name` is on both shapes) — never from
 * `GET /admin/employees/{id}`, which stays System-Admin-only.
 */

import { useParams } from 'next/navigation'

import { ApiError } from '@/lib/api'
import { useEmployeeProfile } from '@/hooks/useEmployeeProfile'
import { useProfileCatalog } from '@/hooks/useProfileCatalog'
import { useRedactedProfile } from '@/hooks/useRedactedProfile'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { ProfileForm } from '@/components/domain/ProfileForm'
import { ProfileSections, ProfileSummarySections } from '@/components/domain/ProfileSections'

function isNotFound(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404
}

export default function EmployeeProfilePage() {
  const params = useParams<{ employee: string }>()
  const id = typeof params.employee === 'string' ? params.employee : null

  const fullQuery = useEmployeeProfile(id ?? '')

  // Only reach for the redacted route once the full one has definitively said "not this
  // viewer, not this way" — never while it's still loading, and never for a non-404
  // failure (a 500 on the full read is not evidence the redacted one would behave any
  // differently).
  const tryRedacted = fullQuery.isError && isNotFound(fullQuery.error)
  const redactedQuery = useRedactedProfile(id ?? '', tryRedacted)
  const catalogQuery = useProfileCatalog()

  const isLoading =
    fullQuery.isLoading || (tryRedacted && redactedQuery.isLoading) || (fullQuery.isSuccess && catalogQuery.isLoading)

  const isNotFoundEverywhere = tryRedacted && redactedQuery.isError && isNotFound(redactedQuery.error)

  const isOtherError =
    (fullQuery.isError && !isNotFound(fullQuery.error)) ||
    (redactedQuery.isError && !isNotFound(redactedQuery.error)) ||
    (fullQuery.isSuccess && catalogQuery.isError)

  const heading = fullQuery.data?.full_name ?? redactedQuery.data?.full_name ?? 'Employee'

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Employees" title={heading} level={1} />

        {id === null ? (
          <InlineNotification kind="error" title="No employee to show.">
            This screen needs an employee id in the URL.
          </InlineNotification>
        ) : isLoading ? (
          <Skeleton height="16rem" />
        ) : isNotFoundEverywhere ? (
          <EmptyState title="No personnel file to show.">
            Either this employee has no profile on record, or your account can&rsquo;t reach it.
          </EmptyState>
        ) : isOtherError ? (
          <InlineNotification kind="error" title="Couldn't load this employee's personnel file.">
            Check your connection and try again.
          </InlineNotification>
        ) : fullQuery.isSuccess && fullQuery.data !== undefined && catalogQuery.data !== undefined ? (
          <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
            <ProfileSections profile={fullQuery.data} />
            <ProfileForm
              profile={fullQuery.data}
              relationships={catalogQuery.data.relationships}
              categories={catalogQuery.data.identification_categories}
            />
          </div>
        ) : redactedQuery.isSuccess && redactedQuery.data !== undefined ? (
          <ProfileSummarySections summary={redactedQuery.data} />
        ) : null}
      </div>
    </AppShell>
  )
}
