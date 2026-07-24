import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Duration } from './Duration'

describe('Duration', () => {
  it('renders 440 minutes as 7h 20m — the one place minutes become text', () => {
    render(<Duration minutes={440} />)

    expect(screen.getByText('7h 20m')).toBeInTheDocument()
  })

  it('drops the minutes part on a whole hour, mirroring formatDuration', () => {
    render(<Duration minutes={480} />)

    expect(screen.getByText('8h')).toBeInTheDocument()
  })
})
