'use client'

import type { ReactNode } from 'react'

export type NotificationKind = 'error' | 'success' | 'warning' | 'info'

export interface InlineNotificationProps {
  kind: NotificationKind
  title: string
  children?: ReactNode
}

const KIND_ACCENT: Record<NotificationKind, string> = {
  error: 'var(--error)',
  success: 'var(--success)',
  warning: 'var(--warning)',
  info: 'var(--blue)',
}

/** 3px left accent bar in the kind's colour; `role="alert"` only for `kind="error"`. */
export function InlineNotification({ kind, title, children }: InlineNotificationProps) {
  return (
    <div
      role={kind === 'error' ? 'alert' : 'status'}
      className="flex flex-col"
      style={{
        background: 'var(--surface-1)',
        borderLeft: `3px solid ${KIND_ACCENT[kind]}`,
        borderRadius: 'var(--radius)',
        padding: 'var(--sp-sm) var(--sp-md)',
        gap: 'var(--sp-xxs)',
      }}
    >
      <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
        {title}
      </span>
      {children ? (
        <span
          style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}
        >
          {children}
        </span>
      ) : null}
    </div>
  )
}
