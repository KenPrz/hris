'use client'

/**
 * The create-employee wizard (M8b) — sysadmin-gated onboarding, a dedicated
 * `/admin/employees/new` route (the pattern the M8a admin screens set: a full page under
 * the Admin nav group, not a dialog, because this is a three-step flow with its own state).
 *
 * Three steps, each owning its own field state; the `<Wizard>` owns only navigation. The
 * organization_id `POST /admin/employees` needs is DERIVED from the chosen office's
 * `organization_id`, never a separate picker — an office already belongs to exactly one
 * organization. Base rate is entered in pesos and converted to integer centavos (×100) on
 * submit; the money invariant is integer centavos everywhere. Step 3 (login) is optional:
 * a blank email/password leaves the employee without a user account, which is a valid state.
 *
 * onComplete creates the employee, THEN — only if step 3 supplied both email and password —
 * provisions the user against the new employee's id. A failure in either surfaces through an
 * InlineNotification and blocks navigation; a success routes to the roster.
 */

import { useState } from 'react'
import { useRouter } from 'next/navigation'

import type { AdminEmployeeCreateInput, EmploymentType } from '@/lib/api'
import { useAdminEmployees, useCreateEmployee, useProvisionUser } from '@/hooks/useAdminEmployees'
import { useDepartments, useOffices } from '@/hooks/useAdminOrgTree'
import { useSession } from '@/hooks/useSession'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { TextInput } from '@/components/ui/TextInput'
import { Wizard } from '@/components/ui/Wizard'
import type { WizardStep } from '@/components/ui/Wizard'

const EMPLOYMENT_TYPE_OPTIONS: SelectOption[] = [
  { value: 'regular', label: 'Regular' },
  { value: 'probationary', label: 'Probationary' },
  { value: 'contractual', label: 'Contractual' },
]

/** Blank or non-numeric → invalid (blocks submit rather than serializing NaN). A valid
 * peso amount becomes integer centavos: `1234.5` → `123450`. */
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

/** A token-styled checkbox — mirrors the org-tree screens' raw `<input type="checkbox">`
 * treatment rather than inventing a tier-1 component. */
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

