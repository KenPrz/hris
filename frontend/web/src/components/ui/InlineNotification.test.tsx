import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { InlineNotification } from './InlineNotification'

describe('InlineNotification', () => {
  it('exposes role="alert" and its title for kind="error"', () => {
    render(<InlineNotification kind="error" title="Something went wrong" />)

    expect(screen.getByRole('alert')).toHaveTextContent('Something went wrong')
  })

  it('renders children as supporting detail alongside the title', () => {
    render(
      <InlineNotification kind="error" title="Something went wrong">
        Try again later.
      </InlineNotification>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Try again later.')
  })

  it('does not use role="alert" for non-error kinds', () => {
    render(<InlineNotification kind="success" title="Saved" />)

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
