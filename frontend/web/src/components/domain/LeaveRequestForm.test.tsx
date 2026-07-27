import { fireEvent, render, screen } from '@testing-library/react'
import { beforeAll, describe, expect, it, vi } from 'vitest'

import type { LeaveBalance, LeaveType } from '@/lib/api'
import { ApiError } from '@/lib/api'

vi.mock('@/hooks/useLeaveTypes', () => ({
  useLeaveTypes: vi.fn(),
}))

vi.mock('@/hooks/useMyLeave', () => ({
  useMyLeave: vi.fn(),
}))

vi.mock('@/hooks/useSubmitLeaveRequest', () => ({
  useSubmitLeaveRequest: vi.fn(),
}))

import { useLeaveTypes } from '@/hooks/useLeaveTypes'
import { useMyLeave } from '@/hooks/useMyLeave'
import { useSubmitLeaveRequest } from '@/hooks/useSubmitLeaveRequest'

import { LeaveRequestForm } from './LeaveRequestForm'

const mockedUseLeaveTypes = vi.mocked(useLeaveTypes)
const mockedUseMyLeave = vi.mocked(useMyLeave)
const mockedUseSubmitLeaveRequest = vi.mocked(useSubmitLeaveRequest)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView. Radix
// Select's trigger/content call both when opening, so without these stubs the open
// interaction throws inside jsdom — a jsdom gap, not a real accessibility one. Mirrors
// CorrectionForm.test.tsx's own workaround.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

function leaveType(overrides: Partial<LeaveType> = {}): LeaveType {
  return {
    id: 'lt1',
    office_id: 'o1',
    name: 'Vacation Leave',
    code: 'VL',
    is_paid: true,
    requires_attachment: false,
    deducts_balance: true,
    is_cash_convertible: true,
    max_carryover_minutes: null,
    is_active: true,
    ...overrides,
  }
}

function balance(overrides: Partial<LeaveBalance> = {}): LeaveBalance {
  return {
    leave_type: leaveType(),
    balance_minutes: 2400,
    balance_readable: { days: 5, hours: 0, minutes: 0 },
    ...overrides,
  }
}

function stubLeaveTypes(data: LeaveType[] = [leaveType()]): void {
  mockedUseLeaveTypes.mockReturnValue({
    data,
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useLeaveTypes>)
}

function stubMyLeave(data: LeaveBalance[] = [balance()]): void {
  mockedUseMyLeave.mockReturnValue({
    data,
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useMyLeave>)
}

type MutationOverrides = Partial<{
  mutate: ReturnType<typeof vi.fn>
  isPending: boolean
  error: unknown
}>

function stubSubmit(overrides: MutationOverrides = {}): ReturnType<typeof vi.fn> {
  const mutate = overrides.mutate ?? vi.fn()
  mockedUseSubmitLeaveRequest.mockReturnValue({
    mutate,
    isPending: overrides.isPending ?? false,
    error: overrides.error ?? null,
  } as unknown as ReturnType<typeof useSubmitLeaveRequest>)
  return mutate
}

async function selectOption(label: string, optionName: string): Promise<void> {
  fireEvent.click(screen.getByLabelText(label))
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

describe('LeaveRequestForm', () => {
  it('selecting a leave type shows its current balance', async () => {
    stubLeaveTypes([leaveType({ id: 'lt1', name: 'Vacation Leave' }), leaveType({ id: 'lt2', name: 'Sick Leave' })])
    stubMyLeave([
      balance({ leave_type: leaveType({ id: 'lt1', name: 'Vacation Leave' }), balance_readable: { days: 5, hours: 0, minutes: 0 } }),
      balance({ leave_type: leaveType({ id: 'lt2', name: 'Sick Leave' }), balance_readable: { days: 2, hours: 0, minutes: 0 } }),
    ])
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    expect(screen.queryByText('Current balance:')).not.toBeInTheDocument()

    await selectOption('Leave type', 'Sick Leave')

    expect(screen.getByText('Current balance:')).toBeInTheDocument()
    expect(screen.getByText('2 days')).toBeInTheDocument()
  })

  it('only offers active leave types', async () => {
    stubLeaveTypes([
      leaveType({ id: 'lt1', name: 'Vacation Leave', is_active: true }),
      leaveType({ id: 'lt2', name: 'Retired Leave', is_active: false }),
    ])
    stubMyLeave([])
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    fireEvent.click(screen.getByLabelText('Leave type'))
    expect(await screen.findByRole('option', { name: 'Vacation Leave' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Retired Leave' })).not.toBeInTheDocument()
  })

  it('shows no balance line for a type with no balance row (e.g. a non-deducting type)', async () => {
    stubLeaveTypes([leaveType({ id: 'lt1', name: 'Vacation Leave' })])
    stubMyLeave([])
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    await selectOption('Leave type', 'Vacation Leave')

    expect(screen.queryByText('Current balance:')).not.toBeInTheDocument()
  })

  it('disables submit until leave type, dates, and note are all present', async () => {
    stubLeaveTypes()
    stubMyLeave()
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    await selectOption('Leave type', 'Vacation Leave')
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-12' } })
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Family trip' } })
    expect(screen.getByRole('button', { name: 'Submit' })).not.toBeDisabled()
  })

  it('disables submit when end date is before start date', async () => {
    stubLeaveTypes()
    stubMyLeave()
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    await selectOption('Leave type', 'Vacation Leave')
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-12' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Family trip' } })

    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()
  })

  it('shows a rough calendar-day cost estimate for the chosen range', async () => {
    stubLeaveTypes()
    stubMyLeave()
    stubSubmit()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    await selectOption('Leave type', 'Vacation Leave')
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-12' } })

    expect(screen.getByText(/~3 days/)).toBeInTheDocument()

    await selectOption('Day part', 'Half day')
    expect(screen.getByText(/~2.5 days/)).toBeInTheDocument()
  })

  it('submits with the right payload', async () => {
    const mutate = stubSubmit()
    stubLeaveTypes()
    stubMyLeave()

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    await selectOption('Leave type', 'Vacation Leave')
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-12' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Family trip' } })

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(mutate).toHaveBeenCalledWith(
      {
        leave_type_id: 'lt1',
        start_date: '2026-08-10',
        end_date: '2026-08-12',
        day_part: 'full',
        note: 'Family trip',
      },
      expect.anything(),
    )
  })

  it('calls onDone() when the mutation succeeds', async () => {
    const onDone = vi.fn()
    stubLeaveTypes()
    stubMyLeave()
    stubSubmit({
      mutate: vi.fn((_input: unknown, options?: { onSuccess?: () => void }) => {
        options?.onSuccess?.()
      }),
    })

    render(<LeaveRequestForm officeId="o1" onDone={onDone} />)

    await selectOption('Leave type', 'Vacation Leave')
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-12' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Family trip' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(onDone).toHaveBeenCalled()
  })

  it('surfaces an ApiError via InlineNotification', () => {
    stubLeaveTypes()
    stubMyLeave()
    stubSubmit({ error: new ApiError('validation_failed', 'The note field is required.', 422) })

    render(<LeaveRequestForm officeId="o1" onDone={vi.fn()} />)

    expect(screen.getByRole('alert')).toHaveTextContent('The note field is required.')
  })
})
