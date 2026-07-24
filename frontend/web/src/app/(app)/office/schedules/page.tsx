'use client'

/**
 * An HR admin's view of one office's shift templates — the first slice of
 * `/office/schedules` (M4b). Mirrors `/office/holidays`'s scaffold: an office picker from
 * `session.hr_offices` (never `current_office_id` alone — see that screen's comment),
 * loading/error/empty via Skeleton/InlineNotification/EmptyState, and a Dialog-driven
 * create/edit flow built on the same mutation + invalidation shape.
 *
 * Extended in M4b's assignment task with an ASSIGNMENTS region: which employee runs which
 * template from which date (`useScheduleAssignments` + its mutations, Task 10). The target
 * picker is EMPLOYEE ONLY for now — `GET /employees` (`EmployeeScope::visibleTo`) exists
 * and is filtered here to the viewed office, but there is no `GET /office/departments`
 * list endpoint yet, so a department-target toggle has nowhere to source options from.
 * The backend fully supports department-target assignments (`department_id` on
 * `ScheduleAssignmentCreateInput`); the UI seam for a target-type toggle is left as a
 * comment on `AssignmentForm` below, to add once that endpoint exists.
 *
 * Extended again in M4b's Task 14 with a RESOLVED CALENDAR region: pick an employee, see
 * `ScheduleResolver`'s output for a month via `useResolvedMonth` (rest days, working
 * hours, and which precedence tier produced them — `ResolvedDayCell`), and click a day to
 * open a single-day override editor. That editor reuses `DayShapeFields` — the
 * is_rest/hours/break/crosses-midnight fields extracted out of `WeekEditor`'s per-row
 * internals so the weekly template editor and the single-day override editor share one
 * implementation of the crosses-midnight math instead of two. Submitting calls
 * `useCreateScheduleOverride` (no existing override for that date) or
 * `useUpdateScheduleOverride` (one already exists) — both invalidate the overrides AND
 * resolved query keys (see `useScheduleOverrides.ts`), so the calendar reflects the edit
 * without a manual refetch. An employee whose office has no default template can't be
 * resolved (`office_has_no_default_template`, 422) — surfaced via InlineNotification, not
 * a crash.
 *
 * The office-default Select has one real gap worth naming: the API has no GET for an
 * office's current `default_shift_template_id` (`PATCH /office/default-template` is
 * write-and-echo-back only — see `SetDefaultTemplateController`). So `officeDefaultId`
 * below is tracked in local state, starting `null` ("not known") and only ever set by a
 * successful `set` call made *this session*. A page reload — or an office it never
 * changed on — legitimately shows no default highlighted even though one exists
 * server-side. Fixing that for real needs the session or office read to start carrying
 * the field; until then this is the honest thing the frontend can do with today's API.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { usePathname, useRouter, useSearchParams } from 'next/navigation'

import type { DayShape } from '@/components/domain/DayShapeFields'
import { DayShapeFields } from '@/components/domain/DayShapeFields'
import { MonthCalendar } from '@/components/domain/MonthCalendar'
import { ResolvedDayCell } from '@/components/domain/ResolvedDayCell'
import { WeekEditor } from '@/components/domain/WeekEditor'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'
import type { ResolvedDay, ScheduleAssignment, ShiftDay, ShiftTemplate, Weekday } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { addMonths, currentMonth, monthLabel } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import {
  useCreateScheduleAssignment,
  useDeleteScheduleAssignment,
  useScheduleAssignments,
} from '@/hooks/useScheduleAssignments'
import {
  useCreateScheduleOverride,
  useScheduleOverrides,
  useUpdateScheduleOverride,
} from '@/hooks/useScheduleOverrides'
import { useResolvedMonth } from '@/hooks/useResolvedMonth'
import {
  useCreateShiftTemplate,
  useDeleteShiftTemplate,
  useSetOfficeDefaultTemplate,
  useShiftTemplates,
  useUpdateShiftTemplate,
} from '@/hooks/useShiftTemplates'
import { useEmployees } from '@/hooks/useEmployees'
import { useSession } from '@/hooks/useSession'

// Month 01–12 only, same guard as /office/holidays and /me/attendance — an impossible
// `?month=` falls back to the current month instead of rendering "undefined 2026" over an
// empty grid.
const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/

function parseViewedMonth(raw: string | null): string {
  return raw !== null && MONTH_PATTERN.test(raw) ? raw : currentMonth(OFFICE_TIME_ZONE)
}

// Mon..Fri working 08:00-18:00 with an hour's break, Sat/Sun rest — a plain, unsurprising
// starting point every admin edits from, matching WeekEditor's own 0=Mon..6=Sun convention.
const DEFAULT_DAYS: ShiftDay[] = [0, 1, 2, 3, 4, 5, 6].map((weekday) => {
  const isWeekend = weekday === 5 || weekday === 6
  return {
    weekday: weekday as Weekday,
    is_rest: isWeekend,
    start_minute: isWeekend ? null : 480,
    end_minute: isWeekend ? null : 1080,
    break_minutes: isWeekend ? null : 60,
  }
})

function templateSummary(days: ShiftDay[]): string {
  const working = days.filter((day) => !day.is_rest).length
  const rest = days.length - working
  const workingLabel = `${working} working day${working === 1 ? '' : 's'}`
  const restLabel = `${rest} rest day${rest === 1 ? '' : 's'}`
  return `${workingLabel}, ${restLabel}`
}

type DialogState =
  | { mode: 'closed' }
  | { mode: 'add' }
  | { mode: 'edit'; template: ShiftTemplate }

interface TemplateFormProps {
  mode: 'add' | 'edit'
  initialName: string
  initialDays: ShiftDay[]
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: { name: string; days: ShiftDay[] }) => void
}

/** Owns its own `name`/`days` state — mounted fresh (via a `key` on the caller) every
 * time the dialog opens on a different template, so switching targets never leaks the
 * previous form's draft into the new one. Same shape as `/office/holidays`'s
 * `HolidayForm`. */
