'use client'

/**
 * A closed cutoff period's payroll export, per employee — the human-readable review of what
 * `usePayrollExport` returns before it becomes an outbound payroll file. Presentational only:
 * the page around it owns the office/period selection, loading, and error chrome.
 *
 * The earnings lines reuse `DaySummaryDetail`'s `LINE_LABEL` and `bpToPercent`, so a payroll
 * line reads identically to the same kind on the day calendar — one copy of the wording, one
 * definition of "basis points to a percent". `base_rate_cents` is shown verbatim as integer
 * centavos: a reference an operator reconciles against, never peso math this screen invents.
 */

import type { CSSProperties } from 'react'

import type { PayrollExport, PayrollEmployeeExport } from '@/lib/api'
import { EmptyState } from '../EmptyState'
import { Tag } from '../Tag'
import { LINE_LABEL, bpToPercent } from './DaySummaryDetail'
import { Duration } from './Duration'

export interface PayrollExportViewProps {
  data: PayrollExport
}

/** `50000` -> `"50000¢"` reference label; a missing rate reads as an em dash, never a zero.
 * Integer centavos verbatim — this screen never does peso math. */
function baseRateLabel(cents: number | null): string {
  return cents === null ? '—' : `${cents}¢`
}

const LINE_HEAD: CSSProperties = {
  padding: 'var(--sp-sm) var(--sp-md)',
  font: 'var(--t-caption)',
  letterSpacing: 'var(--ls-caption)',
  color: 'var(--ink-subtle)',
}

const LINE_CELL: CSSProperties = {
  padding: 'var(--sp-sm) var(--sp-md)',
  font: 'var(--t-body-sm)',
  letterSpacing: 'var(--ls-body)',
  color: 'var(--ink)',
  borderTop: '1px solid var(--hairline)',
}

/** One employee's block: header (employee_no + incomplete flag), day-level totals, and the
 * earnings-lines table. Mirrors the cutoffs list's card-on-`--surface-1` styling. */
function EmployeeSection({ employee }: { employee: PayrollEmployeeExport }) {
  const { employee: who, totals, lines, has_incomplete_days } = employee

  const totalCells: { label: string; minutes: number }[] = [
    { label: 'Worked', minutes: totals.worked_minutes },
    { label: 'Late', minutes: totals.late_minutes },
    { label: 'Undertime', minutes: totals.undertime_minutes },
    { label: 'Unpaid OT', minutes: totals.unpaid_overtime_minutes },
  ]

  return (
    <section
      className="flex flex-col"
      style={{
        gap: 'var(--sp-md)',
        padding: 'var(--sp-md)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
      }}
    >
      <div className="flex items-center flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-card-title)', color: 'var(--ink)' }}>{who.employee_no}</span>
        {has_incomplete_days ? <Tag kind="warning">incomplete days</Tag> : null}
        <span
          style={{
            marginLeft: 'auto',
            font: 'var(--t-caption)',
            letterSpacing: 'var(--ls-caption)',
            color: 'var(--ink-muted)',
          }}
        >
          Base rate: {baseRateLabel(who.base_rate_cents)}
        </span>
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
        {totalCells.map((cell) => (
          <div key={cell.label} className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
            <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}>
              {cell.label}
            </span>
            <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
              <Duration minutes={cell.minutes} />
            </span>
          </div>
        ))}
      </div>

      {lines.length > 0 ? (
        <div style={{ overflowX: 'auto' }}>
          <table
            aria-label={`${who.employee_no} earnings`}
            style={{ width: '100%', borderCollapse: 'collapse' }}
          >
            <thead>
              <tr>
                <th scope="col" style={{ ...LINE_HEAD, textAlign: 'left' }}>Line</th>
                <th scope="col" style={{ ...LINE_HEAD, textAlign: 'right' }}>Rate</th>
                <th scope="col" style={{ ...LINE_HEAD, textAlign: 'right' }}>Hours</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line) => (
                <tr key={line.kind}>
                  <td style={{ ...LINE_CELL, textAlign: 'left' }}>{LINE_LABEL[line.kind]}</td>
                  <td style={{ ...LINE_CELL, textAlign: 'right', color: 'var(--ink-muted)' }}>
                    {bpToPercent(line.applied_bp)}%
                  </td>
                  <td style={{ ...LINE_CELL, textAlign: 'right' }}>
                    <Duration minutes={line.minutes} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          No priced earnings for this period.
        </p>
      )}
    </section>
  )
}

export function PayrollExportView({ data }: PayrollExportViewProps) {
  if (data.employees.length === 0) {
    return <EmptyState title="No employees in this period" />
  }

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      {data.employees.map((employee) => (
        <EmployeeSection key={employee.employee.id} employee={employee} />
      ))}
    </div>
  )
}
