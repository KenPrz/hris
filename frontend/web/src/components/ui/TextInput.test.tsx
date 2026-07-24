import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { TextInput } from './TextInput'

describe('TextInput', () => {
  it('is found by its label and reflects its value', () => {
    render(<TextInput id="email" label="Email" value="a@b.com" onChange={() => {}} />)

    expect(screen.getByLabelText('Email')).toHaveValue('a@b.com')
  })

  it('calls onChange with the new string value', () => {
    const onChange = vi.fn()
    render(<TextInput id="email" label="Email" value="" onChange={onChange} />)

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'x@y.com' } })

    expect(onChange).toHaveBeenCalledWith('x@y.com')
  })

  it('has no aria-invalid or aria-describedby when there is no error', () => {
    render(<TextInput id="email" label="Email" value="" onChange={() => {}} />)

    const input = screen.getByLabelText('Email')
    expect(input).not.toHaveAttribute('aria-invalid')
    expect(input).not.toHaveAttribute('aria-describedby')
  })

  it('sets aria-invalid="true" and links the error message via aria-describedby', () => {
    render(
      <TextInput
        id="email"
        label="Email"
        value=""
        onChange={() => {}}
        error="Enter a valid email address."
      />,
    )

    const input = screen.getByLabelText('Email')
    expect(input).toHaveAttribute('aria-invalid', 'true')

    const describedBy = input.getAttribute('aria-describedby')
    expect(describedBy).toBeTruthy()

    const message = document.getElementById(describedBy as string)
    expect(message).toHaveTextContent('Enter a valid email address.')
  })
})
