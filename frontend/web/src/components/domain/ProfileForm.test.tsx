import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile, ProfileCatalog } from '@/lib/api'

import { BLOOD_TYPE_OPTIONS, GENDER_OPTIONS, MARITAL_STATUS_OPTIONS, ProfileForm } from './ProfileForm'

const profile: EmployeeProfile = {
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
}

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: {
    profile: {
      save: vi.fn(),
      saveDependents: vi.fn(),
      saveIdentification: vi.fn(),
      deleteIdentification: vi.fn(),
    },
  },
}))

const { api } = await import('@/lib/api')

const RELATIONSHIPS = [{ id: 'rel-1', code: 'spouse', description: 'Spouse' }]
const CATEGORIES = [{ id: 'cat-1', code: 'TIN', name: 'TIN' }]

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView. Radix
// Select's trigger/content call both when opening, so without these stubs the open
// interaction throws inside jsdom — a jsdom gap, not a real accessibility one. Mirrors
// CorrectionForm.test.tsx / LeaveRequestForm.test.tsx's own workaround.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

function renderForm(
  overrides: { profile?: EmployeeProfile; relationships?: ProfileCatalog['relationships'] } = {},
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ProfileForm
        profile={overrides.profile ?? profile}
        relationships={overrides.relationships ?? RELATIONSHIPS}
        categories={CATEGORIES}
      />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(api.profile.save).mockReset().mockResolvedValue(profile)
  vi.mocked(api.profile.saveDependents).mockReset().mockResolvedValue(profile)
  vi.mocked(api.profile.saveIdentification).mockReset().mockResolvedValue(profile)
})

