import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { PayrollExport, PayrollEmployeeExport } from '@/lib/api'

import { PayrollExportView } from './PayrollExportView'

function employee(overrides: Partial<PayrollEmployeeExport> = {}): PayrollEmployeeExport {
  return {
    employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
    base_rate_segments: [{ effective_from: '2026-07-01', base_rate_cents: 50000 }],
    totals: { worked_minutes: 4800, late_minutes: 30, undertime_minutes: 15, unpaid_overtime_minutes: 0 },
    lines: [{ kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 4800 }],
    has_incomplete_days: false,
    ...overrides,
  }
}

function exportData(overrides: Partial<PayrollExport> = {}): PayrollExport {
  return {
    period: { id: 'period-1', office_id: 'office-1', start_date: '2026-07-01', end_date: '2026-07-15', state: 'closed' },
    employees: [employee()],
    ...overrides,
  }
}

describe('PayrollExportView', () => {
  it('renders each employee by employee_no', () => {
    render(
      <PayrollExportView
        data={exportData({
          employees: [
            employee({ employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 } }),
            employee({ employee: { id: 'emp-2', employee_no: 'E-002', base_rate_cents: 60000 } }),
          ],
        })}
      />,
    )

    expect(screen.getByText('E-001')).toBeInTheDocument()
    expect(screen.getByText('E-002')).toBeInTheDocument()
  })

  it("renders each earnings line's label, percent (applied_bp / 100), and minutes", () => {
    render(
      <PayrollExportView
        data={exportData({
          employees: [
            employee({
              lines: [
                { kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 400 },
                { kind: 'overtime_day', applied_bp: 12500, rule_version_id: 'rv2', minutes: 80 },
              ],
            }),
          ],
        })}
      />,
    )

    expect(screen.getByText('Regular (day)')).toBeInTheDocument()
    expect(screen.getByText('Overtime (day)')).toBeInTheDocument()
    expect(screen.getByText(/100%/)).toBeInTheDocument()
    expect(screen.getByText(/125%/)).toBeInTheDocument()
    expect(screen.getByText('6h 40m')).toBeInTheDocument()
    expect(screen.getByText('1h 20m')).toBeInTheDocument()
  })

  it('renders the day-level totals via Duration', () => {
    render(
      <PayrollExportView
        data={exportData({
          employees: [
            employee({ totals: { worked_minutes: 495, late_minutes: 30, undertime_minutes: 15, unpaid_overtime_minutes: 60 } }),
          ],
        })}
      />,
    )

    expect(screen.getByText('8h 15m')).toBeInTheDocument()
    expect(screen.getByText('30m')).toBeInTheDocument()
    expect(screen.getByText('15m')).toBeInTheDocument()
    expect(screen.getByText('1h')).toBeInTheDocument()
  })

  it('surfaces the incomplete-days warning tag only when has_incomplete_days is true', () => {
    const { rerender } = render(
      <PayrollExportView data={exportData({ employees: [employee({ has_incomplete_days: true })] })} />,
    )
    expect(screen.getByText('incomplete days')).toBeInTheDocument()

    rerender(<PayrollExportView data={exportData({ employees: [employee({ has_incomplete_days: false })] })} />)
    expect(screen.queryByText('incomplete days')).not.toBeInTheDocument()
  })

  it('shows base_rate_cents as an integer-centavos reference', () => {
    render(
      <PayrollExportView
        data={exportData({ employees: [employee({ employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 } })] })}
      />,
    )

    expect(screen.getByText(/50000/)).toBeInTheDocument()
  })

  it('renders an empty state when the period has no employees', () => {
    render(<PayrollExportView data={exportData({ employees: [] })} />)

    expect(screen.getByText(/no employees/i)).toBeInTheDocument()
  })

  it('scopes lines to their employee (a per-employee earnings table each)', () => {
    render(
      <PayrollExportView
        data={exportData({
          employees: [
            employee({
              employee: { id: 'emp-1', employee_no: 'E-001', base_rate_cents: 50000 },
              lines: [{ kind: 'regular_day', applied_bp: 10000, rule_version_id: 'rv1', minutes: 4800 }],
            }),
            employee({
              employee: { id: 'emp-2', employee_no: 'E-002', base_rate_cents: 60000 },
              lines: [{ kind: 'holiday_unworked', applied_bp: 10000, rule_version_id: 'rv3', minutes: 480 }],
            }),
          ],
        })}
      />,
    )

    const first = screen.getByRole('table', { name: /E-001 earnings/i })
    expect(within(first).getByText('Regular (day)')).toBeInTheDocument()
    const second = screen.getByRole('table', { name: /E-002 earnings/i })
    expect(within(second).getByText('Holiday (unworked)')).toBeInTheDocument()
  })
})
