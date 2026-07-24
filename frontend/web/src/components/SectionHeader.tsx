'use client'

import type { ReactNode } from 'react'

export interface SectionHeaderProps {
  eyebrow?: string
  title: string
  actions?: ReactNode
  /** Heading level. `1` for the page's single title, `2` (default) for the sections
   * under it — so a screen reader's heading outline reads as one page with sections,
   * not several competing page titles. The level also sizes the type: the page title in
   * `{headline}`, a section in the smaller `{card-title}`. */
  level?: 1 | 2
}

/** A title (0-tracking Carbon type step, so no `--ls-*` companion) over a hairline rule —
 * Carbon separates sections with a thin gray row, not a large vertical gap. */
export function SectionHeader({ eyebrow, title, actions, level = 2 }: SectionHeaderProps) {
  const Heading = level === 1 ? 'h1' : 'h2'
  const titleFont = level === 1 ? 'var(--t-headline)' : 'var(--t-card-title)'

  return (
    <div
      className="flex items-end justify-between"
      style={{
        gap: 'var(--sp-md)',
        paddingBottom: 'var(--sp-sm)',
        borderBottom: '1px solid var(--hairline)',
      }}
    >
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        {eyebrow ? (
          <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {eyebrow}
          </span>
        ) : null}
        <Heading style={{ font: titleFont, color: 'var(--ink)' }}>{title}</Heading>
      </div>
      {actions ? (
        <div className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
          {actions}
        </div>
      ) : null}
    </div>
  )
}