describe('ProfileForm', () => {
  it('pre-fills every editable field from the profile', () => {
    renderForm()

    expect(screen.getByLabelText('Nickname')).toHaveValue('KENPE')
    expect(screen.getByLabelText('Home')).toHaveValue('Tagles Compound, Putatan, Muntinlupa City')
    expect(screen.getByLabelText('Mobile')).toHaveValue('09166229187')
    expect(screen.getByLabelText('Birthday')).toHaveValue('2002-01-23')
    expect(screen.getByLabelText('Religion')).toHaveValue('Roman Catholic')
  })

  // The field-name test. A snake_case/camelCase slip here is a silent 400 that no type
  // check catches, because the body is serialised as a plain object.
  it('submits the exact snake_case field names the backend validates', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.clear(screen.getByLabelText('Nickname'))
    await user.type(screen.getByLabelText('Nickname'), 'KEN')
    await user.click(screen.getByRole('button', { name: /save profile/i }))

    await waitFor(() => expect(api.profile.save).toHaveBeenCalledTimes(1))

    expect(api.profile.save).toHaveBeenCalledWith('emp-1', expect.objectContaining({
      nickname: 'KEN',
      home_address: 'Tagles Compound, Putatan, Muntinlupa City',
      personal_email: null,
      marital_status: 'single',
      birth_date: '2002-01-23',
      blood_type: null,
    }))
  })

  // Rule::enum matches the BACKED VALUE exactly, so an option valued 'Male' is a 400.
  // Asserts the exported constants directly rather than introspecting the DOM: gender/
  // marital-status/blood-type render through the tier-1 Radix-backed `Select`, whose
  // `Select.Item`s are ARIA-only `div`s with no `<option>` element to query for — the
  // constants ARE the set of values `Select` is given to render, so asserting them proves
  // the same invariant without depending on `Select`'s internal DOM shape.
  it('the closed-set selects are built from exactly the backend-backed enum values', () => {
    expect(GENDER_OPTIONS.map((option) => option.value)).toEqual(['male', 'female'])
    expect(MARITAL_STATUS_OPTIONS.map((option) => option.value)).toEqual([
      'single', 'married', 'widowed', 'separated', 'annulled',
    ])
    expect(BLOOD_TYPE_OPTIONS.map((option) => option.value)).toEqual([
      'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-',
    ])
  })

  // The constant-based test above proves the VALUE SET; this proves the WIRE PATH — that
  // picking an option in the real Select actually flows through onChange/state/submit and
  // reaches the API with the backed value, not just that the constant array is correct.
  // Switches to 'Female' (not the profile's existing 'male') so the assertion can only pass
  // if the click genuinely changed the field.
  it('selecting a gender via the Select reaches the wire as its backed value', async () => {
    renderForm()

    fireEvent.click(screen.getByLabelText('Gender'))
    fireEvent.click(await screen.findByRole('option', { name: 'Female' }))
    fireEvent.click(screen.getByRole('button', { name: /save profile/i }))

    await waitFor(() => expect(api.profile.save).toHaveBeenCalledTimes(1))

    expect(api.profile.save).toHaveBeenCalledWith('emp-1', expect.objectContaining({ gender: 'female' }))
  })

  // The relationship-code regression. `ProfileDependent.relationship` is the wire CODE
  // (verified against EmployeeProfileResource — `'relationship' => $dependent->relationship
  // ?->code`), e.g. 'spouse', never the catalog description ('Spouse'). Matching on
  // `description` instead never matches (case differs for every seeded relationship) and
  // silently falls back to `relationships[0]` — 'child' once real data is `orderBy('code')`.
  // Because saving dependents PUTs the whole array, that silent fallback would rewrite this
  // untouched spouse to a child. `RELATIONSHIPS_ORDERED_CHILD_FIRST[0]` is deliberately
  // 'child', matching production ordering, so a reintroduced description-match bug lands on
  // a WRONG id here rather than coincidentally the right one.
  it('preserves an existing dependent relationship (matched by CODE) when saving untouched', async () => {
    const user = userEvent.setup()
    const RELATIONSHIPS_ORDERED_CHILD_FIRST = [
      { id: 'rel-child', code: 'child', description: 'Child' },
      { id: 'rel-spouse', code: 'spouse', description: 'Spouse' },
    ]
    const profileWithSpouse: EmployeeProfile = {
      ...profile,
      dependents: [{ id: 'dep-1', name: 'Maria Perez', relationship: 'spouse', birth_date: null }],
    }

    renderForm({ profile: profileWithSpouse, relationships: RELATIONSHIPS_ORDERED_CHILD_FIRST })
    await user.click(screen.getByRole('button', { name: /save dependents/i }))

    await waitFor(() => expect(api.profile.saveDependents).toHaveBeenCalledTimes(1))

    expect(api.profile.saveDependents).toHaveBeenCalledWith('emp-1', [
      expect.objectContaining({ name: 'Maria Perez', relationship_id: 'rel-spouse' }),
    ])
  })

  it('sends the resulting array after adding and removing a dependent row', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.click(screen.getByRole('button', { name: /add dependent/i }))
    await user.click(screen.getByRole('button', { name: /add dependent/i }))
    await user.type(screen.getAllByLabelText('Dependent name')[0], 'Maria Perez')
    await user.click(screen.getAllByRole('button', { name: /remove dependent/i })[1])
    await user.click(screen.getByRole('button', { name: /save dependents/i }))

    await waitFor(() => expect(api.profile.saveDependents).toHaveBeenCalledTimes(1))

    expect(api.profile.saveDependents).toHaveBeenCalledWith('emp-1', [
      expect.objectContaining({ name: 'Maria Perez', relationship_id: 'rel-1' }),
    ])
  })

  // Renamed from "...when every dependent row is removed": this test never adds or removes
  // a row — it asserts what an employee with ZERO dependents to begin with sends. `dependents`
  // is `present`-validated on the backend (an empty array is a real instruction, "no
  // dependents"), so the field must still be sent, not omitted.
  it('sends an empty array — not an omitted field — when there are no dependent rows to save', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.click(screen.getByRole('button', { name: /save dependents/i }))

    await waitFor(() => expect(api.profile.saveDependents).toHaveBeenCalledWith('emp-1', []))
  })

  it('attaches the chosen scan file to the identification save', async () => {
    const user = userEvent.setup()
    renderForm()

    const file = new File(['pdf bytes'], 'tin.pdf', { type: 'application/pdf' })

    await user.type(screen.getByLabelText('ID number'), '653536955000')
    await user.upload(screen.getByLabelText('Scan'), file)
    await user.click(screen.getByRole('button', { name: /save identification/i }))

    await waitFor(() => expect(api.profile.saveIdentification).toHaveBeenCalledTimes(1))

    expect(api.profile.saveIdentification).toHaveBeenCalledWith('emp-1', expect.objectContaining({
      category_id: 'cat-1',
      number: '653536955000',
      scan: file,
    }))
  })

  // The must-not-clear-an-existing-scan rule (M10a spec). Saving with NO file chosen must
  // never send a `scan` key at all — `api.profile.saveIdentification` only appends `scan`
  // to the FormData when the key is present, and the backend's SaveEmployeeIdentification
  // action leaves the existing media alone precisely when the field is absent. Changing
  // `...(scan !== null ? { scan } : {})` to an unconditional `scan` key would pass every
  // OTHER test in this file, since none of them assert on absence — this is the one that
  // would catch it.
  it('does not send a scan key when no file is chosen, so an existing scan is preserved', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.type(screen.getByLabelText('ID number'), '653536955000')
    await user.click(screen.getByRole('button', { name: /save identification/i }))

    await waitFor(() => expect(api.profile.saveIdentification).toHaveBeenCalledTimes(1))

    const fields = vi.mocked(api.profile.saveIdentification).mock.calls[0]?.[1]
    expect(fields).not.toHaveProperty('scan')
  })
})
