import { describe, expect, it } from 'vitest'

import { bpToPercent, percentToBp } from './basisPoints'

describe('bpToPercent', () => {
  it('converts integer basis points to percent', () => {
    expect(bpToPercent(10000)).toBe(100)
    expect(bpToPercent(13000)).toBe(130)
    expect(bpToPercent(12500)).toBe(125)
  })

  it('is fractional when the bp value is not a round percent', () => {
    expect(bpToPercent(1250)).toBe(12.5)
    expect(bpToPercent(0)).toBe(0)
  })
})

describe('percentToBp', () => {
  it('converts percent back to integer basis points', () => {
    expect(percentToBp(100)).toBe(10000)
    expect(percentToBp(130)).toBe(13000)
    expect(percentToBp(125)).toBe(12500)
  })

  it('accepts a fractional percent and rounds to the nearest bp', () => {
    expect(percentToBp(12.5)).toBe(1250)
  })

  it('is an integer bp even given a percent with float drift', () => {
    const bp = percentToBp(33.33)
    expect(Number.isInteger(bp)).toBe(true)
    expect(bp).toBe(3333)
  })
})

describe('round-trips', () => {
  it('bp -> percent -> bp is lossless for round bp values', () => {
    for (const bp of [10000, 13000, 12500, 0, 1250, 3333]) {
      expect(percentToBp(bpToPercent(bp))).toBe(bp)
    }
  })
})
