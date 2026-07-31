import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile } from '@/lib/api'

import { ProfileSections } from './ProfileSections'

function buildProfile(overrides: Partial<EmployeeProfile> = {}): EmployeeProfile {
  return {
    employee_id: 'emp-1',
    employee_no: '2506366',
    full_name: 'Ken Daryl Austero Perez',
    details: {
      salutation: 'Mr.', first_name: 'Ken Daryl', middle_name: 'Austero',
      last_name: 'Perez', name_suffix: null, nickname: 'KENPE',
    },
    contact: {
      home_address: 'Tagles Compound, Putatan, Muntinlupa City',
      personal_email: null, phone: null, fax: null,
      mobile: '09166229187', emergency_contact: null,
    },
    personal: {
      gender: 'male', birth_date: '2002-01-23', age: 24, birthplace: null,
      marital_status: 'single', citizenship: 'Filipino',
      religion: 'Roman Catholic', blood_type: null,
    },
    assignment: {
      designation: 'Backend Software Developer', business_unit: 'MIS',
      reports_to: null, employment_status: 'regular', location: 'Cebu',
      region: 'VII', labor_type: 'direct', hired_at: '2025-06-16', work_shift: null,
    },
    dependents: [],
    identifications: [],
    ...overrides,
  }
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('ProfileSections — DefinitionList key defect (Task 13)', () => {
  // Two children — a real, likely shape: `DefinitionList` used to key each dependent row
  // by its relationship LABEL alone (`d.relationship ?? 'Dependent'`), which collides the
  // instant an employee has two dependents with the same relationship. React logs that
  // collision as a "same key" console.error; asserting it is silent is what actually
  // catches a regression back to `key={label}` — both rows still render either way, since
  // a fresh mount doesn't drop siblings just because their keys collide.
  it('gives each dependent row a stable key even when two share the same relationship label', () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

    // 'child', not 'Child', for `relationship`: EmployeeProfileResource sends the
    // relationship's CODE there (`$dependent->relationship?->code`), not its description —
    // a fixture using the capitalised description for `relationship` encodes the same wrong
    // mental model that produced the relationship-matching bug in ProfileForm's dependents
    // form (Task 14 fix round 1). `relationship_label` carries the description ('Child')
    // and is what the read view actually renders (Task 16).
    const profile = buildProfile({
      dependents: [
        { id: 'dep-1', name: 'Anna Perez', relationship: 'child', relationship_label: 'Child', birth_date: '2015-01-01' },
        { id: 'dep-2', name: 'Ben Perez', relationship: 'child', relationship_label: 'Child', birth_date: '2017-01-01' },
      ],
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('Anna Perez · 2015-01-01')).toBeInTheDocument()
    expect(screen.getByText('Ben Perez · 2017-01-01')).toBeInTheDocument()
    expect(screen.getAllByText('Child')).toHaveLength(2)

    const sameKeyWarning = errorSpy.mock.calls.some((args) =>
      args.some((arg) => typeof arg === 'string' && arg.includes('same key')),
    )
    expect(sameKeyWarning).toBe(false)
  })

  it('gives each identification a stable key even when two share the same category label', () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

    const profile = buildProfile({
      identifications: [
        {
          id: 'id-1', category_code: null, category_name: null,
          number: '111', issued_on: null, expires_on: null, notes: null, has_scan: false,
        },
        {
          id: 'id-2', category_code: null, category_name: null,
          number: '222', issued_on: null, expires_on: null, notes: null, has_scan: false,
        },
      ],
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('111')).toBeInTheDocument()
    expect(screen.getByText('222')).toBeInTheDocument()

    const sameKeyWarning = errorSpy.mock.calls.some((args) =>
      args.some((arg) => typeof arg === 'string' && arg.includes('same key')),
    )
    expect(sameKeyWarning).toBe(false)
  })
})

describe('ProfileSections — display labels for backed values (Task 16)', () => {
  // The defect: the read view used to print the wire values straight through — `gender:
  // 'male'` rendered as literally "male", not "Male", and a dependent's relationship CODE
  // ('spouse') rendered instead of its catalog description ('Spouse'). ProfileForm's Select
  // showed the human labels for the exact same fields on the exact same screen, so the two
  // disagreed about what a value is called — the thing ProfileForm's own doc comment says
  // must never happen. This asserts the read view now goes through the same
  // GENDER_OPTIONS/MARITAL_STATUS_OPTIONS label mapping ProfileForm's Select uses, and
  // renders a dependent's `relationship_label`, not its `relationship` code.
  it('renders human labels for gender, marital status, and a dependent relationship — not the raw wire values', () => {
    const profile = buildProfile({
      personal: {
        gender: 'male', birth_date: '2002-01-23', age: 24, birthplace: null,
        marital_status: 'single', citizenship: 'Filipino',
        religion: 'Roman Catholic', blood_type: null,
      },
      dependents: [
        { id: 'dep-1', name: 'Maria Perez', relationship: 'spouse', relationship_label: 'Spouse', birth_date: null },
      ],
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('Male')).toBeInTheDocument()
    expect(screen.getByText('Single')).toBeInTheDocument()
    expect(screen.getByText('Spouse')).toBeInTheDocument()

    expect(screen.queryByText('male')).not.toBeInTheDocument()
    expect(screen.queryByText('single')).not.toBeInTheDocument()
    expect(screen.queryByText('spouse')).not.toBeInTheDocument()
  })

  // Blood type's label and value happen to be identical ('O+'), so it looks unchanged
  // either way — routed through the same `labelForOption` mechanism as gender/marital
  // status anyway, so all three closed sets share one code path rather than two carrying
  // the label logic and one silently exempt from it.
  it('renders blood type through the same option-label mechanism, even though the label equals the value', () => {
    const profile = buildProfile({
      personal: {
        gender: 'female', birth_date: '2002-01-23', age: 24, birthplace: null,
        marital_status: 'married', citizenship: 'Filipino',
        religion: 'Roman Catholic', blood_type: 'O+',
      },
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('O+')).toBeInTheDocument()
  })

  // M10a final-fixes round: labor_type is a Rule::enum()-backed value on the wire
  // ('direct'/'indirect') just like gender/marital_status/blood_type, and used to render
  // raw. employment_status looks like the same shape but is validated backend-side as a
  // free string (RecordEmploymentRequest), not a backed enum — there is no closed set to
  // label it against, so it stays raw deliberately.
  it('renders labor type as a human label, but leaves employment status raw (no backend-enforced closed set)', () => {
    const profile = buildProfile({
      assignment: {
        designation: 'Backend Software Developer', business_unit: 'MIS',
        reports_to: null, employment_status: 'regular', location: 'Cebu',
        region: 'VII', labor_type: 'indirect', hired_at: '2025-06-16', work_shift: null,
      },
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('Indirect')).toBeInTheDocument()
    expect(screen.queryByText('indirect')).not.toBeInTheDocument()
    // employment_status stays exactly what the wire sent.
    expect(screen.getByText('regular')).toBeInTheDocument()
  })

  // A value with no matching option (stale data from a removed catalog entry, or simply
  // absent) must still render something rather than going blank.
  it('falls back to the raw value when a dependent has no relationship_label', () => {
    const profile = buildProfile({
      dependents: [
        { id: 'dep-1', name: 'Maria Perez', relationship: 'unknown_code', relationship_label: null, birth_date: null },
      ],
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('unknown_code')).toBeInTheDocument()
  })
})
