'use client'

export interface TextInputProps {
  id: string
  label: string
  type?: 'text' | 'email' | 'password'
  value: string
  onChange: (value: string) => void
  error?: string
  autoComplete?: string
  required?: boolean
}

/**
 * Carbon's filled input: `--surface-1` background, 1px bottom rule, visible 2px focus
 * ring. `error` wires `aria-invalid` and `aria-describedby` to a rendered message —
 * the message element only exists (and only gets an id) when there is an error.
 */
export function TextInput({
  id,
  label,
  type = 'text',
  value,
  onChange,
  error,
  autoComplete,
  required = false,
}: TextInputProps) {
  const errorId = `${id}-error`

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
      <label
        htmlFor={id}
        style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
      >
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        required={required}
        autoComplete={autoComplete}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error ? errorId : undefined}
        onChange={(event) => onChange(event.target.value)}
        className="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--blue)]"
        style={{
          background: 'var(--surface-1)',
          color: 'var(--ink)',
          border: 'none',
          borderBottom: `1px solid ${error ? 'var(--error)' : 'var(--field-border)'}`,
          borderRadius: 'var(--radius)',
          // DESIGN.md's text-input padding is 11px 16px — 16px is --sp-md exactly, 11px
          // isn't on the --sp-* scale, so it's derived from --sp-sm (12px) rather than
          // hand-typed, keeping every space value var()-driven.
          padding: 'calc(var(--sp-sm) - 1px) var(--sp-md)',
          font: 'var(--t-body)',
          letterSpacing: 'var(--ls-body)',
        }}
      />
      {error ? (
        <span
          id={errorId}
          style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--error)' }}
        >
          {error}
        </span>
      ) : null}
    </div>
  )
}
