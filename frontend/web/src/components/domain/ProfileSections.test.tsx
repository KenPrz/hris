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

    // 'child', not 'Child': EmployeeProfileResource sends the relationship's CODE
    // (`$dependent->relationship?->code`), not its description — a fixture using the
    // capitalised description encodes the same wrong mental model that produced the
    // relationship-matching bug in ProfileForm's dependents form (Task 14 fix round 1).
    const profile = buildProfile({
      dependents: [
        { id: 'dep-1', name: 'Anna Perez', relationship: 'child', birth_date: '2015-01-01' },
        { id: 'dep-2', name: 'Ben Perez', relationship: 'child', birth_date: '2017-01-01' },
      ],
    })

    render(<ProfileSections profile={profile} />)

    expect(screen.getByText('Anna Perez · 2015-01-01')).toBeInTheDocument()
    expect(screen.getByText('Ben Perez · 2017-01-01')).toBeInTheDocument()
    expect(screen.getAllByText('child')).toHaveLength(2)

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
