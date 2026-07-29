'use client'

/**
 * The employee profile (M8b) — sysadmin-gated, reached from `/admin/employees`'s roster.
 * Reads the `[employee]` route param straight into `useAdminEmployee`, mirroring
 * `PayrollExportPage`'s `useParams` shape.
 *
 * Three independent write paths, each its own self-contained form (owns its own field
 * state, like `OfficeForm`/the create wizard's steps) so one submitting/erroring mutation
 * never blocks another:
 *   - **Edit name** — `useUpdateEmployee`. `employee_no` is immutable and not offered here.
 *   - **Record employment change** — `useRecordEmployment`. A NEW effective-dated segment,
 *     not an edit of the current one (the backend never overwrites employment history), so
 *     `effective_from` always starts blank while the rest prefills from the current segment
 *     as a sensible starting point. Office → department cascades exactly like the create
 *     wizard's step 2: choosing an office resets department + reports-to.
 *   - **Provision login** — `useProvisionUser`, shown only while `!has_user`; once a login
 *     exists there is no unprovision path (mirrors the backend, which has none either).
 *
 * All three mutations invalidate this employee's detail key (see `useAdminEmployees.ts`),
 * so a successful submit refetches without any local state merging.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import Link from 'next/link'
import { useParams } from 'next/navigation'

import type { AdminEmployeeDetail, EmploymentInput, EmploymentType, ProvisionUserInput } from '@/lib/api'
import {
  useAdminEmployee,
  useAdminEmployees,
  useProvisionUser,
  useRecordEmployment,
  useSetHrOffices,
  useUpdateEmployee,
} from '@/hooks/useAdminEmployees'
import { useDepartments, useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
import { formatCentavos } from '@/lib/money'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { Tag } from '@/components/Tag'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'

const EMPLOYMENT_TYPE_OPTIONS: SelectOption[] = [
  { value: 'regular', label: 'Regular' },
  { value: 'probationary', label: 'Probationary' },
  { value: 'contractual', label: 'Contractual' },
]

/** Blank or non-numeric → invalid (blocks submit rather than serializing NaN). A valid
 * peso amount becomes integer centavos: `1234.5` → `123450`. Mirrors the create wizard's
 * helper of the same name. */
function pesosToCentavos(raw: string): { cents: number | null; invalid: boolean } {
  if (raw.trim() === '') return { cents: null, invalid: true }
  const pesos = Number(raw)
  if (Number.isNaN(pesos) || pesos < 0) return { cents: null, invalid: true }
  return { cents: Math.round(pesos * 100), invalid: false }
}

interface CheckboxToggleProps {
  id: string
  label: string
  checked: boolean
  onChange: (checked: boolean) => void
}

/** A token-styled checkbox — mirrors the org-tree screens' and the create wizard's raw
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

interface NameFormProps {
  initial: {
    first_name: string
    middle_name: string | null
    last_name: string
    name_suffix: string | null
  }
  submitting: boolean
  submitError: boolean
  onSubmit: (body: {
    first_name: string
    middle_name: string | null
    last_name: string
    name_suffix: string | null
  }) => void
}

function NameForm({ initial, submitting, submitError, onSubmit }: NameFormProps) {
  const [firstName, setFirstName] = useState(initial.first_name)
  const [middleName, setMiddleName] = useState(initial.middle_name ?? '')
  const [lastName, setLastName] = useState(initial.last_name)
  const [nameSuffix, setNameSuffix] = useState(initial.name_suffix ?? '')

  const isValid = firstName.trim() !== '' && lastName.trim() !== ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    onSubmit({
      first_name: firstName.trim(),
      middle_name: middleName.trim() === '' ? null : middleName.trim(),
      last_name: lastName.trim(),
      name_suffix: nameSuffix.trim() === '' ? null : nameSuffix.trim(),
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="edit-first-name" label="First name" value={firstName} onChange={setFirstName} required />
      <TextInput id="edit-middle-name" label="Middle name" value={middleName} onChange={setMiddleName} />
      <TextInput id="edit-last-name" label="Last name" value={lastName} onChange={setLastName} required />
      <TextInput id="edit-name-suffix" label="Suffix" value={nameSuffix} onChange={setNameSuffix} />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting || !isValid}>
          Save name
        </Button>
      </div>
    </form>
  )
}

interface EmploymentFormProps {
  employeeId: string
  initial: {
    office_id: string
    department_id: string
    employment_type: string
    is_art82_exempt: boolean
    base_rate_cents: number
    reports_to_id: string | null
  }
  submitting: boolean
  submitError: boolean
  onSubmit: (body: EmploymentInput) => void
}

/** Self-contained like the create wizard's employment step: owns office/department
 * cascading, the reports-to roster, and its own field state. `effective_from` always
 * starts blank — recording employment is always a NEW dated segment, never an in-place
 * edit of the current one. */
