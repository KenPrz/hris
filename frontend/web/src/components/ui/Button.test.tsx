import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { Button } from './Button'

describe('Button', () => {
  it('renders its label', () => {
    render(<Button>Save</Button>)

    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument()
  })

  it('fires onClick when clicked', () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>Save</Button>)

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('is disabled and does not fire onClick when disabled', () => {
    const onClick = vi.fn()
    render(
      <Button disabled onClick={onClick}>
        Save
      </Button>,
    )
    const button = screen.getByRole('button', { name: 'Save' })

    fireEvent.click(button)

    expect(button).toBeDisabled()
    expect(onClick).not.toHaveBeenCalled()
  })

  it('is disabled and does not fire onClick when loading', () => {
    const onClick = vi.fn()
    render(
      <Button loading onClick={onClick}>
        Save
      </Button>,
    )
    const button = screen.getByRole('button', { name: 'Save' })

    fireEvent.click(button)

    expect(button).toBeDisabled()
    expect(onClick).not.toHaveBeenCalled()
  })

  it('places the icon after the label in DOM order — Carbon signature layout', () => {
    render(
      <Button icon={<svg data-testid="icon" />}>
        <span data-testid="label">Save</span>
      </Button>,
    )
    const label = screen.getByTestId('label')
    const icon = screen.getByTestId('icon')

    // DOCUMENT_POSITION_FOLLOWING (4): the icon node must come after the label node in
    // document order — label-left/icon-right is Carbon's signature layout, not centred.
    expect(label.compareDocumentPosition(icon) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })
})
