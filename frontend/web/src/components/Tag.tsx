'use client'

import type { CSSProperties, ReactNode } from 'react'

export type TagKind = 'neutral' | 'warning' | 'success' | 'error'

export interface TagProps {
  kind: TagKind
  children: ReactNode
}

// Solid semantic color per kind, with text chosen for contrast against it — carbon.css has
// no separate "tint" tokens, so a pastel badge background would mean inventing a color
// outside the token set. `--warning` (Carbon yellow) reads on dark ink, not white.
const KIND_STYLE: Record<TagKind, CSSProperties> = {
  neutral: { background: 'var(--surface-2)', color: 'var(--ink)' },
  warning: { background: 'var(--warning)', color: 'var(--ink)' },
  success: { background: 'var(--success)', color: 'var(--on-primary)' },
  error: { background: 'var(--error)', color: 'var(--on-primary)' },
}

/** Small status badge. Uses `--radius-xs` (2px) — DESIGN.md's shape scale calls this out
 * as the one deliberate exception to the brand's otherwise-square 0px corners. */
export function Tag({ kind, children }: TagProps) {
  return (
    <span
      className="inline-flex items-center"
      style={{
        ...KIND_STYLE[kind],
        borderRadius: 'var(--radius-xs)',
        padding: 'var(--sp-xxs) var(--sp-xs)',
        font: 'var(--t-caption)',
        letterSpacing: 'var(--ls-caption)',
      }}
    >
      {children}
    </span>
  )
}
