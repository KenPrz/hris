'use client'

import { useState } from 'react'
import type { ReactNode } from 'react'

import { Button } from './Button'

export interface WizardStep {
  /** Stable identity for the step; used as the React key for its indicator. */
  id: string
  /** Short label shown in the step indicator. */
  title: string
  /** Renders the step's body. The consumer owns the form state this reads/writes. */
  render: () => ReactNode
  /**
   * Whether the wizard may advance past this step. `undefined` is treated as valid,
   * so a step with no validation (e.g. an optional final step) needs no `isValid`.
   */
  isValid?: boolean
}

export interface WizardProps {
  steps: WizardStep[]
  /**
   * Called when Finish is pressed on the last step. May return a promise; the Finish
   * button shows a pending state until it settles. Errors are the consumer's to surface —
   * the wizard swallows nothing and renders no error UI of its own.
   */
  onComplete: () => void | Promise<void>
}

/**
 * A stateless-about-data, multi-step form shell. The wizard owns only the current step
 * index and the Back/Next/Finish navigation; every step's form state lives in the
 * consumer, which passes `isValid` per step and reads the accumulated data in
 * `onComplete`. This keeps the API free of a generic data-accumulation type and lets a
 * 3-step create flow keep its own typed state, with an optional last step that simply
 * omits `isValid`.
 */
export function Wizard({ steps, onComplete }: WizardProps) {
  const [index, setIndex] = useState(0)
  const [finishing, setFinishing] = useState(false)

  const total = steps.length
  const step = steps[index]
  const isFirst = index === 0
  const isLast = index === total - 1
  // `undefined` means the step has no gate and may advance.
  const canAdvance = step.isValid !== false

  const back = () => setIndex((i) => Math.max(0, i - 1))
  const next = () => setIndex((i) => Math.min(total - 1, i + 1))

  const finish = async () => {
    setFinishing(true)
    try {
      await onComplete()
    } finally {
      setFinishing(false)
    }
  }

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <nav aria-label="Progress" className="flex flex-col" style={{ gap: 'var(--sp-xs)' }}>
        <span
          style={{
            font: 'var(--t-caption)',
            letterSpacing: 'var(--ls-caption)',
            color: 'var(--ink-muted)',
          }}
        >
          Step {index + 1} of {total}
        </span>
        <ol className="flex flex-wrap items-center" style={{ gap: 'var(--sp-md)' }}>
          {steps.map((s, i) => {
            const current = i === index
            return (
              <li
                key={s.id}
                aria-current={current ? 'step' : undefined}
                style={{
                  font: current ? 'var(--t-emphasis)' : 'var(--t-body-sm)',
                  letterSpacing: 'var(--ls-body)',
                  color: current ? 'var(--ink)' : 'var(--ink-subtle)',
                }}
              >
                {s.title}
              </li>
            )
          })}
        </ol>
      </nav>

      <div>{step.render()}</div>

      <div className="flex items-center justify-between" style={{ gap: 'var(--sp-md)' }}>
        {isFirst ? (
          <span />
        ) : (
          <Button variant="secondary" onClick={back}>
            Back
          </Button>
        )}
        {isLast ? (
          <Button onClick={finish} disabled={!canAdvance} loading={finishing}>
            Finish
          </Button>
        ) : (
          <Button onClick={next} disabled={!canAdvance}>
            Next
          </Button>
        )}
      </div>
    </div>
  )
}
