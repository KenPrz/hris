import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { ResolvedDay } from '@/lib/api'
import { ResolvedDayCell } from './ResolvedDayCell'

function resolvedDay(overrides: Partial<ResolvedDay> = {}): ResolvedDay {
  return {
    is_rest: false,
    start_minute: 480,
    end_minute: 1080,
    break_minutes: 60,
    scheduled_minutes: 540,
    source: 'office_default',
    ...overrides,
  }
}

describe('ResolvedDayCell', () => {
  it('renders "Rest" for a rest day', () => {
    render(
      <ResolvedDayCell
        resolved={resolvedDay({
          is_rest: true,
          start_minute: null,
          end_minute: null,
          break_minutes: null,
          scheduled_minutes: 0,
        })}
      />,
    )

    expect(screen.getByText('Rest')).toBeInTheDocument()
  })

  it('renders the shift hours for a working day', () => {
    render(<ResolvedDayCell resolved={resolvedDay()} />)

    expect(screen.getByText('08:00–18:00')).toBeInTheDocument()
  })

  it('renders a source badge', () => {
    render(<ResolvedDayCell resolved={resolvedDay({ source: 'override' })} />)

    expect(screen.getByText('Override')).toBeInTheDocument()
  })

  it('shows a +1 hint for a cross-midnight resolved day', () => {
    render(<ResolvedDayCell resolved={resolvedDay({ start_minute: 1020, end_minute: 1620 })} />)

    expect(screen.getByText('17:00–03:00 +1')).toBeInTheDocument()
  })

  it('renders blank for an undefined resolved day, without crashing', () => {
    const { container } = render(<ResolvedDayCell resolved={undefined} />)

    expect(container).toBeEmptyDOMElement()
  })
})
