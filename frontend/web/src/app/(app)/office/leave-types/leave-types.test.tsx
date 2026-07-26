import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { Employee, LeaveType, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/office/leave-types',
}))

vi.mock('@/hooks/useSession', () => ({
  useSession: vi.fn(),
}))

vi.mock('@/hooks/useLeaveTypes', () => ({
  useLeaveTypes: vi.fn(),
}))

vi.mock('@/hooks/useSaveLeaveType', () => ({
  useSaveLeaveType: vi.fn(),
}))

vi.mock('@/hooks/useSetLeaveDay', () => ({
  useSetLeaveDay: vi.fn(),
}))

vi.mock('@/hooks/useEmployees', () => ({
  useEmployees: vi.fn(),
}))

vi.mock('@/hooks/useGrantLeave', () => ({
  useGrantLeave: vi.fn(),
}))

import { useEmployees } from '@/hooks/useEmployees'
import { useGrantLeave } from '@/hooks/useGrantLeave'
import { useLeaveTypes } from '@/hooks/useLeaveTypes'
import { useSaveLeaveType } from '@/hooks/useSaveLeaveType'
import { useSession } from '@/hooks/useSession'
import { useSetLeaveDay } from '@/hooks/useSetLeaveDay'

import LeaveTypesPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUseLeaveTypes = vi.mocked(useLeaveTypes)
const mockedUseSaveLeaveType = vi.mocked(useSaveLeaveType)
const mockedUseSetLeaveDay = vi.mocked(useSetLeaveDay)
const mockedUseEmployees = vi.mocked(useEmployees)
const mockedUseGrantLeave = vi.mocked(useGrantLeave)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView — Radix
// Select's trigger/content call both when opening. See Select.test.tsx's own comment.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  vi.clearAllMocks()
})

function session(overrides: Partial<Session> = {}): Session {
  return {
    user: { id: 'u1', email: 'hr@x.com', name: 'HR' },
    employee: { id: 'e-hr', employee_no: 'E-000', current_office_id: 'o1', current_department_id: null },
    is_system_admin: false,
    has_reports: false,
    hr_offices: ['o1'],
    permissions: [],
    ...overrides,
  }
}

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

function employee(overrides: Partial<Employee> = {}): Employee {
  return {
    id: 'e1',
    employee_no: 'E-001',
    current_office_id: 'o1',
    current_department_id: null,
    current_reports_to_id: null,
    hired_at: null,
    ...overrides,
  }
}

function stubSession(overrides: Partial<Session> = {}): void {
  mockedUseSession.mockReturnValue({ session: session(overrides), isLoading: false, isAuthenticated: true })
}

function stubLeaveTypes(overrides: Partial<ReturnType<typeof useLeaveTypes>> = {}): void {
  mockedUseLeaveTypes.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useLeaveTypes>)
}

function stubSaveLeaveType(overrides: Partial<ReturnType<typeof useSaveLeaveType>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseSaveLeaveType.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useSaveLeaveType>)
  return mutate
}

function stubSetLeaveDay(overrides: Partial<ReturnType<typeof useSetLeaveDay>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseSetLeaveDay.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    isSuccess: false,
    ...overrides,
  } as unknown as ReturnType<typeof useSetLeaveDay>)
  return mutate
}

function stubEmployees(overrides: Partial<ReturnType<typeof useEmployees>> = {}): void {
  mockedUseEmployees.mockReturnValue({
    data: [employee()],
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useEmployees>)
}

function stubGrantLeave(overrides: Partial<ReturnType<typeof useGrantLeave>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseGrantLeave.mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useGrantLeave>)
  return mutate
}

function renderPage() {
  return render(
    <Providers>
      <LeaveTypesPage />
    </Providers>,
  )
}

describe('/office/leave-types — list', () => {
  it('shows a loading skeleton', () => {
    stubSession()
    stubLeaveTypes({ isLoading: true })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    expect(screen.getByRole('heading', { name: 'Leave types' })).toBeInTheDocument()
  })

  it('shows an empty state when the office has no leave types yet', () => {
    stubSession()
    stubLeaveTypes({ data: [] })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    expect(screen.getByText(/no leave types/i)).toBeInTheDocument()
  })

  it('renders a leave type with its flags as tags', () => {
    stubSession()
    stubLeaveTypes({ data: [leaveType({ name: 'Sick Leave', is_paid: true, is_cash_convertible: false })] })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    // "Sick Leave" also appears as the Grant form's (single, auto-selected) leave-type
    // option, so this is `getAllByText`, not `getByText` — the row itself is what matters.
    expect(screen.getAllByText('Sick Leave').length).toBeGreaterThan(0)
    expect(screen.getByText('Paid')).toBeInTheDocument()
    expect(screen.getByText('Deducts balance')).toBeInTheDocument()
    expect(screen.queryByText('Cash-convertible')).not.toBeInTheDocument()
  })
})

describe('/office/leave-types — create a leave type', () => {
  it('opening the form and submitting calls useSaveLeaveType with the office id and no id', async () => {
    stubSession()
    stubLeaveTypes({ data: [] })
    const mutate = stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'New leave type' }))

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Emergency Leave' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(mutate).toHaveBeenCalledWith(
        { body: expect.objectContaining({ name: 'Emergency Leave', office_id: 'o1' }) },
        expect.anything(),
      )
    })
  })

  it('a non-numeric "Max carryover (minutes)" blocks submit instead of silently saving null', () => {
    stubSession()
    stubLeaveTypes({ data: [] })
    const mutate = stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'New leave type' }))

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Emergency Leave' } })
    fireEvent.change(screen.getByLabelText('Max carryover (minutes)'), { target: { value: 'abc' } })

    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(mutate).not.toHaveBeenCalled()
  })
})

