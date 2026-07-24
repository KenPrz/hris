import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { SectionHeader } from './SectionHeader'

describe('SectionHeader', () => {
  it('renders the title', () => {
    render(<SectionHeader title="Attendance" />)

    expect(screen.getByText('Attendance')).toBeInTheDocument()
  })

  it('renders an eyebrow when given', () => {
    render(<SectionHeader eyebrow="July 2026" title="Attendance" />)

    expect(screen.getByText('July 2026')).toBeInTheDocument()
  })

  it('omits the eyebrow when not given', () => {
    render(<SectionHeader title="Attendance" />)

    expect(screen.queryByText('July 2026')).not.toBeInTheDocument()
  })

  it('renders actions when given', () => {
    render(<SectionHeader title="Attendance" actions={<button>Export</button>} />)

    expect(screen.getByRole('button', { name: 'Export' })).toBeInTheDocument()
  })
})
