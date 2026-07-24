'use client'

import type { ReactNode } from 'react'

export interface SectionHeaderProps {
  eyebrow?: string
  title: string
  actions?: ReactNode
}

/** `{typography.headline}` (0 tracking, so no `--ls-*` companion needed) over a hairline
 * rule — Carbon separates sections with a thin gray row, not a large vertical gap. */
export function SectionHeader({ eyebrow, title, actions }: SectionHeaderProps) {
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
        <h1 style={{ font: 'var(--t-headline)', color: 'var(--ink)' }}>{title}</h1>
      </div>
      {actions ? (
        <div className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
          {actions}
        </div>
      ) : null}
    </div>
  )
}
