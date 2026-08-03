import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

/*
 * The one check in this suite that a rendering engine would otherwise have to make.
 *
 * jsdom has no layout engine and computes no colours, so nothing in the other 88 test files
 * could ever have seen that `--ink-subtle` shipped at 3.36:1 and `--success` at 3.35:1 —
 * both under WCAG AA's 4.5:1 floor for body text, and `--ink-subtle` is DESIGN.md's own
 * token for helper text and captions, used as real text in roughly twenty files.
 *
 * Reading the tokens straight out of carbon.css rather than importing them keeps this
 * honest: carbon.css is the only place a DESIGN.md token enters code, so this fails if the
 * value drifts there regardless of what any component does with it.
 */

/** WCAG 2.1 relative luminance. */
function luminance(hex: string): number {
  const channels = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

function ratio(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

// Resolved from the project root, not from import.meta.url — vitest does not hand this
// module a file: URL, so `new URL('./carbon.css', import.meta.url)` throws before any test
// runs, which reads as a broken suite rather than a contrast failure.
const css = readFileSync(resolve(process.cwd(), 'src/styles/carbon.css'), 'utf8')

function token(name: string): string {
  const match = css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`))
  if (match === null) throw new Error(`token --${name} not found in carbon.css`)
  return match[1]
}

describe('carbon.css text tokens meet WCAG AA on the page background', () => {
  // 4.5:1, not 3:1 — these are all used as body-sized text, not as large type or as
  // decoration. --canvas is the background every one of them sits on.
  it.each(['ink', 'ink-muted', 'ink-subtle', 'success', 'error'])(
    '--%s reaches 4.5:1 on --canvas',
    (name) => {
      expect(ratio(token(name), token('canvas'))).toBeGreaterThanOrEqual(4.5)
    },
  )

  it('keeps --inverse-ink and --inverse-ink-muted legible on the inverse canvas', () => {
    expect(ratio(token('inverse-ink'), token('inverse-canvas'))).toBeGreaterThanOrEqual(4.5)
    expect(ratio(token('inverse-ink-muted'), token('inverse-canvas'))).toBeGreaterThanOrEqual(4.5)
  })

  it('keeps the focus ring distinguishable from the surfaces it is drawn against', () => {
    // A focus indicator is a non-text UI component: 3:1 is the AA bar for it, not 4.5:1.
    expect(ratio(token('blue'), token('canvas'))).toBeGreaterThanOrEqual(3)
    expect(ratio(token('blue'), token('surface-1'))).toBeGreaterThanOrEqual(3)
  })
})
