import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { LeaveBalance, LeaveType } from '@/lib/api'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/me/leave',
}))

vi.mock('@/hooks/useMyLeave', () => ({
  useMyLeave: vi.fn(),
}))

// Only exercised by the "Request leave" tests below, which open `LeaveRequestForm` — the
// other tests never click the button, so these two hooks are never actually called, but
// they still need a mock in place: `LeaveRequestForm` is mounted (conditionally) in the
// SAME render tree, and an unmocked TanStack Query hook would issue a real fetch the
// `stubSessionFetch` mock below doesn't handle.
vi.mock('@/hooks/useLeaveTypes', () => ({
  useLeaveTypes: vi.fn(),
}))

vi.mock('@/hooks/useSubmitLeaveRequest', () => ({
  useSubmitLeaveRequest: vi.fn(),
}))

import { useLeaveTypes } from '@/hooks/useLeaveTypes'
import { useMyLeave } from '@/hooks/useMyLeave'
import { useSubmitLeaveRequest } from '@/hooks/useSubmitLeaveRequest'

import MyLeavePage from './page'

const mockedUseMyLeave = vi.mocked(useMyLeave)
const mockedUseLeaveTypes = vi.mocked(useLeaveTypes)
const mockedUseSubmitLeaveRequest = vi.mocked(useSubmitLeaveRequest)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView, which
// Radix Select's trigger/content call when opening — see CorrectionForm.test.tsx's own
// workaround for the same gap.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  vi.clearAllMocks()
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

function stubMyLeave(overrides: Partial<ReturnType<typeof useMyLeave>> = {}): void {
  mockedUseMyLeave.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useMyLeave>)
}

function stubSessionFetch(options: { employee?: null } = {}): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      if (url === '/api/v1/me' && method === 'GET') {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              user: { id: 'u1', email: 'a@b.com', name: 'A' },
              employee:
                options.employee === null
                  ? null
                  : { id: 'e1', employee_no: 'E-001', current_office_id: 'o1', current_department_id: null },
              is_system_admin: false,
              has_reports: false,
              hr_offices: [],
              permissions: [],
            },
          }),
        }
      }
      throw new Error(`Unhandled fetch in test: ${method} ${url}`)
    }),
  )
}

function stubLeaveTypesAndSubmit(): void {
  mockedUseLeaveTypes.mockReturnValue({
    data: [leaveType()],
    isLoading: false,
    isError: false,
  } as unknown as ReturnType<typeof useLeaveTypes>)

  mockedUseSubmitLeaveRequest.mockReturnValue({
    mutate: vi.fn(),
    isPending: false,
    error: null,
  } as unknown as ReturnType<typeof useSubmitLeaveRequest>)
}

function renderPage(options: { employee?: null } = {}) {
  setToken('sekrit')
  stubSessionFetch(options)
  return render(
    <Providers>
      <MyLeavePage />
    </Providers>,
  )
}

describe('/me/leave', () => {
  it('shows a loading skeleton', async () => {
    stubMyLeave({ isLoading: true })

    renderPage()

    await screen.findByRole('heading', { name: 'Leave' })
  })

  it('shows an empty state when there are no leave types configured, without the misleading not-an-employee clause', async () => {
    stubMyLeave({ data: [] })

    renderPage()

    expect(await screen.findByText(/no leave balances/i)).toBeInTheDocument()
    // NotAnEmployee is a 422 that renders through the error branch above, never this
    // empty state — the copy must not imply this case reaches here.
    expect(screen.queryByText(/employee record/i)).not.toBeInTheDocument()
  })

  it('shows an error notification when the balances fail to load', async () => {
    stubMyLeave({ isError: true })

    renderPage()

    expect(await screen.findByText(/couldn.t load/i)).toBeInTheDocument()
  })

  it('renders one row per leave type with its readable balance and flags', async () => {
    stubMyLeave({
      data: [
        balance({
          leave_type: leaveType({ name: 'Vacation Leave', is_paid: true, is_cash_convertible: true }),
          balance_readable: { days: 5, hours: 0, minutes: 0 },
        }),
        balance({
          leave_type: leaveType({ id: 'lt2', name: 'Sick Leave', is_paid: false, is_cash_convertible: false }),
          balance_readable: { days: 0, hours: 4, minutes: 0 },
        }),
      ],
    })

    renderPage()

    expect(await screen.findByText('Vacation Leave')).toBeInTheDocument()
    expect(screen.getByText('5 days')).toBeInTheDocument()
    expect(screen.getByText('Sick Leave')).toBeInTheDocument()
    expect(screen.getByText('4 hr')).toBeInTheDocument()

    const paidTags = screen.getAllByText('Paid')
    expect(paidTags).toHaveLength(1)
    expect(screen.getByText('Unpaid')).toBeInTheDocument()
    expect(screen.getByText('Cash-convertible')).toBeInTheDocument()
  })

  it('shows a "Request leave" button that opens LeaveRequestForm, and Cancel closes it again', async () => {
    stubMyLeave({ data: [balance()] })
    stubLeaveTypesAndSubmit()

    renderPage()

    const requestButton = await screen.findByRole('button', { name: 'Request leave' })
    fireEvent.click(requestButton)

    expect(screen.getByLabelText('Leave type')).toBeInTheDocument()
    // The trigger button itself is hidden while the form is open — there's only ever one
    // way to start a request at a time.
    expect(screen.queryByRole('button', { name: 'Request leave' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByLabelText('Leave type')).not.toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Request leave' })).toBeInTheDocument()
  })

  it('shows a success notice and closes the form once the request is submitted', async () => {
    stubMyLeave({ data: [balance()] })
    mockedUseLeaveTypes.mockReturnValue({
      data: [leaveType()],
      isLoading: false,
      isError: false,
    } as unknown as ReturnType<typeof useLeaveTypes>)
    mockedUseSubmitLeaveRequest.mockReturnValue({
      mutate: vi.fn((_input: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.()),
      isPending: false,
      error: null,
    } as unknown as ReturnType<typeof useSubmitLeaveRequest>)

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Request leave' }))

    fireEvent.click(screen.getByLabelText('Leave type'))
    fireEvent.click(await screen.findByRole('option', { name: 'Vacation Leave' }))
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-08-10' } })
    fireEvent.change(screen.getByLabelText('End date'), { target: { value: '2026-08-12' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Family trip' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(screen.queryByLabelText('Leave type')).not.toBeInTheDocument()
    expect(await screen.findByText(/leave request submitted/i)).toBeInTheDocument()
  })

  it('hides "Request leave" for an account with no linked employee record', async () => {
    stubMyLeave({ isError: true })

    renderPage({ employee: null })

    await screen.findByRole('heading', { name: 'Leave' })
    expect(screen.queryByRole('button', { name: 'Request leave' })).not.toBeInTheDocument()
  })
})
