import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { LeaveDuration } from './LeaveDuration'

describe('LeaveDuration', () => {
  it('renders a whole-days balance as "N days"', () => {
    render(<LeaveDuration readable={{ days: 5, hours: 0, minutes: 0 }} />)

    expect(screen.getByText('5 days')).toBeInTheDocument()
  })

  it('renders a mixed day/hour/minute decomposition, one singular "day"', () => {
    render(<LeaveDuration readable={{ days: 1, hours: 1, minutes: 15 }} />)

    expect(screen.getByText('1 day 1 hr 15 min')).toBeInTheDocument()
  })

  it('renders an empty balance as "0 days", never a blank string', () => {
    render(<LeaveDuration readable={{ days: 0, hours: 0, minutes: 0 }} />)

    expect(screen.getByText('0 days')).toBeInTheDocument()
  })
})
