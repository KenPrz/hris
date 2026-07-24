import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { EmptyState } from './EmptyState'

describe('EmptyState', () => {
  it('renders its title', () => {
    render(<EmptyState title="No punches yet" />)

    expect(screen.getByText('No punches yet')).toBeInTheDocument()
  })

  it('renders supporting children when given', () => {
    render(<EmptyState title="No punches yet">Clock in to get started.</EmptyState>)

    expect(screen.getByText('Clock in to get started.')).toBeInTheDocument()
  })

  it('renders nothing extra when children is omitted', () => {
    render(<EmptyState title="No punches yet" />)

    expect(screen.queryByText('Clock in to get started.')).not.toBeInTheDocument()
  })

  it('renders the action and fires its handler', () => {
    const onClick = vi.fn()
    render(
      <EmptyState title="No punches yet" action={<button onClick={onClick}>Clock in</button>}>
        Clock in to get started.
      </EmptyState>,
    )

    const button = screen.getByRole('button', { name: 'Clock in' })
    fireEvent.click(button)

    expect(onClick).toHaveBeenCalledTimes(1)
  })
})
