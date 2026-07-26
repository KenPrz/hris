import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

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

import { useMyLeave } from '@/hooks/useMyLeave'

import MyLeavePage from './page'

const mockedUseMyLeave = vi.mocked(useMyLeave)

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

function stubSessionFetch(): void {
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
              employee: { id: 'e1', employee_no: 'E-001', current_office_id: 'o1', current_department_id: null },
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

function renderPage() {
  setToken('sekrit')
  stubSessionFetch()
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

  it('shows an empty state when there are no leave types configured', async () => {
    stubMyLeave({ data: [] })

    renderPage()

    expect(await screen.findByText(/no leave balances/i)).toBeInTheDocument()
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
})