describe('/office/leave-types — leave day', () => {
  it('submitting the leave-day field calls useSetLeaveDay with the office id and the entered minutes', async () => {
    stubSession()
    stubLeaveTypes({ data: [] })
    stubSaveLeaveType()
    const mutate = stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    fireEvent.change(screen.getByLabelText('Leave day (minutes)'), { target: { value: '480' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save leave day' }))

    expect(mutate).toHaveBeenCalledWith({ office_id: 'o1', minutes_per_leave_day: 480 })
  })
})

describe('/office/leave-types — grant form', () => {
  it('submit is disabled until employee, leave type, amount, unit, and reason are all set', () => {
    stubSession()
    stubLeaveTypes({ data: [leaveType()] })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees({ data: [employee({ id: 'e1', employee_no: 'E-001' })] })
    stubGrantLeave()

    renderPage()

    expect(screen.getByRole('button', { name: 'Grant leave' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '2' } })
    expect(screen.getByRole('button', { name: 'Grant leave' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Approved by manager' } })
    expect(screen.getByRole('button', { name: 'Grant leave' })).not.toBeDisabled()
  })

  it('offers all four LeaveUnitName options in the unit select', async () => {
    stubSession()
    stubLeaveTypes({ data: [leaveType()] })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees()
    stubGrantLeave()

    renderPage()

    fireEvent.click(screen.getByLabelText('Unit'))

    expect(await screen.findByRole('option', { name: 'Day' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Half shift' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Hour' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Minute' })).toBeInTheDocument()
  })

  it('submitting calls useGrantLeave with the chosen fields and shows a success notification', async () => {
    stubSession()
    stubLeaveTypes({ data: [leaveType({ id: 'lt1', name: 'Vacation Leave' })] })
    stubSaveLeaveType()
    stubSetLeaveDay()
    stubEmployees({ data: [employee({ id: 'e1', employee_no: 'E-001' })] })

    let onSuccessCb: (() => void) | undefined
    const mutate = vi.fn((_input: unknown, opts?: { onSuccess?: () => void }) => {
      onSuccessCb = opts?.onSuccess
    })
    stubGrantLeave({ mutate: mutate as unknown as ReturnType<typeof useGrantLeave>['mutate'] })

    renderPage()

    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Manual credit' } })
    fireEvent.click(screen.getByRole('button', { name: 'Grant leave' }))

    expect(mutate).toHaveBeenCalledWith(
      { employee_id: 'e1', leave_type_id: 'lt1', amount: 3, unit: 'day', reason: 'Manual credit' },
      expect.anything(),
    )

    act(() => {
      onSuccessCb?.()
    })

    await waitFor(() => {
      expect(screen.getByText('Leave granted.')).toBeInTheDocument()
    })
  })
})

describe('/office/leave-types — grant form resets on office switch', () => {
  it("switching the office remounts the grant form with the NEW office's first employee/leave-type — no stale cross-office ids survive the switch", async () => {
    stubSession({ hr_offices: ['o1', 'o2'] })

    // /employees is one shared, non-office-scoped list (see useEmployees's own doc
    // comment) — a stale id from the previous office is still a *valid* id, just for the
    // wrong office, so this is the exact condition that let a stale selection slip past
    // `hasInvalidInput` before the fix.
    mockedUseLeaveTypes.mockImplementation(
      (officeId) =>
        ({
          data:
            officeId === 'o1'
              ? [leaveType({ id: 'lt-o1', name: 'O1 Leave' })]
              : officeId === 'o2'
                ? [leaveType({ id: 'lt-o2', name: 'O2 Leave' })]
                : [],
          isLoading: false,
          isError: false,
        }) as unknown as ReturnType<typeof useLeaveTypes>,
    )
    stubSaveLeaveType()
    stubSetLeaveDay()
    mockedUseEmployees.mockReturnValue({
      data: [
        employee({ id: 'e-o1', employee_no: 'E-O1', current_office_id: 'o1' }),
        employee({ id: 'e-o2', employee_no: 'E-O2', current_office_id: 'o2' }),
      ],
      isLoading: false,
      isError: false,
    } as unknown as ReturnType<typeof useEmployees>)

    const mutate = vi.fn()
    stubGrantLeave({ mutate: mutate as unknown as ReturnType<typeof useGrantLeave>['mutate'] })

    renderPage()

    // Defaults to o1 (the session's first hr_offices entry) — switch to o2.
    fireEvent.click(screen.getByLabelText('Office'))
    fireEvent.click(await screen.findByRole('option', { name: 'o2' }))

    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Post-switch grant' } })
    fireEvent.click(screen.getByRole('button', { name: 'Grant leave' }))

    expect(mutate).toHaveBeenCalledWith(
      { employee_id: 'e-o2', leave_type_id: 'lt-o2', amount: 1, unit: 'day', reason: 'Post-switch grant' },
      expect.anything(),
    )
  })
})