export default function NewEmployeePage() {
  const router = useRouter()
  const { session } = useSession()
  const isSysAdmin = session?.is_system_admin ?? false

  // Step 1 — identity.
  const [employeeNo, setEmployeeNo] = useState('')
  const [firstName, setFirstName] = useState('')
  const [middleName, setMiddleName] = useState('')
  const [lastName, setLastName] = useState('')
  const [nameSuffix, setNameSuffix] = useState('')
  const [hiredAt, setHiredAt] = useState('')

  // Step 2 — employment.
  const [officeId, setOfficeId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [employmentType, setEmploymentType] = useState<EmploymentType>('regular')
  const [isArt82Exempt, setIsArt82Exempt] = useState(false)
  const [baseRatePesos, setBaseRatePesos] = useState('')
  const [reportsToId, setReportsToId] = useState('')
  // Empty means "follow the hire date"; the field renders that default without a mount effect.
  const [effectiveFrom, setEffectiveFrom] = useState('')

  // Step 3 — login (optional). `null` name means "untouched": show the derived full name
  // until the operator edits it.
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loginName, setLoginName] = useState<string | null>(null)

  const [submitError, setSubmitError] = useState<string | null>(null)

  const officesQuery = useOffices()
  const departmentsQuery = useDepartments(officeId === '' ? undefined : { office: officeId })
  const employeesQuery = useAdminEmployees(officeId === '' ? undefined : { office: officeId })
  const createEmployee = useCreateEmployee()
  const provisionUser = useProvisionUser()

  const offices = (officesQuery.data ?? []).filter((office) => office.archived_at === null)
  const departments = (departmentsQuery.data ?? []).filter(
    (department) => department.archived_at === null && department.office_id === officeId,
  )
  const employees = employeesQuery.data ?? []
  const chosenOffice = offices.find((office) => office.id === officeId)

  const officeOptions: SelectOption[] = offices.map((office) => ({ value: office.id, label: office.name }))
  const departmentOptions: SelectOption[] = departments.map((department) => ({
    value: department.id,
    label: department.name,
  }))
  const reportsToOptions: SelectOption[] = [
    { value: '', label: 'No manager' },
    ...employees.map((employee) => ({ value: employee.id, label: employee.full_name })),
  ]

  const fullName = [firstName, middleName, lastName, nameSuffix]
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .join(' ')
  const name = loginName ?? fullName
  const effectiveFromValue = effectiveFrom === '' ? hiredAt : effectiveFrom

  const baseRate = pesosToCentavos(baseRatePesos)

  const identityValid =
    employeeNo.trim() !== '' && firstName.trim() !== '' && lastName.trim() !== '' && hiredAt !== ''
  // employment_type always holds one of the closed EmploymentType values (never blank), so
  // it needs no explicit "is set" check — the Select can't produce an empty selection.
  const employmentValid =
    officeId !== '' && departmentId !== '' && !baseRate.invalid && effectiveFromValue !== ''

  async function handleComplete(): Promise<void> {
    setSubmitError(null)

    if (chosenOffice === undefined || baseRate.cents === null) {
      setSubmitError('Complete the employment step before finishing.')
      return
    }

    const body: AdminEmployeeCreateInput = {
      employee_no: employeeNo.trim(),
      organization_id: chosenOffice.organization_id,
      hired_at: hiredAt,
      first_name: firstName.trim(),
      middle_name: middleName.trim() === '' ? null : middleName.trim(),
      last_name: lastName.trim(),
      name_suffix: nameSuffix.trim() === '' ? null : nameSuffix.trim(),
      employment: {
        effective_from: effectiveFromValue,
        office_id: officeId,
        department_id: departmentId,
        reports_to_id: reportsToId === '' ? null : reportsToId,
        employment_type: employmentType,
        is_art82_exempt: isArt82Exempt,
        base_rate_cents: baseRate.cents,
      },
    }

    try {
      const created = await createEmployee.mutateAsync(body)

      if (email.trim() !== '' && password !== '') {
        await provisionUser.mutateAsync({ id: created.id, body: { email: email.trim(), password, name } })
      }

      router.push('/admin/employees')
    } catch {
      setSubmitError('Could not create the employee. Check the details and try again.')
    }
  }

  const steps: WizardStep[] = [
    {
      id: 'identity',
      title: 'Identity',
      isValid: identityValid,
      render: () => (
        <div className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
          <TextInput id="employee-no" label="Employee number" value={employeeNo} onChange={setEmployeeNo} required />
          <TextInput id="first-name" label="First name" value={firstName} onChange={setFirstName} required />
          <TextInput id="middle-name" label="Middle name" value={middleName} onChange={setMiddleName} />
          <TextInput id="last-name" label="Last name" value={lastName} onChange={setLastName} required />
          <TextInput id="name-suffix" label="Suffix" value={nameSuffix} onChange={setNameSuffix} />
          <TextInput id="hired-at" label="Hire date" type="date" value={hiredAt} onChange={setHiredAt} required />
        </div>
      ),
    },
    {
      id: 'employment',
      title: 'Employment',
      isValid: employmentValid,
      render: () => (
        <div className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
          <Select
            id="office"
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
            id="department"
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
            id="is-art82-exempt"
            label="Art. 82 exempt (managerial / field personnel — no overtime, night diff, or holiday premium)"
            checked={isArt82Exempt}
            onChange={setIsArt82Exempt}
          />
          <TextInput
            id="base-rate"
            label="Base rate (₱)"
            value={baseRatePesos}
            onChange={setBaseRatePesos}
            error={baseRate.invalid && baseRatePesos.trim() !== '' ? 'Enter a non-negative amount.' : undefined}
            required
          />
          <Select
            id="reports-to"
            label="Reports to"
            value={reportsToId}
            onChange={setReportsToId}
            options={reportsToOptions}
          />
          <TextInput
            id="effective-from"
            label="Effective from"
            type="date"
            value={effectiveFromValue}
            onChange={setEffectiveFrom}
            required
          />
        </div>
      ),
    },
    {
      id: 'login',
      title: 'Login (optional)',
      // No isValid — an untouched login step is a valid outcome (no user account yet).
      render: () => (
        <div className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
          <p style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            Leave these blank to onboard the employee without a login. You can provision one later.
          </p>
          <TextInput id="login-email" label="Email" type="email" value={email} onChange={setEmail} autoComplete="off" />
          <TextInput
            id="login-password"
            label="Password"
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="new-password"
          />
          <TextInput id="login-name" label="Display name" value={name} onChange={setLoginName} />
        </div>
      ),
    },
  ]

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Admin" title="New employee" level={1} />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't create employees.">
            Employee onboarding is a system-admin-only screen.
          </InlineNotification>
        ) : (
          <>
            {submitError !== null ? (
              <InlineNotification kind="error" title="That didn't save.">
                {submitError}
              </InlineNotification>
            ) : null}

            <Wizard steps={steps} onComplete={handleComplete} />
          </>
        )}
      </div>
    </AppShell>
  )
}
