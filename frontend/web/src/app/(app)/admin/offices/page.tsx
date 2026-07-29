'use client'

/**
 * The org tree's second tier (M8a) — offices, sysadmin-gated. Mirrors `/admin/organizations`
 * plus the archive-never-delete shape of the backend: an office is never deleted, only
 * archived and unarchived (two dedicated POST transitions on `archived_at`), so each row
 * carries an Archive/Unarchive action rather than a delete.
 *
 * Two filters sit above the list: an **organization picker** (sourced from
 * `useOrganizations`) that scopes the query, and a **show-archived toggle**. The list is
 * always fetched with `include_archived` following the toggle, but the toggle ALSO filters
 * client-side — so an archived row that's already in the cache disappears the instant the
 * toggle flips, without waiting on a refetch. Archived rows are badged with `<Tag>`.
 *
 * `geofence_*`, `ip_allowlist`, and `default_shift_template_id` are all optional; a blank
 * field is sent as `null` (or an empty allowlist as `null`), never `''` or `NaN`.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { Office, OfficeCreateInput } from '@/lib/api'
import {
  useArchiveOffice,
  useCreateOffice,
  useOffices,
  useOrganizations,
  useUnarchiveOffice,
  useUpdateOffice,
} from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
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

/** Blank → null (field absent); a number → itself; anything else → invalid, blocking submit
 * rather than silently serializing `NaN`. */
function parseOptionalNumber(raw: string): { value: number | null; invalid: boolean } {
  if (raw.trim() === '') return { value: null, invalid: false }
  const value = Number(raw)
  return Number.isNaN(value) ? { value: null, invalid: true } : { value, invalid: false }
}

/** Comma-or-newline separated CIDRs/IPs → a trimmed non-empty list, or null when blank. */
function parseIpAllowlist(raw: string): string[] | null {
  const entries = raw
    .split(/[\n,]/)
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '')
  return entries.length === 0 ? null : entries
}

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

type DialogState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; office: Office }

interface OfficeFormProps {
  initial: OfficeCreateInput
  organizationOptions: SelectOption[]
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: OfficeCreateInput) => void
}

/** Owns its own field state, remounted fresh per target via a `key` on the caller. The
 * numeric geofence fields and the allowlist are kept as strings and parsed on submit, so a
 * half-typed value never round-trips through `NaN`. */
