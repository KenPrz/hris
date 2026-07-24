import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Skeleton } from './Skeleton'

describe('Skeleton', () => {
  it('is hidden from assistive tech — it carries no information', () => {
    const { container } = render(<Skeleton />)

    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
  })

  it('gates its animation behind motion-safe, so reduced-motion users get a still placeholder', () => {
    // `motion-safe:` compiles to @media (prefers-reduced-motion: no-preference).
    // jsdom cannot evaluate that media query, so this pins the guard itself:
    // deleting the prefix would silently animate for users who asked us not to.
    const { container } = render(<Skeleton />)

    expect(container.firstElementChild).toHaveClass('motion-safe:animate-pulse')
    expect(container.firstElementChild?.className).not.toMatch(/(^|\s)animate-pulse(\s|$)/)
  })

  it('keeps the square brand and takes size overrides', () => {
    const { container } = render(<Skeleton width="120px" height="8px" />)
    const el = container.firstElementChild as HTMLElement

    expect(el.style.width).toBe('120px')
    expect(el.style.height).toBe('8px')
    expect(el.style.borderRadius).toBe('var(--radius)')
  })
})
