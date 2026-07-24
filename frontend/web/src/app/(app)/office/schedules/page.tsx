'use client'

/**
 * An HR admin's view of one office's shift templates — the first slice of
 * `/office/schedules` (M4b). Mirrors `/office/holidays`'s scaffold: an office picker from
 * `session.hr_offices` (never `current_office_id` alone — see that screen's comment),
 * loading/error/empty via Skeleton/InlineNotification/EmptyState, and a Dialog-driven
 * create/edit flow built on the same mutation + invalidation shape.
 *
 * Deliberately out of scope here (later tasks): per-employee/department assignments and
 * the resolved-calendar view. This slice is templates + which one is the office's
 * fallback default.
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

import { WeekEditor } from '@/components/domain/WeekEditor'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'
import type { ShiftDay, ShiftTemplate, Weekday } from '@/lib/api'
import { ApiError } from '@/lib/api'
import {
  useCreateShiftTemplate,
  useDeleteShiftTemplate,
  useSetOfficeDefaultTemplate,
  useShiftTemplates,
  useUpdateShiftTemplate,
} from '@/hooks/useShiftTemplates'
import { useSession } from '@/hooks/useSession'

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

  const templates = templatesQuery.data ?? []

  const deleteErrorMessage =
    deleteMutation.error instanceof ApiError
      ? deleteMutation.error.message
      : 'Check your connection and try again.'

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
      </div>
    </AppShell>
  )
}
