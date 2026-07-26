import { fireEvent, render, screen } from '@testing-library/react'
import { beforeAll, describe, expect, it, vi } from 'vitest'

import type { AttendanceLog } from '@/lib/api'
import { ApiError } from '@/lib/api'

vi.mock('@/hooks/useSubmitCorrection', () => ({
  useSubmitCorrection: vi.fn(),
}))

import { useSubmitCorrection } from '@/hooks/useSubmitCorrection'

import { CorrectionForm } from './CorrectionForm'

const mockedUseSubmitCorrection = vi.mocked(useSubmitCorrection)

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView. Radix
// Select's trigger/content call both when opening, so without these stubs the open
// interaction throws inside jsdom — a jsdom gap, not a real accessibility one. Mirrors
// Select.test.tsx's own workaround.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

function punch(overrides: Partial<AttendanceLog> = {}): AttendanceLog {
  return {
    id: 'p1',
    employee_id: 'e1',
    office_id: 'o1',
    punched_at: '2026-07-20T08:00:00+08:00',
    direction: 'in',
    source: 'web',
    verification: 'verified',
    flag_reason: null,
    ...overrides,
  }
}

type MutationOverrides = Partial<{
  mutate: ReturnType<typeof vi.fn>
  isPending: boolean
  error: unknown
}>

/** Stubs `useSubmitCorrection`'s return shape down to the fields `CorrectionForm` reads
 * (`mutate`, `isPending`, `error`) — a fresh mock per test, no shared state. */
function stubMutation(overrides: MutationOverrides = {}): ReturnType<typeof vi.fn> {
  const mutate = overrides.mutate ?? vi.fn()
  mockedUseSubmitCorrection.mockReturnValue({
    mutate,
    isPending: overrides.isPending ?? false,
    error: overrides.error ?? null,
    reset: vi.fn(),
  } as unknown as ReturnType<typeof useSubmitCorrection>)
  return mutate
}

async function selectOperation(label: string): Promise<void> {
  fireEvent.click(screen.getByLabelText('Type'))
  fireEvent.click(await screen.findByRole('option', { name: label }))
}

describe('CorrectionForm', () => {
  it('shows direction + time fields and hides the target-punch picker for "add" (the default operation)', () => {
    stubMutation()
    render(<CorrectionForm date="2026-07-20" punches={[punch()]} onDone={vi.fn()} />)

    expect(screen.getByLabelText('Direction')).toBeInTheDocument()
    expect(screen.getByLabelText('Time')).toBeInTheDocument()
    expect(screen.queryByLabelText('Punch')).not.toBeInTheDocument()
  })

  it('shows the target-punch picker (options = the day\'s punches) and hides the time field for "void"', async () => {
    stubMutation()
    render(
      <CorrectionForm
        date="2026-07-20"
        punches={[
          punch({ id: 'p1', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
          punch({ id: 'p2', direction: 'out', punched_at: '2026-07-20T18:00:00+08:00' }),
        ]}
        onDone={vi.fn()}
      />,
    )

    await selectOperation('Void a punch')

    expect(screen.getByLabelText('Punch')).toBeInTheDocument()
    expect(screen.queryByLabelText('Time')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Direction')).not.toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Punch'))
    expect(await screen.findByRole('option', { name: 'IN · 08:00' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'OUT · 18:00' })).toBeInTheDocument()
  })

  it('shows both the target-punch picker AND direction + time for "amend"', async () => {
    stubMutation()
    render(<CorrectionForm date="2026-07-20" punches={[punch()]} onDone={vi.fn()} />)

    await selectOperation('Amend a punch')

    expect(screen.getByLabelText('Punch')).toBeInTheDocument()
    expect(screen.getByLabelText('Direction')).toBeInTheDocument()
    expect(screen.getByLabelText('Time')).toBeInTheDocument()
  })

  it('submits "void" with a chosen punch + note as { operation: "void", target_log_id, note }', async () => {
    const mutate = stubMutation()
    render(
      <CorrectionForm
        date="2026-07-20"
        punches={[punch({ id: 'p2', direction: 'out', punched_at: '2026-07-20T18:00:00+08:00' })]}
        onDone={vi.fn()}
      />,
    )

    await selectOperation('Void a punch')

    fireEvent.click(screen.getByLabelText('Punch'))
    fireEvent.click(await screen.findByRole('option', { name: 'OUT · 18:00' }))

    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Duplicate punch' } })

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(mutate).toHaveBeenCalledWith(
      { operation: 'void', target_log_id: 'p2', note: 'Duplicate punch' },
      expect.anything(),
    )
  })

  it('submits "add" with direction + a punched_at built from date+time, and note', () => {
    const mutate = stubMutation()
    render(<CorrectionForm date="2026-07-20" punches={[]} onDone={vi.fn()} />)

    fireEvent.change(screen.getByLabelText('Time'), { target: { value: '09:15' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Forgot to punch in' } })

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(mutate).toHaveBeenCalledWith(
      { operation: 'add', direction: 'in', punched_at: '2026-07-20T09:15:00+08:00', note: 'Forgot to punch in' },
      expect.anything(),
    )
  })

  it('disables submit until the chosen operation\'s required fields are present', () => {
    stubMutation()
    render(<CorrectionForm date="2026-07-20" punches={[punch()]} onDone={vi.fn()} />)

    // "add" (the default): needs a time and a note — neither is filled in yet.
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Time'), { target: { value: '09:00' } })
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Forgot to punch in' } })
    expect(screen.getByRole('button', { name: 'Submit' })).not.toBeDisabled()
  })

  it('disables submit for "void" until a target punch is chosen', async () => {
    stubMutation()
    render(<CorrectionForm date="2026-07-20" punches={[punch({ id: 'p1' })]} onDone={vi.fn()} />)

    await selectOperation('Void a punch')
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Duplicate' } })

    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.click(screen.getByLabelText('Punch'))
    fireEvent.click(await screen.findByRole('option', { name: 'IN · 08:00' }))

    expect(screen.getByRole('button', { name: 'Submit' })).not.toBeDisabled()
  })

  it('surfaces an ApiError via InlineNotification', () => {
    stubMutation({ error: new ApiError('validation_failed', 'The note field is required.', 422) })
    render(<CorrectionForm date="2026-07-20" punches={[]} onDone={vi.fn()} />)

    expect(screen.getByRole('alert')).toHaveTextContent('The note field is required.')
  })

  it('calls onDone() when the mutation succeeds', () => {
    const onDone = vi.fn()
    stubMutation({
      mutate: vi.fn((_input: unknown, options?: { onSuccess?: () => void }) => {
        options?.onSuccess?.()
      }),
    })
    render(<CorrectionForm date="2026-07-20" punches={[]} onDone={onDone} />)

    fireEvent.change(screen.getByLabelText('Time'), { target: { value: '09:00' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Forgot to punch in' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(onDone).toHaveBeenCalled()
  })
})
