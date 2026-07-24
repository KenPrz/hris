'use client'

import type { ReactNode } from 'react'

export interface EmptyStateProps {
  title: string
  children?: ReactNode
  action?: ReactNode
}

/** Centered placeholder for a screen or panel with nothing in it yet — a month with no
 * punches, a list with no results. `action`, when given, is the one way out (e.g. "Clock
 * in"). */
export function EmptyState({ title, children, action }: EmptyStateProps) {
  return (
    <div
      className="flex flex-col items-center text-center"
      style={{ gap: 'var(--sp-sm)', padding: 'var(--sp-xxl) var(--sp-lg)' }}
    >
      <p style={{ font: 'var(--t-subhead)', color: 'var(--ink)' }}>{title}</p>
      {children ? (
        <p style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {children}
        </p>
      ) : null}
      {action ? <div style={{ marginTop: 'var(--sp-xs)' }}>{action}</div> : null}
    </div>
  )
}
