import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { StatTile } from './StatTile'

describe('StatTile', () => {
  it('renders the label and value', () => {
    render(<StatTile label="Total this month" value="164h 20m" />)

    expect(screen.getByText('Total this month')).toBeInTheDocument()
    expect(screen.getByText('164h 20m')).toBeInTheDocument()
  })

  it('renders the value at display size, Plex Light 300', () => {
    render(<StatTile label="Total this month" value="164h 20m" />)

    expect(screen.getByText('164h 20m')).toHaveStyle({ font: 'var(--t-display-md)' })
  })

  it('renders the hint when given', () => {
    render(<StatTile label="Total this month" value="164h 20m" hint="vs. 160h last month" />)

    expect(screen.getByText('vs. 160h last month')).toBeInTheDocument()
  })

  it('omits the hint when not given', () => {
    render(<StatTile label="Total this month" value="164h 20m" />)

    expect(screen.queryByText('vs. 160h last month')).not.toBeInTheDocument()
  })
})