function OfficeForm({ initial, organizationOptions, submitting, submitError, onCancel, onSubmit }: OfficeFormProps) {
  const [organizationId, setOrganizationId] = useState(initial.organization_id || organizationOptions[0]?.value || '')
  const [name, setName] = useState(initial.name)
  const [code, setCode] = useState(initial.code)
  const [timezone, setTimezone] = useState(initial.timezone)
  const [geofenceLat, setGeofenceLat] = useState(initial.geofence_lat != null ? String(initial.geofence_lat) : '')
  const [geofenceLng, setGeofenceLng] = useState(initial.geofence_lng != null ? String(initial.geofence_lng) : '')
  const [geofenceRadius, setGeofenceRadius] = useState(
    initial.geofence_radius_m != null ? String(initial.geofence_radius_m) : '',
  )
  const [ipAllowlist, setIpAllowlist] = useState((initial.ip_allowlist ?? []).join(', '))
  const [defaultShiftTemplateId, setDefaultShiftTemplateId] = useState(initial.default_shift_template_id ?? '')

  const lat = parseOptionalNumber(geofenceLat)
  const lng = parseOptionalNumber(geofenceLng)
  const radius = parseOptionalNumber(geofenceRadius)

  const hasInvalidInput =
    organizationId === '' ||
    name.trim() === '' ||
    code.trim() === '' ||
    timezone.trim() === '' ||
    lat.invalid ||
    lng.invalid ||
    radius.invalid

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return

    onSubmit({
      organization_id: organizationId,
      name,
      code,
      timezone,
      geofence_lat: lat.value,
      geofence_lng: lng.value,
      geofence_radius_m: radius.value,
      ip_allowlist: parseIpAllowlist(ipAllowlist),
      default_shift_template_id: defaultShiftTemplateId.trim() === '' ? null : defaultShiftTemplateId.trim(),
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <Select
        id="office-organization"
        label="Organization"
        value={organizationId}
        onChange={setOrganizationId}
        options={organizationOptions}
      />
      <TextInput id="office-name" label="Name" value={name} onChange={setName} required />
      <TextInput id="office-code" label="Code" value={code} onChange={setCode} required />
      <TextInput id="office-timezone" label="Timezone" value={timezone} onChange={setTimezone} required />
      <TextInput
        id="office-geofence-lat"
        label="Geofence latitude"
        value={geofenceLat}
        onChange={setGeofenceLat}
        error={lat.invalid ? 'Enter a number, or leave it blank.' : undefined}
      />
      <TextInput
        id="office-geofence-lng"
        label="Geofence longitude"
        value={geofenceLng}
        onChange={setGeofenceLng}
        error={lng.invalid ? 'Enter a number, or leave it blank.' : undefined}
      />
      <TextInput
        id="office-geofence-radius"
        label="Geofence radius (m)"
        value={geofenceRadius}
        onChange={setGeofenceRadius}
        error={radius.invalid ? 'Enter a number, or leave it blank.' : undefined}
      />
      <TextInput
        id="office-ip-allowlist"
        label="IP allowlist (comma-separated)"
        value={ipAllowlist}
        onChange={setIpAllowlist}
      />
      <TextInput
        id="office-default-shift-template"
        label="Default shift template ID"
        value={defaultShiftTemplateId}
        onChange={setDefaultShiftTemplateId}
      />

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

interface OfficeRowProps {
  office: Office
  busy: boolean
  onEdit: () => void
  onArchive: () => void
  onUnarchive: () => void
}

function OfficeRow({ office, busy, onEdit, onArchive, onUnarchive }: OfficeRowProps) {
  const isArchived = office.archived_at !== null

  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
            {office.name}
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
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Code: {office.code}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Timezone: {office.timezone}
        </span>
      </div>
    </li>
  )
}

const DEFAULT_OFFICE_INPUT: OfficeCreateInput = {
  organization_id: '',
  name: '',
  code: '',
  timezone: OFFICE_TIME_ZONE,
  geofence_lat: null,
  geofence_lng: null,
  geofence_radius_m: null,
  ip_allowlist: null,
  default_shift_template_id: null,
}

function toOfficeInput(office: Office): OfficeCreateInput {
  return {
    organization_id: office.organization_id,
    name: office.name,
    code: office.code,
    timezone: office.timezone,
    geofence_lat: office.geofence_lat,
    geofence_lng: office.geofence_lng,
    geofence_radius_m: office.geofence_radius_m,
    ip_allowlist: office.ip_allowlist,
    default_shift_template_id: office.default_shift_template_id,
  }
}

export default function OfficesPage() {
  const { session } = useSession()

  const [filterOrganizationId, setFilterOrganizationId] = useState('')
  const [showArchived, setShowArchived] = useState(false)
  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })

  const organizationsQuery = useOrganizations()
  const officesQuery = useOffices({
    include_archived: showArchived,
    ...(filterOrganizationId === '' ? {} : { organization: filterOrganizationId }),
  })
  const createMutation = useCreateOffice()
  const updateMutation = useUpdateOffice()
  const archiveMutation = useArchiveOffice()
  const unarchiveMutation = useUnarchiveOffice()

  const organizations = organizationsQuery.data ?? []
  // Filter client-side too, so an archived row already in cache disappears the instant the
  // toggle flips rather than waiting on the refetch the changed query key triggers.
  const offices = (officesQuery.data ?? []).filter((office) => showArchived || office.archived_at === null)
  const isSysAdmin = session?.is_system_admin ?? false

  const organizationOptions: SelectOption[] = organizations.map((organization) => ({
    value: organization.id,
    label: organization.name,
  }))

  function closeDialog(): void {
    setDialogState({ mode: 'closed' })
  }

  function handleSubmit(input: OfficeCreateInput): void {
    if (dialogState.mode === 'add') {
      createMutation.mutate(input, { onSuccess: closeDialog })
      return
    }

    if (dialogState.mode === 'edit') {
      updateMutation.mutate({ id: dialogState.office.id, body: input }, { onSuccess: closeDialog })
    }
  }

  const isEdit = dialogState.mode === 'edit'
  const activeMutation = isEdit ? updateMutation : createMutation

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin"
          title="Offices"
          level={1}
          actions={
            isSysAdmin && organizations.length > 0 ? (
              <Button onClick={() => setDialogState({ mode: 'add' })}>New office</Button>
            ) : undefined
          }
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer offices.">
            Offices are a system-admin-only screen.
          </InlineNotification>
        ) : (
          <>
            <div className="flex items-end flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
              <Select
                id="office-filter-organization"
                label="Organization"
                value={filterOrganizationId}
                onChange={setFilterOrganizationId}
                options={[{ value: '', label: 'All organizations' }, ...organizationOptions]}
              />
              <CheckboxToggle
                id="office-show-archived"
                label="Show archived"
                checked={showArchived}
                onChange={setShowArchived}
              />
            </div>

            {officesQuery.isLoading ? (
              <Skeleton height="12rem" />
            ) : officesQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load offices.">
                Check your connection and try again.
              </InlineNotification>
            ) : offices.length === 0 ? (
              <EmptyState title="No offices to show">
                {organizations.length === 0
                  ? 'Create an organization first, then add its offices here.'
                  : 'Create the first one with “New office” above.'}
              </EmptyState>
            ) : (
              <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                {offices.map((office) => (
                  <OfficeRow
                    key={office.id}
                    office={office}
                    busy={
                      (archiveMutation.isPending && archiveMutation.variables === office.id) ||
                      (unarchiveMutation.isPending && unarchiveMutation.variables === office.id)
                    }
                    onEdit={() => setDialogState({ mode: 'edit', office })}
                    onArchive={() => archiveMutation.mutate(office.id)}
                    onUnarchive={() => unarchiveMutation.mutate(office.id)}
                  />
                ))}
              </ul>
            )}
          </>
        )}

        <Dialog open={dialogState.mode !== 'closed'} onClose={closeDialog} title={isEdit ? 'Edit office' : 'New office'}>
          {dialogState.mode === 'closed' ? null : (
            <OfficeForm
              key={dialogState.mode === 'add' ? 'add' : dialogState.office.id}
              initial={
                dialogState.mode === 'edit'
                  ? toOfficeInput(dialogState.office)
                  : { ...DEFAULT_OFFICE_INPUT, organization_id: filterOrganizationId }
              }
              organizationOptions={organizationOptions}
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
