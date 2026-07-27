'use client'

/**
 * "My leave" — every leave type the caller's office has configured, with the caller's own
 * balance in it (`GET /me/leave`, `useMyLeave`). One row per type, `LeaveDuration` rendering
 * the backend's `balance_readable` decomposition directly (never recomputed from
 * `balance_minutes` — see that component's own doc comment), plus the type's paid/
 * cash-convertible flags so an employee can tell at a glance which kind of leave this is
 * before filing anything against it.
 *
 * "Request leave" opens `LeaveRequestForm` inline below the header — same toggle-a-form,
 * show-a-success-notice-on-`onDone` idiom `/me/attendance` uses for `CorrectionForm`
 * (`formOpen`/`submitted` state, reset together, never a stale draft or a stale success
 * notice left over). Hidden when the account has no linked employee record (no
 * `current_office_id` to scope the leave-type Select to) — there is nothing to file
 * against in that case.
 */

import { useState } from 'react'

import type { LeaveBalance } from '@/lib/api'
import { useMyLeave } from '@/hooks/useMyLeave'
import { useSession } from '@/hooks/useSession'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { LeaveDuration } from '@/components/domain/LeaveDuration'
import { LeaveRequestForm } from '@/components/domain/LeaveRequestForm'

interface BalanceRowProps {
  balance: LeaveBalance
}

function BalanceRow({ balance }: BalanceRowProps) {
  const { leave_type: leaveType } = balance

  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {leaveType.name}
        </span>
        <LeaveDuration readable={balance.balance_readable} />
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-xxs)' }}>
        <Tag kind={leaveType.is_paid ? 'success' : 'neutral'}>{leaveType.is_paid ? 'Paid' : 'Unpaid'}</Tag>
        {leaveType.is_cash_convertible ? <Tag kind="neutral">Cash-convertible</Tag> : null}
      </div>
    </li>
  )
}

export default function MyLeavePage() {
  const { session } = useSession()
  const myLeaveQuery = useMyLeave()

  const [formOpen, setFormOpen] = useState(false)
  const [submitted, setSubmitted] = useState(false)

  const balances = myLeaveQuery.data ?? []
  const officeId = session?.employee?.current_office_id ?? null

  function openForm(): void {
    setFormOpen(true)
    setSubmitted(false)
  }

  function closeAndToast(): void {
    setFormOpen(false)
    setSubmitted(true)
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Me"
          title="Leave"
          level={1}
          actions={
            officeId !== null && !formOpen ? (
              <Button variant="secondary" onClick={openForm}>
                Request leave
              </Button>
            ) : undefined
          }
        />

        {submitted ? (
          <InlineNotification kind="success" title="Leave request submitted.">
            It&rsquo;s pending approval — check &ldquo;My requests&rdquo; for its status.
          </InlineNotification>
        ) : null}

        {formOpen && officeId !== null ? (
          <div
            className="flex flex-col"
            style={{ gap: 'var(--sp-sm)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-lg)' }}
          >
            <LeaveRequestForm officeId={officeId} onDone={closeAndToast} />
            <div>
              <Button variant="ghost" onClick={() => setFormOpen(false)}>
                Cancel
              </Button>
            </div>
          </div>
        ) : null}

        {myLeaveQuery.isLoading ? (
          <Skeleton height="12rem" />
        ) : myLeaveQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load your leave balances.">
            Check your connection and try again.
          </InlineNotification>
        ) : balances.length === 0 ? (
          <EmptyState title="No leave balances to show">
            Nothing is configured for your office yet.
          </EmptyState>
        ) : (
          <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
            {balances.map((balance) => (
              <BalanceRow key={balance.leave_type.id} balance={balance} />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  )
}
