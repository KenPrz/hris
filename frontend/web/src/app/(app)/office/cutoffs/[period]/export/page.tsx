'use client'

/**
 * The payroll-export review screen for one CLOSED cutoff period, reached from the
 * `/office/cutoffs` list's "View export" affordance (closed rows only). The `[period]`
 * route param is a stored period id — only a closed period carries one — so the page reads
 * it straight into `usePayrollExport`. Loading/error chrome mirrors the cutoffs list;
 * `PayrollExportView` owns the per-employee earnings breakdown.
 */

import Link from 'next/link'
import { useParams } from 'next/navigation'

import { usePayrollExport } from '@/hooks/usePayrollExport'
import { formatDateSpan } from '@/lib/date'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { PayrollExportView } from '@/components/domain/PayrollExportView'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'

export default function PayrollExportPage() {
  const params = useParams<{ period: string }>()
  const periodId = typeof params.period === 'string' ? params.period : null

  const exportQuery = usePayrollExport(periodId)
  const data = exportQuery.data

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader
          eyebrow="Office · Cutoffs"
          title="Payroll export"
          level={1}
          actions={
            <Link
              href="/office/cutoffs"
              className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
              style={{
                font: 'var(--t-body-sm)',
                letterSpacing: 'var(--ls-body)',
                color: 'var(--blue)',
                textDecoration: 'none',
              }}
            >
              Back to cutoffs
            </Link>
          }
        />

        {data !== undefined ? (
          <p style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {formatDateSpan(data.period.start_date, data.period.end_date)}
          </p>
        ) : null}

        {periodId === null ? (
          <InlineNotification kind="error" title="No period to export.">
            This screen needs a closed cutoff period. Pick one from the cutoffs list.
          </InlineNotification>
        ) : exportQuery.isLoading ? (
          <Skeleton height="16rem" />
        ) : exportQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load the payroll export.">
            Check your connection and try again.
          </InlineNotification>
        ) : data !== undefined ? (
          <PayrollExportView data={data} />
        ) : null}
      </div>
    </AppShell>
  )
}
