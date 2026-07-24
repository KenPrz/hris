'use client'

import type { CSSProperties, ReactNode } from 'react'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'

export interface ButtonProps {
  variant?: ButtonVariant
  type?: 'button' | 'submit'
  disabled?: boolean
  loading?: boolean
  onClick?: () => void
  children: ReactNode
  icon?: ReactNode
}

// DESIGN.md's `components` block, per variant (button-primary / -secondary / -tertiary
// (aka ghost) / -danger). Border is intentionally omitted — the flat-square system draws
// no border on any of these.
const VARIANT_STYLE: Record<ButtonVariant, CSSProperties> = {
  primary: { background: 'var(--blue)', color: 'var(--on-primary)' },
  secondary: { background: 'var(--ink)', color: 'var(--inverse-ink)' },
  ghost: { background: 'var(--canvas)', color: 'var(--blue)' },
  danger: { background: 'var(--error)', color: 'var(--on-primary)' },
}

/**
 * Carbon's signature button layout: label on the left, icon on the right
 * (`justify-content: space-between`), not centred. 0 radius; 48px tall for
 * primary field-level use.
 */
export function Button({
  variant = 'primary',
  type = 'button',
  disabled = false,
  loading = false,
  onClick,
  children,
  icon,
}: ButtonProps) {
  const isDisabled = disabled || loading

  return (
    <button
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      onClick={isDisabled ? undefined : onClick}
      className="inline-flex items-center justify-between border-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)] disabled:cursor-not-allowed disabled:opacity-50"
      style={{
        ...VARIANT_STYLE[variant],
        borderRadius: 'var(--radius)',
        height: 'var(--sp-xxl)',
        padding: '0 var(--sp-md)',
        gap: 'var(--sp-xs)',
        font: 'var(--t-body-sm)',
        letterSpacing: 'var(--ls-body)',
        cursor: isDisabled ? 'not-allowed' : 'pointer',
      }}
    >
      <span>{children}</span>
      {icon ? (
        <span aria-hidden="true" className="inline-flex items-center">
          {icon}
        </span>
      ) : null}
    </button>
  )
}
