import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { Wizard } from './Wizard'
import type { WizardStep } from './Wizard'

function makeSteps(overrides: Partial<WizardStep>[] = []): WizardStep[] {
  const base: WizardStep[] = [
    { id: 'one', title: 'Identity', render: () => <p>Step one body</p> },
    { id: 'two', title: 'Employment', render: () => <p>Step two body</p> },
    { id: 'three', title: 'Optional', render: () => <p>Step three body</p> },
  ]
  return base.map((s, i) => ({ ...s, ...overrides[i] }))
}

describe('Wizard', () => {
  it('renders the first step with Back hidden and Next present', () => {
    render(<Wizard steps={makeSteps()} onComplete={vi.fn()} />)

    expect(screen.getByText('Step one body')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Back' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument()
  })

  it('shows "Step 1 of 3" and emphasizes the current step title', () => {
    render(<Wizard steps={makeSteps()} onComplete={vi.fn()} />)

    expect(screen.getByText('Step 1 of 3')).toBeInTheDocument()
    expect(screen.getByText('Identity')).toHaveAttribute('aria-current', 'step')
    expect(screen.getByText('Employment')).not.toHaveAttribute('aria-current')
  })

  it('disables Next when the current step is invalid and enables it when valid', () => {
    const { rerender } = render(
      <Wizard steps={makeSteps([{ isValid: false }])} onComplete={vi.fn()} />,
    )
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()

    rerender(<Wizard steps={makeSteps([{ isValid: true }])} onComplete={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()
  })

  it('treats an undefined isValid as advanceable', () => {
    render(<Wizard steps={makeSteps()} onComplete={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()
  })

  it('advances to step 2 on Next and returns to step 1 on Back', () => {
    render(<Wizard steps={makeSteps()} onComplete={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.getByText('Step two body')).toBeInTheDocument()
    expect(screen.getByText('Step 2 of 3')).toBeInTheDocument()
    expect(screen.getByText('Employment')).toHaveAttribute('aria-current', 'step')

    fireEvent.click(screen.getByRole('button', { name: 'Back' }))

    expect(screen.getByText('Step one body')).toBeInTheDocument()
    expect(screen.getByText('Step 1 of 3')).toBeInTheDocument()
  })

  it('shows Finish on the last step and calls onComplete when clicked', async () => {
    const onComplete = vi.fn()
    render(<Wizard steps={makeSteps()} onComplete={onComplete} />)

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument()
    const finish = screen.getByRole('button', { name: 'Finish' })
    fireEvent.click(finish)

    await waitFor(() => expect(onComplete).toHaveBeenCalledTimes(1))
  })

  it('shows a pending state on Finish while onComplete resolves', async () => {
    let resolve: () => void = () => {}
    const onComplete = vi.fn(() => new Promise<void>((r) => (resolve = r)))
    render(<Wizard steps={makeSteps()} onComplete={onComplete} />)

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    fireEvent.click(screen.getByRole('button', { name: 'Finish' }))

    const finish = screen.getByRole('button', { name: 'Finish' })
    await waitFor(() => expect(finish).toHaveAttribute('aria-busy', 'true'))

    resolve()
    await waitFor(() => expect(finish).not.toHaveAttribute('aria-busy'))
  })

  it('carries consumer-owned form state across steps into onComplete', async () => {
    const seen: string[] = []

    function Harness() {
      const [name, setName] = useState('')
      const steps: WizardStep[] = [
        {
          id: 'name',
          title: 'Name',
          isValid: name.length > 0,
          render: () => (
            <input
              aria-label="name"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          ),
        },
        { id: 'review', title: 'Review', render: () => <p>Review {name}</p> },
      ]
      return <Wizard steps={steps} onComplete={() => void seen.push(name)} />
    }

    render(<Harness />)

    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    fireEvent.change(screen.getByLabelText('name'), { target: { value: 'Ada' } })
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    fireEvent.click(screen.getByRole('button', { name: 'Finish' }))

    await waitFor(() => expect(seen).toEqual(['Ada']))
  })
})
