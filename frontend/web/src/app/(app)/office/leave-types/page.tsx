'use client'

/**
 * An HR admin's view of one office's leave-type config (M6b-a Task 4's config surface,
 * finally getting a screen) — mirrors `/office/holidays`'s scaffold exactly: an office
 * picker sourced from `session.hr_offices` (never `current_office_id` alone — a single
 * office skips the picker; only length > 1 shows one), loading/error/empty via
 * `Skeleton`/`InlineNotification`/`EmptyState`, and a `Dialog`-driven create/edit form built
 * on the same mutation + invalidation shape as `useCreateHoliday`/`useUpdateHoliday`.
 *
 * Two more surfaces live on this same page rather than a screen apiece, since both are
 * small and both belong to the office an HR admin is already looking at:
 *
 * - **Leave day**: `minutes_per_leave_day` (`useSetLeaveDay`) — the divisor
 *   `LeaveUnit::toMinutes` uses to turn a `'day'`/`'half_shift'` grant amount into minutes.
 *   Write-and-echo-back only, like `/office/schedules`'s office-default template setter —
 *   there is no GET for the current value, so nothing here shows what it's currently set
 *   to until this session sets it.
 * - **Grant leave**: HR manually crediting one employee's balance (`useGrantLeave`), scoped
 *   to this office's employees and leave types. `GrantForm` owns its own field state and is
 *   remounted (via `key`) after a successful grant, the same reset-via-remount idiom
 *   `HolidayForm`/`NewVersionForm` use — a fresh form for the next grant, never a stale
 *   draft of the last one.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { LeaveGrantInput, LeaveType, LeaveTypeInput, LeaveUnitName } from '@/lib/api'
import { useEmployees } from '@/hooks/useEmployees'
import { useGrantLeave } from '@/hooks/useGrantLeave'
import { useLeaveTypes } from '@/hooks/useLeaveTypes'
import { useSaveLeaveType } from '@/hooks/useSaveLeaveType'
import { useSession } from '@/hooks/useSession'
import { useSetLeaveDay } from '@/hooks/useSetLeaveDay'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'

const UNIT_OPTIONS: SelectOption[] = [
  { value: 'day', label: 'Day' },
  { value: 'half_shift', label: 'Half shift' },
  { value: 'hour', label: 'Hour' },
  { value: 'minute', label: 'Minute' },
]

const DEFAULT_LEAVE_TYPE_INPUT: LeaveTypeInput = {
  name: '',
  code: null,
  is_paid: true,
  requires_attachment: false,
  deducts_balance: true,
  is_cash_convertible: false,
  max_carryover_minutes: null,
  is_active: true,
}

function toLeaveTypeInput(leaveType: LeaveType): LeaveTypeInput {
  return {
    name: leaveType.name,
    code: leaveType.code,
    is_paid: leaveType.is_paid,
    requires_attachment: leaveType.requires_attachment,
    deducts_balance: leaveType.deducts_balance,
    is_cash_convertible: leaveType.is_cash_convertible,
    max_carryover_minutes: leaveType.max_carryover_minutes,
    is_active: leaveType.is_active,
  }
}

type DialogState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; leaveType: LeaveType }

interface CheckboxFieldProps {
  id: string
  label: string
  checked: boolean
  onChange: (checked: boolean) => void
}

/** A token-styled checkbox — there is no tier-1 checkbox component yet; this mirrors
 * `DayShapeFields`'s own raw `<input type="checkbox">` treatment exactly rather than
 * inventing a second one. */
