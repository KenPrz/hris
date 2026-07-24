import { describe, expect, it } from 'vitest'

import type { AttendanceLog } from './api'
import { pairPunches, sortByPunchedAt } from './punches'

function punch(overrides: Partial<AttendanceLog> = {}): AttendanceLog {
  return {
    id: 'p1',
    employee_id: 'e1',
    office_id: 'o1',
    punched_at: '2026-07-20T08:00:00+08:00',
    direction: 'in',
    source: 'web',
    verification: 'verified',
    flag_reason: null,
    ...overrides,
  }
}

describe('sortByPunchedAt', () => {
  it('sorts chronologically regardless of input order', () => {
    const punches = [
      punch({ id: 'b', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'a', punched_at: '2026-07-20T08:00:00+08:00' }),
    ]

    expect(sortByPunchedAt(punches).map((p) => p.id)).toEqual(['a', 'b'])
  })

  it('does not mutate the input array', () => {
    const punches = [
      punch({ id: 'b', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'a', punched_at: '2026-07-20T08:00:00+08:00' }),
    ]
    const original = [...punches]

    sortByPunchedAt(punches)

    expect(punches).toEqual(original)
  })
})

describe('pairPunches', () => {
  it('returns "none" for an empty day', () => {
    expect(pairPunches([])).toEqual({ kind: 'none' })
  })

  it('sums a single clean in/out pair', () => {
    const punches = [
      punch({ id: 'in', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'out', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({ kind: 'paired', totalMinutes: 540 })
  })

  it('sums multiple clean pairs (e.g. a lunch break)', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'c', direction: 'in', punched_at: '2026-07-20T13:00:00+08:00' }),
      punch({ id: 'd', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({ kind: 'paired', totalMinutes: 480 })
  })

  it('reports "open" for a trailing unclosed in — a shift genuinely in progress', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'c', direction: 'in', punched_at: '2026-07-20T13:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({
      kind: 'open',
      completedMinutes: 240,
      openSince: '2026-07-20T13:00:00+08:00',
    })
  })

  it('reports "open" for a single lone `in` (the very start of a shift)', () => {
    const punches = [punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' })]

    expect(pairPunches(punches)).toEqual({
      kind: 'open',
      completedMinutes: 0,
      openSince: '2026-07-20T08:00:00+08:00',
    })
  })

  it('reports "unpaired" for an odd count ending on a dangling `out` (FINDING 2 example: in, out, out)', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'c', direction: 'out', punched_at: '2026-07-20T13:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({ kind: 'unpaired' })
  })

  it('reports "unpaired" for an even but mis-ordered day (in, in, out, out) — never cross-matches', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'in', punched_at: '2026-07-20T09:00:00+08:00' }),
      punch({ id: 'c', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'd', direction: 'out', punched_at: '2026-07-20T18:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({ kind: 'unpaired' })
  })

  it('reports "unpaired" for a negative span (an out before its in)', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'b', direction: 'out', punched_at: '2026-07-20T08:00:00+08:00' }),
    ]

    expect(pairPunches(punches)).toEqual({ kind: 'unpaired' })
  })
})
