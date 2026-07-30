import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile } from '@/lib/api'

import { ProfileForm } from './ProfileForm'

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

function renderForm() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ProfileForm profile={profile} relationships={RELATIONSHIPS} categories={CATEGORIES} />
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

  // The field-name test. A snake_case/camelCase slip here is a silent 422 that no type
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

  // Rule::enum matches the BACKED VALUE exactly, so an option valued 'Male' is a 422.
  it('offers only backed enum values in the closed-set selects', () => {
    renderForm()

    const genderValues = Array.from(
      screen.getByLabelText('Gender').querySelectorAll('option'),
    ).map((option) => option.value).filter((value) => value !== '')

    expect(genderValues).toEqual(['male', 'female'])

    const bloodValues = Array.from(
      screen.getByLabelText('Blood Type').querySelectorAll('option'),
    ).map((option) => option.value).filter((value) => value !== '')

    expect(bloodValues).toEqual(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])
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

  it('sends an empty array when every dependent row is removed', async () => {
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
})
