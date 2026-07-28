'use client'

/**
 * The org tree's third tier (M8a) — departments, sysadmin-gated. Mirrors `/admin/offices`
 * exactly, one tier down: an **office picker** (sourced from `useOffices`) scopes the list,
 * a **show-archived toggle** filters both the query and the rendered rows, and each row
 * carries the same archive-never-delete Archive/Unarchive action (never a delete). A
 * department's only fields are name, code, and its owning office.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { Department, DepartmentCreateInput } from '@/lib/api'
import {
  useArchiveDepartment,
  useCreateDepartment,
  useDepartments,
  useOffices,
  useUnarchiveDepartment,
  useUpdateDepartment,
} from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
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

interface CheckboxToggleProps {
  id: string
  label: string
  checked: boolean
  onChange: (checked: boolean) => void
}

/** A token-styled checkbox — mirrors `DayShapeFields`/`LeaveTypeForm`'s raw
 * `<input type="checkbox">` treatment rather than inventing a tier-1 component. */
function CheckboxToggle({ id, label, checked, onChange }: CheckboxToggleProps) {
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

type DialogState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; department: Department }

interface DepartmentFormProps {
  initial: DepartmentCreateInput
  officeOptions: SelectOption[]
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: DepartmentCreateInput) => void
}

/** Owns its own field state, remounted fresh per target via a `key` on the caller. */
function DepartmentForm({ initial, officeOptions, submitting, submitError, onCancel, onSubmit }: DepartmentFormProps) {
  const [officeId, setOfficeId] = useState(initial.office_id || officeOptions[0]?.value || '')
  const [name, setName] = useState(initial.name)
  const [code, setCode] = useState(initial.code)

  const hasInvalidInput = officeId === '' || name.trim() === '' || code.trim() === ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return
    onSubmit({ office_id: officeId, name, code })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select id="department-office" label="Office" value={officeId} onChange={setOfficeId} options={officeOptions} />
      <TextInput id="department-name" label="Name" value={name} onChange={setName} required />
      <TextInput id="department-code" label="Code" value={code} onChange={setCode} required />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || hasInvalidInput}>
          Save
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface DepartmentRowProps {
  department: Department
  busy: boolean
  onEdit: () => void
  onArchive: () => void
  onUnarchive: () => void
}

function DepartmentRow({ department, busy, onEdit, onArchive, onUnarchive }: DepartmentRowProps) {
  const isArchived = department.archived_at !== null

  return (
    <li
      className="flex items-center justify-between flex-wrap"
      style={{ gap: 'var(--sp-sm)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {department.name}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {department.code}
        </span>
        {isArchived ? <Tag kind="neutral">Archived</Tag> : null}
      </span>
      <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
        <Button variant="ghost" onClick={onEdit}>
          Edit
        </Button>
        {isArchived ? (
          <Button variant="ghost" loading={busy} disabled={busy} onClick={onUnarchive}>
            Unarchive
          </Button>
        ) : (
          <Button variant="ghost" loading={busy} disabled={busy} onClick={onArchive}>
            Archive
          </Button>
        )}
      </span>
    </li>
  )
}

export default function DepartmentsPage() {
  const { session } = useSession()

  const [filterOfficeId, setFilterOfficeId] = useState('')
  const [showArchived, setShowArchived] = useState(false)
  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })

  // Active offices only (no include_archived) — you don't file new departments under an
  // archived office, and the picker/filter should list the live ones.
  const officesQuery = useOffices()
  const departmentsQuery = useDepartments({
    include_archived: showArchived,
    ...(filterOfficeId === '' ? {} : { office: filterOfficeId }),
  })
  const createMutation = useCreateDepartment()
  const updateMutation = useUpdateDepartment()
  const archiveMutation = useArchiveDepartment()
  const unarchiveMutation = useUnarchiveDepartment()

  const offices = officesQuery.data ?? []
  // Filter client-side too, so an archived row already in cache disappears the instant the
  // toggle flips rather than waiting on the refetch the changed query key triggers.
  const departments = (departmentsQuery.data ?? []).filter(
    (department) => showArchived || department.archived_at === null,
  )
  const isSysAdmin = session?.is_system_admin ?? false

  const officeOptions: SelectOption[] = offices.map((office) => ({ value: office.id, label: office.name }))

  function closeDialog(): void {
    setDialogState({ mode: 'closed' })
  }

  function handleSubmit(input: DepartmentCreateInput): void {
    if (dialogState.mode === 'add') {
      createMutation.mutate(input, { onSuccess: closeDialog })
      return
    }

    if (dialogState.mode === 'edit') {
      updateMutation.mutate({ id: dialogState.department.id, body: input }, { onSuccess: closeDialog })
    }
  }

  const isEdit = dialogState.mode === 'edit'
  const activeMutation = isEdit ? updateMutation : createMutation

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin"
          title="Departments"
          level={1}
          actions={
            isSysAdmin && offices.length > 0 ? (
              <Button onClick={() => setDialogState({ mode: 'add' })}>New department</Button>
            ) : undefined
          }
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer departments.">
            Departments are a system-admin-only screen.
          </InlineNotification>
        ) : (
          <>
            <div className="flex items-end flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
              <Select
                id="department-filter-office"
                label="Office"
                value={filterOfficeId}
                onChange={setFilterOfficeId}
                options={[{ value: '', label: 'All offices' }, ...officeOptions]}
              />
              <CheckboxToggle
                id="department-show-archived"
                label="Show archived"
                checked={showArchived}
                onChange={setShowArchived}
              />
            </div>

            {departmentsQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : departmentsQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load departments.">
                Check your connection and try again.
              </InlineNotification>
            ) : departments.length === 0 ? (
              <EmptyState title="No departments to show">
                {offices.length === 0
                  ? 'Create an office first, then add its departments here.'
                  : 'Create the first one with “New department” above.'}
              </EmptyState>
            ) : (
              <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                {departments.map((department) => (
                  <DepartmentRow
                    key={department.id}
                    department={department}
                    busy={
                      (archiveMutation.isPending && archiveMutation.variables === department.id) ||
                      (unarchiveMutation.isPending && unarchiveMutation.variables === department.id)
                    }
                    onEdit={() => setDialogState({ mode: 'edit', department })}
                    onArchive={() => archiveMutation.mutate(department.id)}
                    onUnarchive={() => unarchiveMutation.mutate(department.id)}
                  />
                ))}
              </ul>
            )}
          </>
        )}

        <Dialog
          open={dialogState.mode !== 'closed'}
          onClose={closeDialog}
          title={isEdit ? 'Edit department' : 'New department'}
        >
          {dialogState.mode === 'closed' ? null : (
            <DepartmentForm
              key={dialogState.mode === 'add' ? 'add' : dialogState.department.id}
              initial={
                dialogState.mode === 'edit'
                  ? { office_id: dialogState.department.office_id, name: dialogState.department.name, code: dialogState.department.code }
                  : { office_id: filterOfficeId, name: '', code: '' }
              }
              officeOptions={officeOptions}
              submitting={activeMutation.isPending}
              submitError={activeMutation.isError}
              onCancel={closeDialog}
              onSubmit={handleSubmit}
            />
          )}
        </Dialog>
      </div>
    </AppShell>
  )
}
