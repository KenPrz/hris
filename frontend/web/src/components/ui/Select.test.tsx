import { fireEvent, render, screen } from '@testing-library/react'
import { beforeAll, describe, expect, it, vi } from 'vitest'

import { Select } from './Select'

const OPTIONS = [
  { value: 'regular_holiday', label: 'Regular holiday' },
  { value: 'special_working', label: 'Special working' },
]

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView. Radix
// Select's trigger/content call both when opening, so without these stubs the open
// interaction throws inside jsdom — a jsdom gap, not a real accessibility one. This is
// the documented workaround from Radix's own test suite.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

describe('Select', () => {
  it('is reachable by its label and shows the current value', () => {
    render(
      <Select id="day-type" label="Day type" value="regular_holiday" onChange={vi.fn()} options={OPTIONS} />,
    )

    const trigger = screen.getByLabelText('Day type')
    expect(trigger).toBeInTheDocument()
    expect(trigger).toHaveTextContent('Regular holiday')
  })

  it('lists every option once opened', async () => {
    render(
      <Select id="day-type" label="Day type" value="regular_holiday" onChange={vi.fn()} options={OPTIONS} />,
    )

    fireEvent.click(screen.getByLabelText('Day type'))

    expect(await screen.findByRole('option', { name: 'Regular holiday' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Special working' })).toBeInTheDocument()
  })

  it('calls onChange with the chosen option value', async () => {
    const onChange = vi.fn()
    render(
      <Select id="day-type" label="Day type" value="regular_holiday" onChange={onChange} options={OPTIONS} />,
    )

    fireEvent.click(screen.getByLabelText('Day type'))
    const option = await screen.findByRole('option', { name: 'Special working' })
    fireEvent.click(option)

    expect(onChange).toHaveBeenCalledWith('special_working')
  })
})