function TemplateForm({
  mode,
  initialName,
  initialDays,
  submitting,
  submitError,
  onCancel,
  onSubmit,
}: TemplateFormProps) {
  const [name, setName] = useState(initialName)
  const [days, setDays] = useState<ShiftDay[]>(initialDays)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ name, days })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="template-name" label="Name" value={name} onChange={setName} required />

      <WeekEditor value={days} onChange={setDays} />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || name.trim().length === 0}>
          {mode === 'add' ? 'Add template' : 'Save changes'}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface AssignmentFormProps {
  employeeOptions: SelectOption[]
  templateOptions: SelectOption[]
  submitting: boolean
  submitError: string | null
  onCancel: () => void
  onSubmit: (input: { employeeId: string; templateId: string; effectiveFrom: string }) => void
}

/** Employee-target only for M4b — see the file-level comment. A department-target toggle
 * (radio/segmented control switching between an employee Select and a department Select)
 * would go here, right above the employee Select, once `GET /office/departments` exists
 * to source its options from; today there is nothing to populate it with. */
function AssignmentForm({
  employeeOptions,
  templateOptions,
  submitting,
  submitError,
  onCancel,
  onSubmit,
}: AssignmentFormProps) {
  const [employeeId, setEmployeeId] = useState(employeeOptions[0]?.value ?? '')
  const [templateId, setTemplateId] = useState(templateOptions[0]?.value ?? '')
  const [effectiveFrom, setEffectiveFrom] = useState('')

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ employeeId, templateId, effectiveFrom })
  }

  const canSubmit = employeeId.length > 0 && templateId.length > 0 && effectiveFrom.length > 0

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select
        id="assignment-employee"
        label="Employee"
        value={employeeId}
        onChange={setEmployeeId}
        options={employeeOptions}
      />

      <Select
        id="assignment-template"
        label="Shift template"
        value={templateId}
        onChange={setTemplateId}
        options={templateOptions}
      />

      <TextInput
        id="assignment-effective-from"
        label="Effective from"
        type="date"
        value={effectiveFrom}
        onChange={setEffectiveFrom}
        required
      />

      {submitError !== null ? (
        <InlineNotification kind="error" title="That didn't save.">
          {submitError}
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || !canSubmit}>
          Create assignment
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

// What a newly-opened override dialog starts from when the clicked date has no resolved
// shape to seed from (should not happen in practice — every date in the loaded month
// resolves to something — but a plain working day is the unsurprising fallback).
const DEFAULT_OVERRIDE_SHAPE: DayShape = {
  is_rest: false,
  start_minute: 480,
  end_minute: 1080,
  break_minutes: 60,
}

function shapeFromResolved(resolved: ResolvedDay | undefined): DayShape {
  if (resolved === undefined) return DEFAULT_OVERRIDE_SHAPE
  return {
    is_rest: resolved.is_rest,
    start_minute: resolved.start_minute,
    end_minute: resolved.end_minute,
    break_minutes: resolved.break_minutes,
  }
}

type OverrideDialogState = { mode: 'closed' } | { mode: 'open'; date: string }

interface OverrideFormProps {
  date: string
  initialShape: DayShape
  submitting: boolean
  submitError: string | null
  onCancel: () => void
  onSubmit: (shape: DayShape) => void
}

/** Owns its own day-shape state — mounted fresh (via a `key` on the caller) every time the
 * dialog opens on a different date, so switching days never leaks the previous draft into
 * the new one. Reuses `DayShapeFields` — the same rest/hours/break/crosses-midnight fields
 * `WeekEditor` edits per weekday, here editing a single override date instead of a whole
 * week. */
function OverrideForm({ date, initialShape, submitting, submitError, onCancel, onSubmit }: OverrideFormProps) {
  const [shape, setShape] = useState<DayShape>(initialShape)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit(shape)
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Date
        </span>
        {/* Fixed by the day clicked — never a field the admin types into, same rule as
            /office/holidays's HolidayForm. */}
        <span style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>{date}</span>
      </div>

      <div className="flex items-center flex-wrap" style={{ gap: 'var(--sp-md)' }}>
        <DayShapeFields label="Override" value={shape} onChange={setShape} />
      </div>

      {submitError !== null ? (
        <InlineNotification kind="error" title="That didn't save.">
          {submitError}
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting}>
          Save override
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface AssignmentRowProps {
  assignment: ScheduleAssignment
  employeeLabel: string
  templateName: string
  onDelete: () => void
  deleting: boolean
}

function AssignmentRow({ assignment, employeeLabel, templateName, onDelete, deleting }: AssignmentRowProps) {
  return (
    <div
      className="flex items-center justify-between"
      style={{
        gap: 'var(--sp-md)',
        padding: 'var(--sp-sm) var(--sp-md)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
      }}
    >
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {employeeLabel}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {templateName} from {assignment.effective_from}
        </span>
      </div>

      <Button variant="danger" onClick={onDelete} loading={deleting} disabled={deleting}>
        {`Delete assignment for ${employeeLabel}`}
      </Button>
    </div>
  )
}

interface TemplateRowProps {
  template: ShiftTemplate
  isDefault: boolean
  onEdit: () => void
  onDelete: () => void
  deleting: boolean
}

function TemplateRow({ template, isDefault, onEdit, onDelete, deleting }: TemplateRowProps) {
  return (
    <div
      className="flex items-center justify-between"
      style={{
        gap: 'var(--sp-md)',
        padding: 'var(--sp-sm) var(--sp-md)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
      }}
    >
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <div className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
            {template.name}
          </span>
          {isDefault ? (
            <span
              style={{
                font: 'var(--t-caption)',
                letterSpacing: 'var(--ls-caption)',
                color: 'var(--blue)',
                border: '1px solid var(--blue)',
                borderRadius: 'var(--radius)',
                padding: '0 var(--sp-xxs)',
              }}
            >
              Default
            </span>
          ) : null}
        </div>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {templateSummary(template.days)}
        </span>
      </div>

      <div className="flex" style={{ gap: 'var(--sp-xs)' }}>
        <Button variant="ghost" onClick={onEdit}>
          {`Edit ${template.name}`}
        </Button>
        <Button variant="danger" onClick={onDelete} loading={deleting} disabled={deleting}>
          {`Delete ${template.name}`}
        </Button>
      </div>
    </div>
  )
}

export default function SchedulesPage() {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const { session } = useSession()

  const hrOffices = session?.hr_offices ?? []
  // hr_offices is the authority for "offices you administer"; current_office_id only
  // covers the degenerate single-office case where an admin has none listed there yet —
  // same rule as /office/holidays.
  const defaultOfficeId = hrOffices[0] ?? session?.employee?.current_office_id ?? null

  const [chosenOfficeId, setChosenOfficeId] = useState<string | null>(null)
  const officeId = chosenOfficeId ?? defaultOfficeId

  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })
  const [deletingId, setDeletingId] = useState<string | null>(null)

  // Which template is the office's fallback default. See the file-level comment: the
  // API has no read for this, so it only ever reflects a `set` made this session — reset
  // to "not known" whenever the viewed office changes, so switching offices can never
  // show a stale default carried over from the last one. Adjusted during render (the
  // React-documented way to reset state on a prop change) rather than an effect, which
  // would commit one extra render with the stale value first.
  const [officeDefaultId, setOfficeDefaultId] = useState<string | null>(null)
  const [officeIdForDefault, setOfficeIdForDefault] = useState(officeId)
  if (officeId !== officeIdForDefault) {
    setOfficeIdForDefault(officeId)
    setOfficeDefaultId(null)
  }

  const templatesQuery = useShiftTemplates(officeId)
  const createMutation = useCreateShiftTemplate(officeId)
  const updateMutation = useUpdateShiftTemplate(officeId)
  const deleteMutation = useDeleteShiftTemplate(officeId)
  const setDefaultMutation = useSetOfficeDefaultTemplate(officeId)

  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [deletingAssignmentId, setDeletingAssignmentId] = useState<string | null>(null)

  const employeesQuery = useEmployees()
  const assignmentsQuery = useScheduleAssignments(officeId)
  const createAssignmentMutation = useCreateScheduleAssignment(officeId)
  const deleteAssignmentMutation = useDeleteScheduleAssignment(officeId)

  // The resolved calendar's own month — independent of the templates/assignments regions
  // above, which aren't month-scoped. Same `?month=` querystring convention as
  // /office/holidays and /me/attendance.
  const viewedMonth = parseViewedMonth(searchParams.get('month'))

  // No fallback to "the first employee in the office" — unlike `officeId` (every admin
  // needs SOME office picked to see anything), an unpicked employee here just means
  // "nothing to resolve yet," which is the honest state until the admin actually chooses
  // one. That also keeps this Select's trigger blank rather than echoing an employee_no
  // that might collide with the Assignments list rendering the same text elsewhere.
  const [resolvedEmployeeId, setResolvedEmployeeId] = useState<string | null>(null)

  const resolvedQuery = useResolvedMonth(resolvedEmployeeId, viewedMonth)
  const overridesQuery = useScheduleOverrides(officeId, resolvedEmployeeId, viewedMonth)
  const createOverrideMutation = useCreateScheduleOverride(resolvedEmployeeId, viewedMonth)
  const updateOverrideMutation = useUpdateScheduleOverride(resolvedEmployeeId, viewedMonth)

  const [overrideDialogState, setOverrideDialogState] = useState<OverrideDialogState>({ mode: 'closed' })

  function navigateToMonth(nextMonth: string) {
    router.replace(`${pathname}?month=${nextMonth}`)
  }

  function closeOverrideDialog() {
    setOverrideDialogState({ mode: 'closed' })
  }

  function handleOverrideSubmit(shape: DayShape) {
    if (resolvedEmployeeId === null || overrideDialogState.mode === 'closed') return

    const date = overrideDialogState.date
    const existing = overridesQuery.data?.find((override) => override.date === date)
    const shapeFields = {
      is_rest: shape.is_rest,
      start_minute: shape.start_minute,
      end_minute: shape.end_minute,
      break_minutes: shape.break_minutes,
    }

    if (existing !== undefined) {
      updateOverrideMutation.mutate({ id: existing.id, body: shapeFields }, { onSuccess: closeOverrideDialog })
      return
    }

    createOverrideMutation.mutate(
      { employee_id: resolvedEmployeeId, date, ...shapeFields },
      { onSuccess: closeOverrideDialog },
    )
  }

  function closeDialog() {
    setDialogState({ mode: 'closed' })
  }

  function handleSubmit(input: { name: string; days: ShiftDay[] }) {
    if (officeId === null || dialogState.mode === 'closed') return

    if (dialogState.mode === 'add') {
      createMutation.mutate(
        { office_id: officeId, name: input.name, days: input.days },
        { onSuccess: closeDialog },
      )
      return
    }

    updateMutation.mutate(
      { id: dialogState.template.id, body: { name: input.name, days: input.days } },
      { onSuccess: closeDialog },
    )
  }

  function handleDelete(template: ShiftTemplate) {
    setDeletingId(template.id)
    deleteMutation.mutate(template.id, { onSettled: () => setDeletingId(null) })
  }

  function handleSetDefault(templateId: string) {
    if (officeId === null) return
    setDefaultMutation.mutate(
      { office_id: officeId, template_id: templateId },
      { onSuccess: (result) => setOfficeDefaultId(result.default_shift_template_id) },
    )
  }

  function handleAssignSubmit(input: { employeeId: string; templateId: string; effectiveFrom: string }) {
    // Exactly one target key — employee_id only, never department_id (not even `null`);
    // the backend 400s on both-or-neither. See the file-level comment for why department
    // targeting has no UI yet.
    createAssignmentMutation.mutate(
      {
        shift_template_id: input.templateId,
        employee_id: input.employeeId,
        effective_from: input.effectiveFrom,
      },
      { onSuccess: () => setAssignDialogOpen(false) },
    )
  }

  function handleDeleteAssignment(assignment: ScheduleAssignment) {
    setDeletingAssignmentId(assignment.id)
    deleteAssignmentMutation.mutate(assignment.id, { onSettled: () => setDeletingAssignmentId(null) })
  }

  const templates = templatesQuery.data ?? []

  const deleteErrorMessage =
    deleteMutation.error instanceof ApiError
      ? deleteMutation.error.message
      : 'Check your connection and try again.'

  const employees = employeesQuery.data ?? []
  const officeEmployees = employees.filter((employee) => employee.current_office_id === officeId)
  const employeeLabelById = new Map(officeEmployees.map((employee) => [employee.id, employee.employee_no]))

  const assignments = assignmentsQuery.data ?? []
  const templateNameById = new Map(templates.map((template) => [template.id, template.name]))

  const assignErrorMessage =
    createAssignmentMutation.error instanceof ApiError
      ? createAssignmentMutation.error.message
      : 'Check your connection and try again.'

  const deleteAssignmentErrorMessage =
    deleteAssignmentMutation.error instanceof ApiError
      ? deleteAssignmentMutation.error.message
      : 'Check your connection and try again.'

  const resolvedMonth = resolvedQuery.data ?? {}

  const resolvedErrorMessage =
    resolvedQuery.error instanceof ApiError ? resolvedQuery.error.message : 'Check your connection and try again.'

  const activeOverrideError = createOverrideMutation.error ?? updateOverrideMutation.error
  const overrideErrorMessage =
    createOverrideMutation.isError || updateOverrideMutation.isError
      ? activeOverrideError instanceof ApiError
        ? activeOverrideError.message
        : 'Check your connection and try again.'
      : null

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Office"
          title="Schedules"
          level={1}
          actions={
            officeId !== null ? (
              <Button onClick={() => setDialogState({ mode: 'add' })}>New template</Button>
            ) : undefined
          }
        />

        {hrOffices.length > 1 ? (
          <Select
            id="schedules-office"
            label="Office"
            value={officeId ?? ''}
            onChange={setChosenOfficeId}
            options={hrOffices.map((id) => ({ value: id, label: id }))}
          />
        ) : null}

        {officeId === null ? (
          <InlineNotification kind="info" title="No office to show schedules for.">
            This account doesn&rsquo;t administer any office&rsquo;s schedules.
          </InlineNotification>
        ) : (
          <>
            {templatesQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : templatesQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load this office's shift templates.">
                Check your connection and try again.
              </InlineNotification>
            ) : templates.length === 0 ? (
              // No `action` button here — the "New template" button in the SectionHeader
              // above is the one way in, so this never duplicates it (and duplicate
              // accessible names would make `getByRole('button', { name: ... })` ambiguous).
              <EmptyState title="No shift templates yet">
                A shift template is a 7-day week an employee or department schedule can point at.
              </EmptyState>
            ) : (
              <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                {templates.map((template) => (
                  <TemplateRow
                    key={template.id}
                    template={template}
                    isDefault={template.id === officeDefaultId}
                    onEdit={() => setDialogState({ mode: 'edit', template })}
                    onDelete={() => handleDelete(template)}
                    deleting={deletingId === template.id && deleteMutation.isPending}
                  />
                ))}
              </div>
            )}

            {deleteMutation.isError ? (
              <InlineNotification kind="error" title="That template couldn't be deleted.">
                {deleteErrorMessage}
              </InlineNotification>
            ) : null}

            {templates.length > 0 ? (
              <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                <SectionHeader title="Office default" />
                <Select
                  id="schedules-default-template"
                  label="Office default template"
                  value={officeDefaultId ?? ''}
                  onChange={handleSetDefault}
                  options={templates.map((template) => ({ value: template.id, label: template.name }))}
                />
                {setDefaultMutation.isError ? (
                  <InlineNotification kind="error" title="That didn't save.">
                    Check your connection and try again.
                  </InlineNotification>
                ) : null}
              </div>
            ) : null}

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader
                title="Assignments"
                actions={<Button onClick={() => setAssignDialogOpen(true)}>Assign template</Button>}
              />

              {assignmentsQuery.isLoading ? (
                <Skeleton height="8rem" />
              ) : assignmentsQuery.isError ? (
                <InlineNotification kind="error" title="Couldn't load this office's assignments.">
                  Check your connection and try again.
                </InlineNotification>
              ) : assignments.length === 0 ? (
                <EmptyState title="No assignments yet">
                  Assign a shift template to an employee to override the office default for them.
                </EmptyState>
              ) : (
                <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                  {assignments.map((assignment) => (
                    <AssignmentRow
                      key={assignment.id}
                      assignment={assignment}
                      employeeLabel={
                        assignment.employee_id !== null
                          ? (employeeLabelById.get(assignment.employee_id) ?? assignment.employee_id)
                          : `Department ${assignment.department_id ?? 'unknown'}`
                      }
                      templateName={templateNameById.get(assignment.shift_template_id) ?? assignment.shift_template_id}
                      onDelete={() => handleDeleteAssignment(assignment)}
                      deleting={deletingAssignmentId === assignment.id && deleteAssignmentMutation.isPending}
                    />
                  ))}
                </div>
              )}

              {deleteAssignmentMutation.isError ? (
                <InlineNotification kind="error" title="That assignment couldn't be deleted.">
                  {deleteAssignmentErrorMessage}
                </InlineNotification>
              ) : null}
            </div>

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader title="Resolved calendar" />

              <Select
                id="resolved-employee"
                label="Show schedule for"
                value={resolvedEmployeeId ?? ''}
                onChange={setResolvedEmployeeId}
                options={officeEmployees.map((employee) => ({ value: employee.id, label: employee.employee_no }))}
              />

              {resolvedEmployeeId === null ? (
                <EmptyState title="Pick an employee">
                  Choose an employee above to see how their schedule resolves for a month.
                </EmptyState>
              ) : (
                <>
                  <SectionHeader
                    title={monthLabel(viewedMonth)}
                    actions={
                      <>
                        <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, -1))}>
                          Previous month
                        </Button>
                        <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, 1))}>
                          Next month
                        </Button>
                      </>
                    }
                  />

                  {resolvedQuery.isLoading ? (
                    <Skeleton height="20rem" />
                  ) : resolvedQuery.isError ? (
                    <InlineNotification kind="error" title="Couldn't resolve this employee's schedule.">
                      {resolvedErrorMessage}
                    </InlineNotification>
                  ) : (
                    <MonthCalendar
                      month={viewedMonth}
                      timeZone={OFFICE_TIME_ZONE}
                      renderDay={({ date, isToday, inMonth }) => (
                        <button
                          type="button"
                          aria-label={`Edit schedule for ${date}`}
                          onClick={() => setOverrideDialogState({ mode: 'open', date })}
                          className="flex h-full w-full flex-col items-start text-left focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--blue)]"
                          style={{
                            gap: 'var(--sp-xxs)',
                            padding: 'var(--sp-xs)',
                            background: isToday ? 'var(--surface-1)' : 'transparent',
                            opacity: inMonth ? 1 : 0.4,
                            border: 'none',
                            cursor: 'pointer',
                          }}
                        >
                          <span
                            style={{
                              font: isToday ? 'var(--t-emphasis)' : 'var(--t-body-sm)',
                              letterSpacing: 'var(--ls-body)',
                              color: inMonth ? 'var(--ink)' : 'var(--ink-subtle)',
                            }}
                          >
                            {Number(date.slice(8, 10))}
                          </span>
                          <ResolvedDayCell resolved={resolvedMonth[date]} />
                        </button>
                      )}
                    />
                  )}

                  {overrideErrorMessage !== null ? (
                    <InlineNotification kind="error" title="That override didn't save.">
                      {overrideErrorMessage}
                    </InlineNotification>
                  ) : null}
                </>
              )}
            </div>
          </>
        )}

        <Dialog
          open={dialogState.mode !== 'closed'}
          onClose={closeDialog}
          title={dialogState.mode === 'edit' ? 'Edit template' : 'New template'}
        >
          {dialogState.mode === 'closed' ? null : (
            <TemplateForm
              key={dialogState.mode === 'add' ? 'add' : dialogState.template.id}
              mode={dialogState.mode}
              initialName={dialogState.mode === 'edit' ? dialogState.template.name : ''}
              initialDays={dialogState.mode === 'edit' ? dialogState.template.days : DEFAULT_DAYS}
              submitting={createMutation.isPending || updateMutation.isPending}
              submitError={createMutation.isError || updateMutation.isError}
              onCancel={closeDialog}
              onSubmit={handleSubmit}
            />
          )}
        </Dialog>

        <Dialog
          open={assignDialogOpen}
          onClose={() => setAssignDialogOpen(false)}
          title="Assign template"
        >
          {assignDialogOpen ? (
            <AssignmentForm
              employeeOptions={officeEmployees.map((employee) => ({ value: employee.id, label: employee.employee_no }))}
              templateOptions={templates.map((template) => ({ value: template.id, label: template.name }))}
              submitting={createAssignmentMutation.isPending}
              submitError={createAssignmentMutation.isError ? assignErrorMessage : null}
              onCancel={() => setAssignDialogOpen(false)}
              onSubmit={handleAssignSubmit}
            />
          ) : null}
        </Dialog>

        <Dialog open={overrideDialogState.mode !== 'closed'} onClose={closeOverrideDialog} title="Edit schedule">
          {overrideDialogState.mode === 'closed' ? null : (
            <OverrideForm
              key={overrideDialogState.date}
              date={overrideDialogState.date}
              initialShape={shapeFromResolved(resolvedMonth[overrideDialogState.date])}
              submitting={createOverrideMutation.isPending || updateOverrideMutation.isPending}
              submitError={overrideErrorMessage}
              onCancel={closeOverrideDialog}
              onSubmit={handleOverrideSubmit}
            />
          )}
        </Dialog>
      </div>
    </AppShell>
  )
}
