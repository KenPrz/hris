'use client'

export interface SkeletonProps {
  width?: string
  height?: string
  className?: string
}

/** Loading placeholder. Pulses only when the user has not asked for reduced motion. */
export function Skeleton({ width = '100%', height = 'var(--sp-lg)', className }: SkeletonProps) {
  return (
    <div
      aria-hidden="true"
      className={['motion-safe:animate-pulse', className].filter(Boolean).join(' ')}
      style={{
        width,
        height,
        background: 'var(--surface-2)',
        borderRadius: 'var(--radius)',
      }}
    />
  )
}
