import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { DayTypeTag } from './DayTypeTag'
import type { DayType } from './DayTypeTag'

describe('DayTypeTag', () => {
  const cases: Array<[DayType, string]> = [
    ['special_working', 'Special working'],
    ['special_non_working', 'Special non-working'],
    ['regular_holiday', 'Regular holiday'],
    ['double_regular_holiday', 'Double regular holiday'],
  ]

  it.each(cases)('renders the human label for %s', (dayType, label) => {
    render(<DayTypeTag dayType={dayType} />)

    expect(screen.getByText(label)).toBeInTheDocument()
  })
})
