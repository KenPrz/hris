import { describe, expect, it } from 'vitest'

import { formatCentavos, formatCentavosPlain } from './money'

describe('formatCentavos', () => {
  it('renders pesos with a symbol, a group separator and two decimals', () => {
    expect(formatCentavos(123456)).toBe('₱1,234.56')
    expect(formatCentavos(0)).toBe('₱0.00')
    expect(formatCentavos(5)).toBe('₱0.05')
  })

  it('renders large amounts', () => {
    expect(formatCentavos(123456789)).toBe('₱1,234,567.89')
  })

  it('renders negatives with the sign before the symbol', () => {
    expect(formatCentavos(-50000)).toBe('-₱500.00')
  })

  it('rejects a non-integer, because money is integer centavos', () => {
    expect(() => formatCentavos(1.5)).toThrow(/integer/)
  })
})

describe('formatCentavosPlain', () => {
  it('omits the symbol for tables that carry a currency column header', () => {
    expect(formatCentavosPlain(123456)).toBe('1,234.56')
    expect(formatCentavosPlain(-50000)).toBe('-500.00')
  })
})
