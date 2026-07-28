'use client'

/**
 * The employee roster (M8b) — sysadmin-gated, mirroring `/admin/offices`'s scaffold:
 * an optional office filter above the list, loading/error/empty via
 * `Skeleton`/`InlineNotification`/`EmptyState`, and a "New employee" affordance that
 * routes to the create wizard rather than opening a dialog — onboarding is a multi-step
 * flow with its own accumulated state (see `/admin/employees/new`), not a single-record
 * edit.
 *
 * Each row links to `/admin/employees/[employee]`, the detail/edit screen. The login
 * badge (`has_user`) is the one thing an admin needs at a glance without opening a
 * profile: whether this person can sign in at all yet.
 */

import Link from 'next/link'
import { useState } from 'react'

import type { AdminEmployee } from '@/lib/api'
import { useAdminEmployees } from '@/hooks/useAdminEmployees'
import { useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Tag } from '@/components/Tag'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'

// No tier-1 "link that looks like a button" component exists yet, so this mirrors
// `Button`'s primary variant style directly — a `<Link>` navigates via `next/link`
// (no full-document reload, no re-fetching the session), which a `<Button onClick>`
// wrapping `router.push` cannot offer as a plain anchor-shaped affordance.
function NewEmployeeLink() {
  return (
    <Link
      href="/admin/employees/new"
      className="inline-flex items-center justify-between focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
      style={{
        background: 'var(--blue)',
        color: 'var(--on-primary)',
        borderRadius: 'var(--radius)',
        height: 'var(--sp-xxl)',
        padding: '0 var(--sp-md)',
        font: 'var(--t-body-sm)',
        letterSpacing: 'var(--ls-body)',
        textDecoration: 'none',
      }}
    >
      New employee
    </Link>
  )
}

interface EmployeeRowProps {
  employee: AdminEmployee
  officeName: string
}

function EmployeeRow({ employee, officeName }: EmployeeRowProps) {
  return (
    <tr style={{ borderTop: '1px solid var(--hairline)' }}>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)' }}>
        <Link
          href={`/admin/employees/${employee.id}`}
          className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
          style={{
            font: 'var(--t-body)',
            letterSpacing: 'var(--ls-body)',
            color: 'var(--blue)',
            textDecoration: 'none',
          }}
        >
          {employee.full_name}
        </Link>
      </td>
      <td
        style={{
          padding: 'var(--sp-sm) var(--sp-md)',
          font: 'var(--t-body-sm)',
          letterSpacing: 'var(--ls-body)',
          color: 'var(--ink-muted)',
        }}
      >
        {employee.employee_no}
      </td>
      <td
        style={{
          padding: 'var(--sp-sm) var(--sp-md)',
          font: 'var(--t-body-sm)',
          letterSpacing: 'var(--ls-body)',
          color: 'var(--ink-muted)',
        }}
      >
        {officeName}
      </td>
      <td style={{ padding: 'var(--sp-sm) var(--sp-md)' }}>
        <Tag kind={employee.has_user ? 'success' : 'neutral'}>{employee.has_user ? 'Has login' : 'No login'}</Tag>
      </td>
    </tr>
  )
}

export default function EmployeesPage() {
  const { session } = useSession()
  const isSysAdmin = session?.is_system_admin ?? false

  const [filterOfficeId, setFilterOfficeId] = useState('')

  const officesQuery = useOffices()
  const employeesQuery = useAdminEmployees(filterOfficeId === '' ? undefined : { office: filterOfficeId })

  const offices = (officesQuery.data ?? []).filter((office) => office.archived_at === null)
  const employees = employeesQuery.data ?? []

  const officeNameById = new Map(offices.map((office) => [office.id, office.name]))
  const officeOptions: SelectOption[] = offices.map((office) => ({ value: office.id, label: office.name }))

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin"
          title="Employees"
          level={1}
          actions={isSysAdmin ? <NewEmployeeLink /> : undefined}
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer employees.">
            The employee roster is a system-admin-only screen.
          </InlineNotification>
        ) : (
          <>
            <Select
              id="employee-filter-office"
              label="Office"
              value={filterOfficeId}
              onChange={setFilterOfficeId}
              options={[{ value: '', label: 'All offices' }, ...officeOptions]}
            />

            {employeesQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : employeesQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load employees.">
                Check your connection and try again.
              </InlineNotification>
            ) : employees.length === 0 ? (
              <EmptyState title="No employees to show">
                Onboard the first one with “New employee” above.
              </EmptyState>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table
                  aria-label="Employees"
                  style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    background: 'var(--surface-1)',
                    borderRadius: 'var(--radius)',
                  }}
                >
                  <thead>
                    <tr>
                      {['Name', 'Employee no.', 'Office', 'Login'].map((heading) => (
                        <th
                          key={heading}
                          scope="col"
                          style={{
                            padding: 'var(--sp-sm) var(--sp-md)',
                            textAlign: 'left',
                            font: 'var(--t-caption)',
                            letterSpacing: 'var(--ls-caption)',
                            color: 'var(--ink-subtle)',
                          }}
                        >
                          {heading}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {employees.map((employee) => (
                      <EmployeeRow
                        key={employee.id}
                        employee={employee}
                        officeName={
                          employee.current_office_id !== null
                            ? (officeNameById.get(employee.current_office_id) ?? '—')
                            : '—'
                        }
                      />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </div>
    </AppShell>
  )
}
