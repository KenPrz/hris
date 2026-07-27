import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { DailySummary } from '@/lib/api'

import { DaySummaryDetail } from './DaySummaryDetail'

function summary(overrides: Partial<DailySummary> = {}): DailySummary {
  return {
    date: '2026-01-15',
    day_type: 'ordinary',
    is_rest_day: false,
    scheduled_minutes: 480,
    is_art82_exempt: false,
    worked_minutes: 480,
    late_minutes: 0,
    undertime_minutes: 0,
    unpaid_overtime_minutes: 0,
    status: 'final',
    is_incomplete: false,
    rule_version_id: 'rv1',
    lines: [{ kind: 'regular_day', minutes: 480, applied_bp: 10000 }],
    ...overrides,
  }
}

describe('DaySummaryDetail', () => {
  it('renders the worked total via Duration', () => {
    render(<DaySummaryDetail summary={summary({ worked_minutes: 495 })} />)

    expect(screen.getByText('8h 15m')).toBeInTheDocument()
  })

  it('renders each line\'s label, minutes, and percent (applied_bp / 100)', () => {
    render(
      <DaySummaryDetail
        summary={summary({
          worked_minutes: 480,
          lines: [
            { kind: 'regular_day', minutes: 400, applied_bp: 10000 },
            { kind: 'overtime_day', minutes: 80, applied_bp: 12500 },
          ],
        })}
      />,
    )

    expect(screen.getByText('Regular (day)')).toBeInTheDocument()
    expect(screen.getByText('Overtime (day)')).toBeInTheDocument()
    expect(screen.getByText(/6h 40m/)).toBeInTheDocument()
    expect(screen.getByText(/100%/)).toBeInTheDocument()
    expect(screen.getByText(/125%/)).toBeInTheDocument()
  })

  it('shows the OT badge when an overtime line exists', () => {
    render(
      <DaySummaryDetail
        summary={summary({ lines: [{ kind: 'overtime_night', minutes: 60, applied_bp: 13000 }] })}
      />,
    )

    expect(screen.getByText('OT')).toBeInTheDocument()
  })

  it('does not show the OT badge when no overtime line exists', () => {
    render(<DaySummaryDetail summary={summary({ lines: [{ kind: 'regular_day', minutes: 480, applied_bp: 10000 }] })} />)

    expect(screen.queryByText('OT')).not.toBeInTheDocument()
  })

  it('shows the premium badge when any line applied_bp > 10000', () => {
    render(
      <DaySummaryDetail
        summary={summary({ lines: [{ kind: 'regular_night', minutes: 60, applied_bp: 11000 }] })}
      />,
    )

    expect(screen.getByText('premium')).toBeInTheDocument()
  })

  it('does not show the premium badge when every line applied_bp <= 10000', () => {
    render(<DaySummaryDetail summary={summary({ lines: [{ kind: 'regular_day', minutes: 480, applied_bp: 10000 }] })} />)

    expect(screen.queryByText('premium')).not.toBeInTheDocument()
  })

  it('shows the incomplete badge when is_incomplete is true', () => {
    render(<DaySummaryDetail summary={summary({ is_incomplete: true })} />)

    expect(screen.getByText('incomplete')).toBeInTheDocument()
  })

  it('does not show the incomplete badge when is_incomplete is false', () => {
    render(<DaySummaryDetail summary={summary({ is_incomplete: false })} />)

    expect(screen.queryByText('incomplete')).not.toBeInTheDocument()
  })

  it('renders nothing, without throwing, when summary is undefined', () => {
    const { container } = render(<DaySummaryDetail summary={undefined} />)

    expect(container).toBeEmptyDOMElement()
  })
})