function EmploymentForm({ employeeId, initial, submitting, submitError, onSubmit }: EmploymentFormProps) {
  const [effectiveFrom, setEffectiveFrom] = useState('')
  const [officeId, setOfficeId] = useState(initial.office_id)
  const [departmentId, setDepartmentId] = useState(initial.department_id)
  const [employmentType, setEmploymentType] = useState<EmploymentType>(
    (['regular', 'probationary', 'contractual'] as const).includes(initial.employment_type as EmploymentType)
      ? (initial.employment_type as EmploymentType)
      : 'regular',
  )
  const [isArt82Exempt, setIsArt82Exempt] = useState(initial.is_art82_exempt)
  const [baseRatePesos, setBaseRatePesos] = useState(String(initial.base_rate_cents / 100))
  const [reportsToId, setReportsToId] = useState(initial.reports_to_id ?? '')

  const officesQuery = useOffices()
  const departmentsQuery = useDepartments(officeId === '' ? undefined : { office: officeId })
  const employeesQuery = useAdminEmployees(officeId === '' ? undefined : { office: officeId })

  const offices = (officesQuery.data ?? []).filter((office) => office.archived_at === null)
  const departments = (departmentsQuery.data ?? []).filter(
    (department) => department.archived_at === null && department.office_id === officeId,
  )
  const employees = (employeesQuery.data ?? []).filter((employee) => employee.id !== employeeId)

  const officeOptions: SelectOption[] = offices.map((office) => ({ value: office.id, label: office.name }))
  const departmentOptions: SelectOption[] = departments.map((department) => ({
    value: department.id,
    label: department.name,
  }))
  const reportsToOptions: SelectOption[] = [
    { value: '', label: 'No manager' },
    ...employees.map((employee) => ({ value: employee.id, label: employee.full_name })),
  ]

  const baseRate = pesosToCentavos(baseRatePesos)
  const isValid =
    effectiveFrom !== '' && officeId !== '' && departmentId !== '' && !baseRate.invalid

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid || baseRate.cents === null) return

    onSubmit({
      effective_from: effectiveFrom,
      office_id: officeId,
      department_id: departmentId,
      reports_to_id: reportsToId === '' ? null : reportsToId,
      employment_type: employmentType,
      is_art82_exempt: isArt82Exempt,
      base_rate_cents: baseRate.cents,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput
        id="employment-effective-from"
        label="Effective from"
        type="date"
        value={effectiveFrom}
        onChange={setEffectiveFrom}
        required
      />
      <Select
        id="employment-office"
        label="Office"
        value={officeId}
        onChange={(value) => {
          setOfficeId(value)
          setDepartmentId('')
          setReportsToId('')
        }}
        options={[{ value: '', label: 'Select an office' }, ...officeOptions]}
      />
      <Select
        id="employment-department"
        label="Department"
        value={departmentId}
        onChange={setDepartmentId}
        options={[{ value: '', label: 'Select a department' }, ...departmentOptions]}
      />
      <Select
        id="employment-type"
        label="Employment type"
        value={employmentType}
        onChange={(value) => setEmploymentType(value as EmploymentType)}
        options={EMPLOYMENT_TYPE_OPTIONS}
      />
      <CheckboxToggle
        id="employment-is-art82-exempt"
        label="Art. 82 exempt (managerial / field personnel — no overtime, night diff, or holiday premium)"
        checked={isArt82Exempt}
        onChange={setIsArt82Exempt}
      />
      <TextInput
        id="employment-base-rate"
        label="Base rate (₱)"
        value={baseRatePesos}
        onChange={setBaseRatePesos}
        error={baseRate.invalid && baseRatePesos.trim() !== '' ? 'Enter a non-negative amount.' : undefined}
        required
      />
      <Select
        id="employment-reports-to"
        label="Reports to"
        value={reportsToId}
        onChange={setReportsToId}
        options={reportsToOptions}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting || !isValid}>
          Record employment change
        </Button>
      </div>
    </form>
  )
}

interface ProvisionFormProps {
  initialName: string
  submitting: boolean
  submitError: boolean
  onSubmit: (body: ProvisionUserInput) => void
}

