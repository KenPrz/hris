import { describe, expect, it } from 'vitest'

import { keys } from './keys'

describe('keys', () => {
  it('produces a stable session key', () => {
    expect(keys.session()).toEqual(['session'])
    expect(keys.session()).toEqual(keys.session())
  })

  it('produces a stable attendance-month key across calls', () => {
    expect(keys.attendance.month('2026-07')).toEqual(['attendance', 'month', '2026-07'])
    expect(keys.attendance.month('2026-07')).toEqual(keys.attendance.month('2026-07'))
  })

  it('is prefix-invalidatable: ["attendance"] matches every month key', () => {
    const monthKey: readonly unknown[] = keys.attendance.month('2026-07')
    const prefix: readonly unknown[] = ['attendance']

    expect(monthKey.slice(0, prefix.length)).toEqual(prefix)

    // A different month is still under the same prefix.
    const otherMonthKey: readonly unknown[] = keys.attendance.month('2027-01')
    expect(otherMonthKey.slice(0, prefix.length)).toEqual(prefix)
  })

  it('produces distinct keys for distinct months', () => {
    expect(keys.attendance.month('2026-07')).not.toEqual(keys.attendance.month('2026-08'))
  })
})
