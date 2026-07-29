'use client'

/**
 * The org tree's root (M8a) — organizations, sysadmin-gated. Mirrors `/admin/pay-rules`'s
 * scaffold: `is_system_admin` gating (not office scope — an organization is global config,
 * so there's no office picker), loading/error/empty via `Skeleton`/`InlineNotification`/
 * `EmptyState`, and a `Dialog`-driven create/edit form on the same mutation + invalidation
 * shape as the rest of the admin surface.
 *
 * An organization is never archived (the tree's root has no `archived_at`), so this screen
 * offers create and edit only — no archive/unarchive, unlike offices and departments below
 * it. `legal_name`/`tin` are optional; a blank field is sent as `null`, never `''`.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { Organization, OrganizationCreateInput } from '@/lib/api'
import { useCreateOrganization, useOrganizations, useUpdateOrganization } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'

const DEFAULT_ORGANIZATION_INPUT: OrganizationCreateInput = {
  name: '',
  legal_name: null,
  tin: null,
  timezone: OFFICE_TIME_ZONE,
}

function toOrganizationInput(organization: Organization): OrganizationCreateInput {
  return {
    name: organization.name,
    legal_name: organization.legal_name,
    tin: organization.tin,
    timezone: organization.timezone,
  }
}

type DialogState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; organization: Organization }

interface OrganizationFormProps {
  initial: OrganizationCreateInput
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: OrganizationCreateInput) => void
}

/** Owns its own field state, mounted fresh each time the dialog opens on a different target
 * (a `key` on the caller) — same idiom as `LeaveTypeForm`. `legal_name`/`tin` collapse a
 * blank string to `null` so an untouched optional field is absent, not an empty value. */
function OrganizationForm({ initial, submitting, submitError, onCancel, onSubmit }: OrganizationFormProps) {
  const [name, setName] = useState(initial.name)
  const [legalName, setLegalName] = useState(initial.legal_name ?? '')
  const [tin, setTin] = useState(initial.tin ?? '')
  const [timezone, setTimezone] = useState(initial.timezone)

  const hasInvalidInput = name.trim() === '' || timezone.trim() === ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return

    onSubmit({
      name,
      legal_name: legalName.trim() === '' ? null : legalName,
      tin: tin.trim() === '' ? null : tin,
      timezone,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="organization-name" label="Name" value={name} onChange={setName} required />
      <TextInput id="organization-legal-name" label="Legal name" value={legalName} onChange={setLegalName} />
      <TextInput id="organization-tin" label="TIN" value={tin} onChange={setTin} />
      <TextInput id="organization-timezone" label="Timezone" value={timezone} onChange={setTimezone} required />

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

interface OrganizationRowProps {
  organization: Organization
  onEdit: () => void
}

function OrganizationRow({ organization, onEdit }: OrganizationRowProps) {
  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {organization.name}
        </span>
        <Button variant="ghost" onClick={onEdit}>
          Edit
        </Button>
      </div>

      <dl className="flex flex-wrap" style={{ gap: 'var(--sp-lg)', margin: 0 }}>
        {[
          { label: 'Legal name', value: organization.legal_name ?? '—' },
          { label: 'TIN', value: organization.tin ?? '—' },
          { label: 'Timezone', value: organization.timezone },
        ].map((field) => (
          <div key={field.label} className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
            <dt style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}>
              {field.label}
            </dt>
            <dd style={{ margin: 0, font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
              {field.value}
            </dd>
          </div>
        ))}
      </dl>
    </li>
  )
}

export default function OrganizationsPage() {
  const { session } = useSession()

  const organizationsQuery = useOrganizations()
  const createMutation = useCreateOrganization()
  const updateMutation = useUpdateOrganization()

  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })

  const organizations = organizationsQuery.data ?? []
  const isSysAdmin = session?.is_system_admin ?? false

  function closeDialog(): void {
    setDialogState({ mode: 'closed' })
  }

  function handleSubmit(input: OrganizationCreateInput): void {
    if (dialogState.mode === 'add') {
      createMutation.mutate(input, { onSuccess: closeDialog })
      return
    }

    if (dialogState.mode === 'edit') {
      updateMutation.mutate({ id: dialogState.organization.id, body: input }, { onSuccess: closeDialog })
    }
  }

  const isEdit = dialogState.mode === 'edit'
  const activeMutation = isEdit ? updateMutation : createMutation

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin"
          title="Organizations"
          level={1}
          actions={isSysAdmin ? <Button onClick={() => setDialogState({ mode: 'add' })}>New organization</Button> : undefined}
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer organizations.">
            Organizations are a system-admin-only screen.
          </InlineNotification>
        ) : organizationsQuery.isLoading ? (
          <Skeleton height="12rem" />
        ) : organizationsQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load organizations.">
            Check your connection and try again.
          </InlineNotification>
        ) : organizations.length === 0 ? (
          <EmptyState title="No organizations yet">
            Create the first one with &ldquo;New organization&rdquo; above.
          </EmptyState>
        ) : (
          <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
            {organizations.map((organization) => (
              <OrganizationRow
                key={organization.id}
                organization={organization}
                onEdit={() => setDialogState({ mode: 'edit', organization })}
              />
            ))}
          </ul>
        )}

        <Dialog
          open={dialogState.mode !== 'closed'}
          onClose={closeDialog}
          title={isEdit ? 'Edit organization' : 'New organization'}
        >
          {dialogState.mode === 'closed' ? null : (
            <OrganizationForm
              key={dialogState.mode === 'add' ? 'add' : dialogState.organization.id}
              initial={dialogState.mode === 'edit' ? toOrganizationInput(dialogState.organization) : DEFAULT_ORGANIZATION_INPUT}
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