function ProvisionForm({ initialName, submitting, submitError, onSubmit }: ProvisionFormProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [name, setName] = useState(initialName)

  const isValid = email.trim() !== '' && password !== '' && name.trim() !== ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    onSubmit({ email: email.trim(), password, name: name.trim() })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="provision-email" label="Email" type="email" value={email} onChange={setEmail} autoComplete="off" />
      <TextInput
        id="provision-password"
        label="Password"
        type="password"
        value={password}
        onChange={setPassword}
        autoComplete="new-password"
      />
      <TextInput id="provision-name" label="Display name" value={name} onChange={setName} />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting || !isValid}>
          Provision login
        </Button>
      </div>
    </form>
  )
}

interface HrOfficesFormProps {
  offices: Array<{ id: string; name: string }>
  initialOfficeIds: string[]
  roles: string[]
  submitting: boolean
  submitError: boolean
  onSubmit: (officeIds: string[]) => void
}

/** The office-admin access panel (M8c) — a checkbox per office (mirrors
 * `CheckboxToggle`'s existing raw-checkbox treatment rather than a new tier-1
 * multi-select), prefilled from `hr_admin_office_ids`. Submitting always sends the
 * FULL selected set — the backend syncs the pivot and assigns/removes the HR Admin
 * role from that one list, so there is no separate add/remove call. */
function HrOfficesForm({ offices, initialOfficeIds, roles, submitting, submitError, onSubmit }: HrOfficesFormProps) {
  const [selected, setSelected] = useState<string[]>(initialOfficeIds)

  function toggleOffice(officeId: string, checked: boolean): void {
    setSelected((current) =>
      checked ? [...current, officeId] : current.filter((id) => id !== officeId),
    )
  }

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    onSubmit(selected)
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-subtle)' }}>
          Offices
        </span>
        {offices.length === 0 ? (
          <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            No offices to grant.
          </span>
        ) : (
          <div className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
            {offices.map((office) => (
              <CheckboxToggle
                key={office.id}
                id={`hr-office-${office.id}`}
                label={office.name}
                checked={selected.includes(office.id)}
                onChange={(checked) => toggleOffice(office.id, checked)}
              />
            ))}
          </div>
        )}
      </div>

      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-subtle)' }}>
          Roles
        </span>
        <div className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
          {roles.length === 0 ? (
            <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
              No roles
            </span>
          ) : (
            roles.map((role) => (
              <Tag key={role} kind="neutral">
                {role}
              </Tag>
            ))
          )}
        </div>
      </div>

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting}>
          Save access
        </Button>
      </div>
    </form>
  )
}

interface CurrentEmploymentCardProps {
  employment: NonNullable<AdminEmployeeDetail['current_employment']>
  officeName: string
  departmentName: string
  reportsToName: string | null
}

function CurrentEmploymentCard({ employment, officeName, departmentName, reportsToName }: CurrentEmploymentCardProps) {
  const rows: Array<[string, string]> = [
    ['Office', officeName],
    ['Department', departmentName],
    ['Employment type', employment.employment_type],
    ['Art. 82 exempt', employment.is_art82_exempt ? 'Yes' : 'No'],
    ['Base rate', formatCentavos(employment.base_rate_cents)],
    ['Reports to', reportsToName ?? 'No manager'],
    ['Effective from', employment.effective_from ?? '—'],
  ]

  return (
    <dl className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
      {rows.map(([label, value]) => (
        <div key={label} className="flex" style={{ gap: 'var(--sp-sm)' }}>
          <dt
            style={{
              minWidth: '10rem',
              font: 'var(--t-body-sm)',
              letterSpacing: 'var(--ls-body)',
              color: 'var(--ink-subtle)',
            }}
          >
            {label}
          </dt>
          <dd style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>{value}</dd>
        </div>
      ))}
    </dl>
  )
}

