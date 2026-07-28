import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { PayrollExport, Session } from '@/lib/api'
import { Providers } from '@/components/Providers'

vi.mock('next/navigation', () => ({
  useParams: () => ({ period: 'period-1' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => '/office/cutoffs/period-1/export',
}))

vi.mock('@/hooks/useSession', () => ({
  useSession: vi.fn(),
}))

vi.mock('@/hooks/usePayrollExport', () => ({
  usePayrollExport: vi.fn(),
}))

import { usePayrollExport } from '@/hooks/usePayrollExport'
import { useSession } from '@/hooks/useSession'

import PayrollExportPage from './page'

const mockedUseSession = vi.mocked(useSession)
const mockedUsePayrollExport = vi.mocked(usePayrollExport)

afterEach(() => {
  vi.clearAllMocks()
})

function session(): Session {
  return {
    user: { id: 'u1', email: 'hr@x.com', name: 'HR' },
    employee: { id: 'e-hr', employee_no: 'E-000', current_office_id: 'o1', current_department_id: null },
    is_system_admin: false,
    has_reports: false,
    hr_offices: ['o1'],
    permissions: [],
  }
}

function exportData(overrides: Partial<PayrollExport> = {}): PayrollExport {
  return {
    period: { id: 'period-1', office_id: 'office-1', start_date: '2026-07-01', end_date: '2026-07-15', state: 'closed' },
    employees: [
      {
        employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
        base_rate_segments: [{ effective_from: '2026-07-01', base_rate_cents: 50000 }],
        totals: { worked_minutes: 4800, late_minutes: 0, undertime_minutes: 0, unpaid_overtime_minutes: 0 },
        lines: [{ kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 4800 }],
        has_incomplete_days: false,
      },
    ],
    ...overrides,
  }
}

function stubExport(overrides: Partial<ReturnType<typeof usePayrollExport>> = {}): void {
  mockedUsePayrollExport.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof usePayrollExport>)
}

function renderPage() {
  mockedUseSession.mockReturnValue({ session: session(), isLoading: false, isAuthenticated: true })
  return render(
    <Providers>
      <PayrollExportPage />
    </Providers>,
  )
}

describe('/office/cutoffs/[period]/export', () => {
  it('shows a loading skeleton while the export is loading', () => {
    stubExport({ isLoading: true })
    renderPage()

    expect(screen.getByRole('heading', { name: 'Payroll export' })).toBeInTheDocument()
  })

  it('shows an error notification when the export fails', () => {
    stubExport({ isError: true })
    renderPage()

    expect(screen.getByText(/couldn't load the payroll export/i)).toBeInTheDocument()
  })

  it('renders the per-employee earnings lines and totals from the payload', () => {
    stubExport({
      data: exportData({
        employees: [
          {
            employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
            base_rate_segments: [],
            totals: { worked_minutes: 495, late_minutes: 30, undertime_minutes: 0, unpaid_overtime_minutes: 0 },
            lines: [
              { kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 400 },
              { kind: 'overtime_day', applied_bp: 12500, rule_version_id: 'rv2', minutes: 95 },
            ],
            has_incomplete_days: false,
          },
        ],
      }),
    })
    renderPage()

    expect(screen.getByText('E-001')).toBeInTheDocument()
    expect(screen.getByText('Regular (day)')).toBeInTheDocument()
    expect(screen.getByText('Overtime (day)')).toBeInTheDocument()
    expect(screen.getByText(/125%/)).toBeInTheDocument()
    expect(screen.getByText('8h 15m')).toBeInTheDocument()
  })

  it('surfaces the incomplete-days flag when an employee has one', () => {
    stubExport({
      data: exportData({
        employees: [
          {
            employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
            base_rate_segments: [],
            totals: { worked_minutes: 4800, late_minutes: 0, undertime_minutes: 0, unpaid_overtime_minutes: 0 },
            lines: [{ kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 4800 }],
            has_incomplete_days: true,
          },
        ],
      }),
    })
    renderPage()

    expect(screen.getByText('incomplete days')).toBeInTheDocument()
  })
})