function CheckboxField({ id, label, checked, onChange }: CheckboxFieldProps) {
  return (
    <label
      htmlFor={id}
      className="flex items-center"
      style={{ gap: 'var(--sp-xxs)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
    >
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        style={{ accentColor: 'var(--blue)' }}
      />
      {label}
    </label>
  )
}

interface LeaveTypeFormProps {
  initial: LeaveTypeInput
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: LeaveTypeInput) => void
}

/** Owns its own field state, mounted fresh each time the dialog opens on a different
 * target (a `key` on the caller) — same idiom as `HolidayForm`. Unlike `HolidayForm`, the
 * submit button reads "Save" either way — there's no "Add leave type"/"Save changes" split
 * worth making, so this doesn't need an add/edit `mode` prop at all. */
function LeaveTypeForm({ initial, submitting, submitError, onCancel, onSubmit }: LeaveTypeFormProps) {
  const [name, setName] = useState(initial.name)
  const [code, setCode] = useState(initial.code ?? '')
  const [isPaid, setIsPaid] = useState(initial.is_paid)
  const [requiresAttachment, setRequiresAttachment] = useState(initial.requires_attachment)
  const [deductsBalance, setDeductsBalance] = useState(initial.deducts_balance)
  const [isCashConvertible, setIsCashConvertible] = useState(initial.is_cash_convertible)
  const [isActive, setIsActive] = useState(initial.is_active)
  const [maxCarryover, setMaxCarryover] = useState(
    initial.max_carryover_minutes !== null ? String(initial.max_carryover_minutes) : '',
  )

  // Same guard as `leave-day-minutes` and `grant-amount`: a non-numeric entry must block
  // submit, not silently fall through `Number('abc')` → `NaN` → serialized as `null` (which
  // would read as "no carryover cap" — a materially different, wrong value).
  const maxCarryoverValue = maxCarryover.trim() === '' ? null : Number(maxCarryover)
  const hasInvalidCarryover = maxCarryoverValue !== null && Number.isNaN(maxCarryoverValue)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (hasInvalidCarryover) return

    onSubmit({
      name,
      code: code.trim() === '' ? null : code,
      is_paid: isPaid,
      requires_attachment: requiresAttachment,
      deducts_balance: deductsBalance,
      is_cash_convertible: isCashConvertible,
      max_carryover_minutes: maxCarryoverValue,
      is_active: isActive,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="leave-type-name" label="Name" value={name} onChange={setName} required />
      <TextInput id="leave-type-code" label="Code" value={code} onChange={setCode} />

      <div className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
        <CheckboxField id="leave-type-is-paid" label="Paid" checked={isPaid} onChange={setIsPaid} />
        <CheckboxField
          id="leave-type-requires-attachment"
          label="Requires attachment"
          checked={requiresAttachment}
          onChange={setRequiresAttachment}
        />
        <CheckboxField
          id="leave-type-deducts-balance"
          label="Deducts balance"
          checked={deductsBalance}
          onChange={setDeductsBalance}
        />
        <CheckboxField
          id="leave-type-is-cash-convertible"
          label="Cash-convertible"
          checked={isCashConvertible}
          onChange={setIsCashConvertible}
        />
        <CheckboxField id="leave-type-is-active" label="Active" checked={isActive} onChange={setIsActive} />
      </div>

      <TextInput
        id="leave-type-max-carryover"
        label="Max carryover (minutes)"
        value={maxCarryover}
        onChange={setMaxCarryover}
        error={hasInvalidCarryover ? 'Enter a whole number of minutes, or leave it blank.' : undefined}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button
          type="submit"
          loading={submitting}
          disabled={submitting || name.trim().length === 0 || hasInvalidCarryover}
        >
          Save
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface GrantFormProps {
  employeeOptions: SelectOption[]
  leaveTypeOptions: SelectOption[]
  submitting: boolean
  onSubmit: (input: LeaveGrantInput) => void
}

/** HR crediting one employee's balance. Fully self-contained field state, remounted (via a
 * `key` on the caller) after a successful grant so the next one starts from a clean slate
 * rather than echoing the last submission. */
function GrantForm({ employeeOptions, leaveTypeOptions, submitting, onSubmit }: GrantFormProps) {
  const [employeeId, setEmployeeId] = useState(employeeOptions[0]?.value ?? '')
  const [leaveTypeId, setLeaveTypeId] = useState(leaveTypeOptions[0]?.value ?? '')
  const [amount, setAmount] = useState('')
  const [unit, setUnit] = useState<LeaveUnitName>('day')
  const [reason, setReason] = useState('')

  const amountValue = Number(amount)
  const hasInvalidInput =
    employeeId === '' ||
    leaveTypeId === '' ||
    amount.trim() === '' ||
    Number.isNaN(amountValue) ||
    amountValue <= 0 ||
    reason.trim() === ''

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (hasInvalidInput) return
    onSubmit({ employee_id: employeeId, leave_type_id: leaveTypeId, amount: amountValue, unit, reason })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select
        id="grant-employee"
        label="Employee"
        value={employeeId}
        onChange={setEmployeeId}
        options={employeeOptions}
      />
      <Select
        id="grant-leave-type"
        label="Leave type"
        value={leaveTypeId}
        onChange={setLeaveTypeId}
        options={leaveTypeOptions}
      />
      <TextInput id="grant-amount" label="Amount" value={amount} onChange={setAmount} />
      <Select
        id="grant-unit"
        label="Unit"
        value={unit}
        onChange={(value) => setUnit(value as LeaveUnitName)}
        options={UNIT_OPTIONS}
      />
      <TextInput id="grant-reason" label="Reason" value={reason} onChange={setReason} />

      <div>
        <Button type="submit" loading={submitting} disabled={submitting || hasInvalidInput}>
          Grant leave
        </Button>
      </div>
    </form>
  )
}

interface LeaveTypeRowProps {
  leaveType: LeaveType
  onEdit: () => void
}

function LeaveTypeRow({ leaveType, onEdit }: LeaveTypeRowProps) {
  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {leaveType.name}
        </span>
        <Button variant="ghost" onClick={onEdit}>
          Edit
        </Button>
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-xxs)' }}>
        <Tag kind={leaveType.is_paid ? 'success' : 'neutral'}>{leaveType.is_paid ? 'Paid' : 'Unpaid'}</Tag>
        {leaveType.deducts_balance ? <Tag kind="neutral">Deducts balance</Tag> : null}
        {leaveType.requires_attachment ? <Tag kind="warning">Requires attachment</Tag> : null}
        {leaveType.is_cash_convertible ? <Tag kind="neutral">Cash-convertible</Tag> : null}
        {!leaveType.is_active ? <Tag kind="error">Inactive</Tag> : null}
      </div>
    </li>
  )
}

export default function LeaveTypesPage() {
  const { session } = useSession()

  const hrOffices = session?.hr_offices ?? []
  // hr_offices is the authority for "offices you administer"; current_office_id only
  // covers the degenerate single-office case where an admin has none listed there yet —
  // same fallback /office/holidays uses.
  const defaultOfficeId = hrOffices[0] ?? session?.employee?.current_office_id ?? null

  const [chosenOfficeId, setChosenOfficeId] = useState<string | null>(null)
  const officeId = chosenOfficeId ?? defaultOfficeId

  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })
  const [leaveDayMinutes, setLeaveDayMinutes] = useState('')
  const [grantFormKey, setGrantFormKey] = useState(0)
  const [grantSucceeded, setGrantSucceeded] = useState(false)

  const leaveTypesQuery = useLeaveTypes(officeId)
  const saveMutation = useSaveLeaveType(officeId)
  const setLeaveDayMutation = useSetLeaveDay()
  const employeesQuery = useEmployees()
  const grantMutation = useGrantLeave()

  const leaveTypes = leaveTypesQuery.data ?? []
  const employees = employeesQuery.data ?? []
  // There is no office-scoped employee list endpoint (see useEmployees's own doc comment)
  // — filter the shared list to this office client-side, same as /office/schedules does
  // for its assignment target picker.
  const officeEmployees = employees.filter((employee) => employee.current_office_id === officeId)

  function closeDialog(): void {
    setDialogState({ mode: 'closed' })
  }

  function handleLeaveTypeSubmit(input: LeaveTypeInput): void {
    if (officeId === null) return

    if (dialogState.mode === 'add') {
      saveMutation.mutate({ body: { ...input, office_id: officeId } }, { onSuccess: closeDialog })
      return
    }

    if (dialogState.mode === 'edit') {
      saveMutation.mutate({ id: dialogState.leaveType.id, body: input }, { onSuccess: closeDialog })
    }
  }

  function handleSetLeaveDay(event: FormEvent): void {
    event.preventDefault()
    if (officeId === null) return

    const minutes = Number(leaveDayMinutes)
    if (leaveDayMinutes.trim() === '' || Number.isNaN(minutes)) return

    setLeaveDayMutation.mutate({ office_id: officeId, minutes_per_leave_day: minutes })
  }

  function handleGrantSubmit(input: LeaveGrantInput): void {
    setGrantSucceeded(false)
    grantMutation.mutate(input, {
      onSuccess: () => {
        setGrantSucceeded(true)
        // Bumps GrantForm's `key`, remounting it with fresh field state — see the
        // component's own doc comment for why that's the reset mechanism.
        setGrantFormKey((key) => key + 1)
      },
    })
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Office"
          title="Leave types"
          level={1}
          actions={
            officeId !== null ? (
              <Button onClick={() => setDialogState({ mode: 'add' })}>New leave type</Button>
            ) : undefined
          }
        />

        {hrOffices.length > 1 ? (
          <Select
            id="leave-type-office"
            label="Office"
            value={officeId ?? ''}
            onChange={setChosenOfficeId}
            options={hrOffices.map((id) => ({ value: id, label: id }))}
          />
        ) : null}

        {officeId === null ? (
          <InlineNotification kind="info" title="No office to show leave types for.">
            This account doesn&rsquo;t administer any office&rsquo;s leave types.
          </InlineNotification>
        ) : (
          <>
            <section
              style={{ background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-lg)' }}
              className="flex flex-col"
            >
              <SectionHeader title="Leave day" />
              <form
                onSubmit={handleSetLeaveDay}
                className="flex items-end flex-wrap"
                style={{ gap: 'var(--sp-sm)', marginTop: 'var(--sp-md)' }}
              >
                <TextInput
                  id="leave-day-minutes"
                  label="Leave day (minutes)"
                  value={leaveDayMinutes}
                  onChange={setLeaveDayMinutes}
                />
                <Button type="submit" loading={setLeaveDayMutation.isPending} disabled={setLeaveDayMutation.isPending}>
                  Save leave day
                </Button>
              </form>

              {setLeaveDayMutation.isError ? (
                <InlineNotification kind="error" title="That didn't save.">
                  Check your connection and try again.
                </InlineNotification>
              ) : null}

              {setLeaveDayMutation.isSuccess ? (
                <InlineNotification kind="success" title="Leave day updated." />
              ) : null}
            </section>

            {leaveTypesQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : leaveTypesQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load leave types.">
                Check your connection and try again.
              </InlineNotification>
            ) : leaveTypes.length === 0 ? (
              <EmptyState title="No leave types yet">
                Create the first one with &ldquo;New leave type&rdquo; above.
              </EmptyState>
            ) : (
              <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                {leaveTypes.map((leaveType) => (
                  <LeaveTypeRow
                    key={leaveType.id}
                    leaveType={leaveType}
                    onEdit={() => setDialogState({ mode: 'edit', leaveType })}
                  />
                ))}
              </ul>
            )}

            <section
              style={{ background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-lg)' }}
              className="flex flex-col"
            >
              <SectionHeader title="Grant leave" />

              <div style={{ marginTop: 'var(--sp-md)' }} className="flex flex-col" >
                {grantMutation.isError ? (
                  <InlineNotification kind="error" title="That grant didn't go through.">
                    Check your connection and try again.
                  </InlineNotification>
                ) : null}

                {grantSucceeded ? <InlineNotification kind="success" title="Leave granted." /> : null}

                <GrantForm
                  // Keyed on officeId too, not just grantFormKey — /employees and this
                  // office's leave types are looked up by id, and a stale id from the
                  // PREVIOUS office is still a *valid* id (just for the wrong office), so
                  // hasInvalidInput can't catch it. Remounting on officeId change is what
                  // guarantees the fresh employee/leave-type selection actually belongs to
                  // the office now showing, instead of echoing the switch-away office's ids.
                  key={`${officeId}-${grantFormKey}`}
                  employeeOptions={officeEmployees.map((employee) => ({ value: employee.id, label: employee.employee_no }))}
                  leaveTypeOptions={leaveTypes.map((leaveType) => ({ value: leaveType.id, label: leaveType.name }))}
                  submitting={grantMutation.isPending}
                  onSubmit={handleGrantSubmit}
                />
              </div>
            </section>
          </>
        )}

        <Dialog
          open={dialogState.mode !== 'closed'}
          onClose={closeDialog}
          title={dialogState.mode === 'edit' ? 'Edit leave type' : 'New leave type'}
        >
          {dialogState.mode === 'closed' ? null : (
            <LeaveTypeForm
              key={dialogState.mode === 'add' ? 'add' : dialogState.leaveType.id}
              initial={dialogState.mode === 'edit' ? toLeaveTypeInput(dialogState.leaveType) : DEFAULT_LEAVE_TYPE_INPUT}
              submitting={saveMutation.isPending}
              submitError={saveMutation.isError}
              onCancel={closeDialog}
              onSubmit={handleLeaveTypeSubmit}
            />
          )}
        </Dialog>
      </div>
    </AppShell>
  )
}