export default function EmployeeDetailPage() {
  const params = useParams<{ employee: string }>()
  const id = typeof params.employee === 'string' ? params.employee : null

  const { session } = useSession()
  const isSysAdmin = session?.is_system_admin ?? false

  const employeeQuery = useAdminEmployee(id)
  const officesQuery = useOffices()
  const departmentsQuery = useDepartments()
  const employeesQuery = useAdminEmployees()

  const updateMutation = useUpdateEmployee()
  const recordEmploymentMutation = useRecordEmployment()
  const provisionMutation = useProvisionUser()
  const setHrOfficesMutation = useSetHrOffices()

  const detail = employeeQuery.data ?? null
  const offices = officesQuery.data ?? []
  const departments = departmentsQuery.data ?? []
  const employees = employeesQuery.data ?? []

  const officeNameById = new Map(offices.map((office) => [office.id, office.name]))
  const departmentNameById = new Map(departments.map((department) => [department.id, department.name]))
  const employeeNameById = new Map(employees.map((employee) => [employee.id, employee.full_name]))

  function handleUpdateName(body: {
    first_name: string
    middle_name: string | null
    last_name: string
    name_suffix: string | null
  }): void {
    if (id === null) return
    updateMutation.mutate({ id, body })
  }

  function handleRecordEmployment(body: EmploymentInput): void {
    if (id === null) return
    recordEmploymentMutation.mutate({ id, body })
  }

  function handleProvision(body: ProvisionUserInput): void {
    if (id === null) return
    provisionMutation.mutate({ id, body })
  }

  function handleSetHrOffices(officeIds: string[]): void {
    if (id === null) return
    setHrOfficesMutation.mutate({ id, body: { office_ids: officeIds } })
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Admin · Employees"
          title={detail?.full_name ?? 'Employee'}
          level={1}
          actions={
            <Link
              href="/admin/employees"
              className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
              style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--blue)', textDecoration: 'none' }}
            >
              Back to employees
            </Link>
          }
        />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't administer employees.">
            The employee profile is a system-admin-only screen.
          </InlineNotification>
        ) : id === null ? (
          <InlineNotification kind="error" title="No employee to show.">
            This screen needs an employee id in the URL.
          </InlineNotification>
        ) : employeeQuery.isLoading ? (
          <Skeleton height="16rem" />
        ) : employeeQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load this employee.">
            Check your connection and try again.
          </InlineNotification>
        ) : detail === null ? null : (
          <>
            <div className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
              <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
                Employee no. {detail.employee_no}
                {detail.hired_at !== null ? ` · Hired ${detail.hired_at}` : ''}
              </span>
              <span className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
                <Tag kind={detail.has_user ? 'success' : 'neutral'}>{detail.has_user ? 'Has login' : 'No login'}</Tag>
              </span>
            </div>

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader title="Current employment" level={2} />
              {detail.current_employment === null ? (
                <InlineNotification kind="info" title="No employment on record.">
                  Record one below to price this employee&rsquo;s days.
                </InlineNotification>
              ) : (
                <CurrentEmploymentCard
                  employment={detail.current_employment}
                  officeName={officeNameById.get(detail.current_employment.office_id) ?? '—'}
                  departmentName={departmentNameById.get(detail.current_employment.department_id) ?? '—'}
                  reportsToName={
                    detail.current_employment.reports_to_id !== null
                      ? (employeeNameById.get(detail.current_employment.reports_to_id) ?? '—')
                      : null
                  }
                />
              )}
            </div>

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader title="Edit name" level={2} />
              <NameForm
                initial={{
                  first_name: detail.first_name,
                  middle_name: detail.middle_name,
                  last_name: detail.last_name,
                  name_suffix: detail.name_suffix,
                }}
                submitting={updateMutation.isPending}
                submitError={updateMutation.isError}
                onSubmit={handleUpdateName}
              />
            </div>

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader title="Record employment change" level={2} />
              <EmploymentForm
                employeeId={detail.id}
                initial={{
                  office_id: detail.current_employment?.office_id ?? '',
                  department_id: detail.current_employment?.department_id ?? '',
                  employment_type: detail.current_employment?.employment_type ?? 'regular',
                  is_art82_exempt: detail.current_employment?.is_art82_exempt ?? false,
                  base_rate_cents: detail.current_employment?.base_rate_cents ?? 0,
                  reports_to_id: detail.current_employment?.reports_to_id ?? null,
                }}
                submitting={recordEmploymentMutation.isPending}
                submitError={recordEmploymentMutation.isError}
                onSubmit={handleRecordEmployment}
              />
            </div>

            {!detail.has_user ? (
              <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                <SectionHeader title="Provision login" level={2} />
                <ProvisionForm
                  initialName={detail.full_name}
                  submitting={provisionMutation.isPending}
                  submitError={provisionMutation.isError}
                  onSubmit={handleProvision}
                />
              </div>
            ) : null}

            <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
              <SectionHeader title="Office admin access" level={2} />
              {!detail.has_user ? (
                <InlineNotification kind="info" title="No login yet.">
                  Provision a login first to grant office-admin access.
                </InlineNotification>
              ) : (
                <HrOfficesForm
                  offices={offices.filter((office) => office.archived_at === null)}
                  initialOfficeIds={detail.hr_admin_office_ids}
                  roles={detail.roles}
                  submitting={setHrOfficesMutation.isPending}
                  submitError={setHrOfficesMutation.isError}
                  onSubmit={handleSetHrOffices}
                />
              )}
            </div>
          </>
        )}
      </div>
    </AppShell>
  )
}
