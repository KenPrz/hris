import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(path.resolve(__dirname, './carbon.css'), 'utf8')
const globalsCss = readFileSync(path.resolve(__dirname, '../app/globals.css'), 'utf8')

describe('carbon tokens', () => {
  it.each([
    ['--blue', '#0f62fe'],
    ['--ink', '#161616'],
    ['--canvas', '#ffffff'],
    ['--hairline', '#e0e0e0'],
    ['--error', '#da1e28'],
    ['--radius', '0px'],
  ])('defines %s as %s, matching DESIGN.md', (token, value) => {
    expect(css).toMatch(new RegExp(`${token}:\\s*${value};`))
  })

  it('ships no dark-mode override — DESIGN.md is a light-canvas system', () => {
    expect(css).not.toContain('prefers-color-scheme')
  })

  it('globals.css carries no dark-mode override either — that is where the create-next-app boilerplate put it', () => {
    expect(globalsCss).not.toContain('prefers-color-scheme')
  })
})

describe('carbon tracking tokens (letter-spacing)', () => {
  // The `font` shorthand used by --t-* tokens cannot express letter-spacing, so DESIGN.md's
  // tracking values must exist as companion custom properties. Values from DESIGN.md's
  // typography block, verbatim.
  it.each([
    ['--ls-body', '0.16px'],
    ['--ls-caption', '0.32px'],
    ['--ls-display-xl', '-0.5px'],
    ['--ls-display-lg', '-0.4px'],
  ])('defines %s as %s, matching DESIGN.md', (token, value) => {
    expect(css).toMatch(new RegExp(`${token}:\\s*${value.replace('.', '\\.')};`))
  })

  it('actually applies tracking where a type token is consumed — not merely defined', () => {
    // globals.css sets `font: var(--t-body)`, which silently drops letter-spacing; it must
    // also carry the matching --ls-body or DESIGN.md's tracking is lost in the one place
    // Task 1 consumes a type token today.
    expect(globalsCss).toMatch(/font:\s*var\(--t-body\);/)
    expect(globalsCss).toMatch(/letter-spacing:\s*var\(--ls-body\);/)
  })
})

describe('vendored IBM Plex Sans woff2 files', () => {
  const fontsDir = path.resolve(__dirname, '../fonts')

  it.each([
    ['IBMPlexSans-Light.woff2', 300],
    ['IBMPlexSans-Regular.woff2', 400],
    ['IBMPlexSans-SemiBold.woff2', 600],
  ])('%s (weight %i) is a genuine, non-truncated WOFF2 file', (filename) => {
    const buffer = readFileSync(path.join(fontsDir, filename))

    // WOFF2 files open with the magic bytes 'wOF2' (0x774F4632). A truncated download or an
    // HTML error page saved with a .woff2 extension will fail this check loudly instead of
    // silently shipping a font that falls back to the system font for missing glyphs.
    expect(buffer.subarray(0, 4).toString('ascii')).toBe('wOF2')

    // The Latin-1-only subset that shipped without U+20B1 (peso) was ~20-22KB. The "complete"
    // build that adds the Currency Symbols block (including U+20B1) is ~63-67KB. A regression
    // back to a truncated or stripped-down subset would produce a suspiciously small file;
    // guard the floor so that regression fails the suite instead of shipping silently.
    // (Full cmap coverage of U+20B1 is verified out-of-band with fc-scan — see the task
    // report — since parsing a woff2 cmap in-test would require adding a font-parsing
    // dependency, which the task explicitly avoids.)
    expect(buffer.length).toBeGreaterThan(40_000)
  })
})
