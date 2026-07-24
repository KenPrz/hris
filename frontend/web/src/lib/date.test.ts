import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  addMonths,
  currentMonth,
  daysInMonth,
  monthLabel,
  timeInZone,
  todayInZone,
  weekdayIndex,
} from './date'

describe('addMonths', () => {
  it('rolls over into the next year', () => {
    expect(addMonths('2026-12', 1)).toBe('2027-01')
  })

  it('rolls back into the previous year', () => {
    expect(addMonths('2026-01', -1)).toBe('2025-12')
  })

  it('handles a forward delta greater than 12', () => {
    // 2026-01 + 14 months = 2027-03
    expect(addMonths('2026-01', 14)).toBe('2027-03')
  })

  it('handles a backward delta greater than 12', () => {
    // 2026-01 - 14 months = 2024-11
    expect(addMonths('2026-01', -14)).toBe('2024-11')
  })

  it('handles a delta of zero', () => {
    expect(addMonths('2026-07', 0)).toBe('2026-07')
  })

  it('handles a mid-year delta with no rollover', () => {
    expect(addMonths('2026-03', 2)).toBe('2026-05')
  })
})

describe('monthLabel', () => {
  it('renders a human month/year label', () => {
    expect(monthLabel('2026-07')).toBe('July 2026')
    expect(monthLabel('2026-01')).toBe('January 2026')
    expect(monthLabel('2026-12')).toBe('December 2026')
  })
})

describe('daysInMonth', () => {
  it('has 28 entries for a non-leap February', () => {
    const days = daysInMonth('2026-02')
    expect(days).toHaveLength(28)
    expect(days[0]).toBe('2026-02-01')
    expect(days[27]).toBe('2026-02-28')
  })

  it('has 29 entries for a leap February', () => {
    const days = daysInMonth('2028-02')
    expect(days).toHaveLength(29)
    expect(days[28]).toBe('2028-02-29')
  })

  it('has 31 entries for a 31-day month', () => {
    const days = daysInMonth('2026-07')
    expect(days).toHaveLength(31)
    expect(days[30]).toBe('2026-07-31')
  })

  it('has 30 entries for a 30-day month', () => {
    const days = daysInMonth('2026-04')
    expect(days).toHaveLength(30)
    expect(days[29]).toBe('2026-04-30')
  })

  it('returns every day in order as YYYY-MM-DD strings', () => {
    const days = daysInMonth('2026-09')
    expect(days).toEqual([
      '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05',
      '2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10',
      '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15',
      '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20',
      '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25',
      '2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30',
    ])
  })
})

describe('weekdayIndex', () => {
  // Confirmed independently (date -d 2026-07-20 +%A, and Python's datetime): Monday.
  it('is 0 for a known Monday', () => {
    expect(weekdayIndex('2026-07-20')).toBe(0)
  })

  it('is 6 for the following Sunday', () => {
    expect(weekdayIndex('2026-07-26')).toBe(6)
  })

  it('walks Tue..Sat as 1..5 across the same week', () => {
    expect(weekdayIndex('2026-07-21')).toBe(1) // Tuesday
    expect(weekdayIndex('2026-07-22')).toBe(2) // Wednesday
    expect(weekdayIndex('2026-07-23')).toBe(3) // Thursday
    expect(weekdayIndex('2026-07-24')).toBe(4) // Friday
    expect(weekdayIndex('2026-07-25')).toBe(5) // Saturday
  })
})

describe('timeInZone', () => {
  it('renders a Manila midnight punch as 00:30, on the 20th — not the 19th', () => {
    const iso = '2026-07-20T00:30:00+08:00'

    expect(timeInZone(iso, 'Asia/Manila')).toBe('00:30')

    // The whole reason dates are strings: the same instant, read naively in another
    // zone (e.g. via a Date's local getters), would land on the 19th. Confirm that a
    // date string derived independently for this instant in Manila is still the 20th.
    const manilaDate = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Manila',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(iso))
    expect(manilaDate).toBe('2026-07-20')
  })

  it('renders the same instant differently across zones (24h HH:mm)', () => {
    // 2026-07-19T16:30:00Z: 00:30 in Manila (UTC+8, next day) but 12:30 in New York (UTC-4, same day).
    const iso = '2026-07-19T16:30:00Z'
    expect(timeInZone(iso, 'Asia/Manila')).toBe('00:30')
    expect(timeInZone(iso, 'America/New_York')).toBe('12:30')
  })

  it('pads single-digit hours and minutes to two digits', () => {
    expect(timeInZone('2026-07-20T05:05:00Z', 'UTC')).toBe('05:05')
  })
})

describe('host-timezone independence', () => {
  // These functions must give the same, correct answer no matter what zone the host
  // process happens to be running in — they must never rely on the JS engine's
  // implicit local timezone. We prove this by passing explicit, different `timeZone`
  // arguments for a fixed instant and checking each produces the zone-correct,
  // *different* result — a vacuous test (same value regardless of zone) would not
  // catch a function that ignores its `timeZone` parameter and falls back to host-local.
  beforeEach(() => {
    // A fixed instant right at a day boundary in several zones: 2026-07-19T16:30:00Z.
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-19T16:30:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('todayInZone gives different, zone-correct calendar dates for the same instant', () => {
    expect(todayInZone('Asia/Manila')).toBe('2026-07-20')
    expect(todayInZone('America/New_York')).toBe('2026-07-19')
    expect(todayInZone('Asia/Manila')).not.toBe(todayInZone('America/New_York'))
  })

  it('currentMonth gives different, zone-correct months when the instant straddles a month boundary', () => {
    vi.setSystemTime(new Date('2026-07-31T16:30:00Z'))
    // 2026-07-31T16:30Z = 2026-08-01T00:30 in Manila, but 2026-07-31T12:30 in New York.
    expect(currentMonth('Asia/Manila')).toBe('2026-08')
    expect(currentMonth('America/New_York')).toBe('2026-07')
  })

  it('timeInZone is unaffected by the host clock/zone, only by its explicit argument', () => {
    const iso = '2026-07-20T00:30:00+08:00'
    expect(timeInZone(iso, 'Asia/Manila')).toBe('00:30')
    expect(timeInZone(iso, 'America/New_York')).toBe('12:30')
  })
})
