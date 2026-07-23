import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(path.resolve(__dirname, './carbon.css'), 'utf8')

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
})
