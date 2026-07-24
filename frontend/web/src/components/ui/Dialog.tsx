'use client'

import * as RadixDialog from '@radix-ui/react-dialog'
import type { ReactNode } from 'react'

export interface DialogProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
}

/**
 * Radix-backed modal. Every dismissal path — Escape, overlay click, and (if a caller
 * wires one in) an explicit close control — funnels through Radix's single
 * `onOpenChange`, so there is exactly one route to `onClose`. Focus trap, `role="dialog"`
 * and the `aria-labelledby` wiring to the title are all Radix's, not hand-rolled.
 */
export function Dialog({ open, onClose, title, children }: DialogProps) {
  return (
    <RadixDialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <RadixDialog.Portal>
        <RadixDialog.Overlay
          data-testid="dialog-overlay"
          className="fixed inset-0"
          style={{ background: 'var(--ink)', opacity: 0.5 }}
        />
        <RadixDialog.Content
          className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 focus-visible:outline-none flex flex-col"
          style={{
            background: 'var(--canvas)',
            color: 'var(--ink)',
            borderRadius: 'var(--radius)',
            padding: 'var(--sp-lg)',
            gap: 'var(--sp-md)',
            minWidth: '20rem',
            maxWidth: '90vw',
          }}
        >
          <RadixDialog.Title style={{ font: 'var(--t-card-title)', color: 'var(--ink)' }}>
            {title}
          </RadixDialog.Title>
          {children}
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  )
}
