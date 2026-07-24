import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Tag } from './Tag'

describe('Tag', () => {
  it.each([['neutral'], ['warning'], ['success'], ['error']] as const)(
    'renders children for kind="%s"',
    (kind) => {
      render(<Tag kind={kind}>Flagged</Tag>)

      expect(screen.getByText('Flagged')).toBeInTheDocument()
    },
  )
})
