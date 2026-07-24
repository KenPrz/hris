'use client'

export interface StatTileProps {
  label: string
  value: string
  hint?: string
}

/** The value carries `--t-display-md` — Plex Light 300 at display size, Carbon's brand
 * signature for numbers that matter (0 tracking, so no `--ls-*` companion needed). */
export function StatTile({ label, value, hint }: StatTileProps) {
  return (
    <div
      className="flex flex-col"
      style={{
        gap: 'var(--sp-xxs)',
        padding: 'var(--sp-md)',
        background: 'var(--surface-1)',
        borderRadius: 'var(--radius)',
      }}
    >
      <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-muted)' }}>
        {label}
      </span>
      <span style={{ font: 'var(--t-display-md)', color: 'var(--ink)' }}>{value}</span>
      {hint ? (
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-subtle)' }}>
          {hint}
        </span>
      ) : null}
    </div>
  )
}
