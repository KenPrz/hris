import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/lib/api'

vi.mock('@/hooks/useSubmitOvertimeRequest', () => ({
  useSubmitOvertimeRequest: vi.fn(),
}))

import { useSubmitOvertimeRequest } from '@/hooks/useSubmitOvertimeRequest'

import { OvertimeRequestForm } from './OvertimeRequestForm'

const mockedUseSubmitOvertimeRequest = vi.mocked(useSubmitOvertimeRequest)

type MutationOverrides = Partial<{
  mutate: ReturnType<typeof vi.fn>
  isPending: boolean
  error: unknown
}>

function stubSubmit(overrides: MutationOverrides = {}): ReturnType<typeof vi.fn> {
  const mutate = overrides.mutate ?? vi.fn()
  mockedUseSubmitOvertimeRequest.mockReturnValue({
    mutate,
    isPending: overrides.isPending ?? false,
    error: overrides.error ?? null,
  } as unknown as ReturnType<typeof useSubmitOvertimeRequest>)
  return mutate
}

describe('OvertimeRequestForm', () => {
  it('disables submit until date, hours, and note are all present', () => {
    stubSubmit()

    render(<OvertimeRequestForm onDone={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-07-15' } })
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Hours'), { target: { value: '2' } })
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Release crunch' } })
    expect(screen.getByRole('button', { name: 'Submit' })).not.toBeDisabled()
  })

  it('disables submit when hours is zero or negative', () => {
    stubSubmit()

    render(<OvertimeRequestForm onDone={vi.fn()} />)

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-07-15' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Release crunch' } })

    fireEvent.change(screen.getByLabelText('Hours'), { target: { value: '0' } })
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()
  })

  it('previews the minutes to be requested from the hours entered', () => {
    stubSubmit()

    render(<OvertimeRequestForm onDone={vi.fn()} />)

    expect(screen.queryByText(/overtime requested/i)).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Hours'), { target: { value: '2' } })

    expect(screen.getByText(/2h/)).toBeInTheDocument()
  })

  it('submits with the { date, hours, note } payload', () => {
    const mutate = stubSubmit()

    render(<OvertimeRequestForm onDone={vi.fn()} />)

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-07-15' } })
    fireEvent.change(screen.getByLabelText('Hours'), { target: { value: '2.5' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Release crunch' } })

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(mutate).toHaveBeenCalledWith(
      { date: '2026-07-15', hours: 2.5, note: 'Release crunch' },
      expect.anything(),
    )
  })

  it('calls onDone() when the mutation succeeds', () => {
    const onDone = vi.fn()
    stubSubmit({
      mutate: vi.fn((_input: unknown, options?: { onSuccess?: () => void }) => {
        options?.onSuccess?.()
      }),
    })

    render(<OvertimeRequestForm onDone={onDone} />)

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-07-15' } })
    fireEvent.change(screen.getByLabelText('Hours'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Release crunch' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }))

    expect(onDone).toHaveBeenCalled()
  })

  it('surfaces an ApiError via InlineNotification', () => {
    stubSubmit({ error: new ApiError('validation_failed', 'The note field is required.', 422) })

    render(<OvertimeRequestForm onDone={vi.fn()} />)

    expect(screen.getByRole('alert')).toHaveTextContent('The note field is required.')
  })
})
