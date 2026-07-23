import { describe, expect, it } from 'vitest'

import { formatDuration, formatDurationDecimal } from './duration'

describe('formatDuration', () => {
  it('renders hours and minutes', () => {
    expect(formatDuration(450)).toBe('7h 30m')
    expect(formatDuration(140)).toBe('2h 20m')
  })

  it('drops the minutes part on a whole hour', () => {
    expect(formatDuration(60)).toBe('1h')
    expect(formatDuration(480)).toBe('8h')
  })

  it('drops the hours part under an hour', () => {
    expect(formatDuration(45)).toBe('45m')
    expect(formatDuration(0)).toBe('0m')
  })

  it('handles spans longer than a day', () => {
    // Night shifts and cumulative period totals both exceed 24h.
    expect(formatDuration(1500)).toBe('25h')
    expect(formatDuration(1501)).toBe('25h 1m')
  })

  it('rejects a non-integer, because worked time is integer minutes', () => {
    // 7.333… hours is exactly the value this whole system exists to never produce.
    expect(() => formatDuration(7.5)).toThrow(/integer/)
  })

  it('rejects a negative duration', () => {
    expect(() => formatDuration(-1)).toThrow(/negative/)
  })
})

describe('formatDurationDecimal', () => {
  it('renders decimal hours for payroll export columns only', () => {
    expect(formatDurationDecimal(450)).toBe('7.50')
    expect(formatDurationDecimal(480)).toBe('8.00')
    expect(formatDurationDecimal(440)).toBe('7.33')
  })
})
