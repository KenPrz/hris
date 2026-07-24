import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { Dialog } from './Dialog'

describe('Dialog', () => {
  it('renders the title and children when open', () => {
    render(
      <Dialog open onClose={vi.fn()} title="Add holiday">
        <p>Form contents</p>
      </Dialog>,
    )

    expect(screen.getByRole('dialog', { name: 'Add holiday' })).toBeInTheDocument()
    expect(screen.getByText('Form contents')).toBeInTheDocument()
  })

  it('renders nothing when closed', () => {
    render(
      <Dialog open={false} onClose={vi.fn()} title="Add holiday">
        <p>Form contents</p>
      </Dialog>,
    )

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByText('Form contents')).not.toBeInTheDocument()
  })

  it('calls onClose on Escape', () => {
    const onClose = vi.fn()
    render(
      <Dialog open onClose={onClose} title="Add holiday">
        <p>Form contents</p>
      </Dialog>,
    )

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('calls onClose on overlay click', async () => {
    const onClose = vi.fn()
    render(
      <Dialog open onClose={onClose} title="Add holiday">
        <p>Form contents</p>
      </Dialog>,
    )

    // Radix's dismissable layer defers "pointerdown outside" handling by one tick (so the
    // click that opened a dialog can't also close it) and only commits on a following
    // "click" — so a real user click is a pointerdown+click pair, not click alone.
    await new Promise((resolve) => setTimeout(resolve, 0))
    const overlay = screen.getByTestId('dialog-overlay')
    fireEvent.pointerDown(overlay)
    fireEvent.click(overlay)

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('traps focus inside the dialog on open', () => {
    render(
      <Dialog open onClose={vi.fn()} title="Add holiday">
        <button type="button">Inside</button>
      </Dialog>,
    )

    const dialog = screen.getByRole('dialog')
    expect(dialog.contains(document.activeElement)).toBe(true)
  })
})
