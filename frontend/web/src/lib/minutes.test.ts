import { describe, expect, it } from 'vitest'

import { hhmmToMinutes, minutesToHHMM } from './minutes'

describe('minutesToHHMM', () => {
  it('renders a wall-clock minute as zero-padded HH:MM', () => {
    expect(minutesToHHMM(90)).toBe('01:30')
    expect(minutesToHHMM(480)).toBe('08:00')
    expect(minutesToHHMM(0)).toBe('00:00')
  })

  it('wraps a cross-midnight absolute minute (>=1440) back onto the wall clock', () => {
    // 1620 is 17:00 + 1440 (a night shift's stored end_minute) — the wall-clock time is
    // 03:00; the "+1 day" fact is the editor's concern, not this pure formatter's.
    expect(minutesToHHMM(1620)).toBe('03:00')
    expect(minutesToHHMM(1500)).toBe('01:00')
    expect(minutesToHHMM(1440)).toBe('00:00')
  })

  it('rejects a non-integer, because worked time is integer minutes', () => {
    expect(() => minutesToHHMM(90.5)).toThrow(/integer/)
  })

  it('rejects a negative minute', () => {
    expect(() => minutesToHHMM(-1)).toThrow(/negative/)
  })
})

describe('hhmmToMinutes', () => {
  it('parses HH:MM into minutes-from-midnight', () => {
    expect(hhmmToMinutes('08:00')).toBe(480)
    expect(hhmmToMinutes('00:00')).toBe(0)
    expect(hhmmToMinutes('23:59')).toBe(1439)
  })

  it('round-trips with minutesToHHMM for wall-clock values', () => {
    for (const minutes of [0, 1, 90, 480, 1020, 1439]) {
      expect(hhmmToMinutes(minutesToHHMM(minutes))).toBe(minutes)
    }
  })
})

describe('the cross-midnight end_minute mapping', () => {
  it('adds 1440 to an HH:MM end time when the shift crosses midnight', () => {
    // 17:00 start, 03:00 end, "crosses midnight" checked -> end_minute 1620.
    const start = hhmmToMinutes('17:00')
    const end = hhmmToMinutes('03:00') + 1440

    expect(start).toBe(1020)
    expect(end).toBe(1620)
  })
})
