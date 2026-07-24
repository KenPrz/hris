import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { AttendanceLog } from '@/lib/api'
import { DayCell } from './DayCell'

function punch(overrides: Partial<AttendanceLog> = {}): AttendanceLog {
  return {
    id: 'p1',
    employee_id: 'e1',
    office_id: 'o1',
    punched_at: '2026-07-20T08:02:00+08:00',
    direction: 'in',
    source: 'web',
    verification: 'verified',
    flag_reason: null,
    ...overrides,
  }
}

describe('DayCell', () => {
  it('renders the day number', () => {
    render(<DayCell date="2026-07-20" punches={[]} timeZone="Asia/Manila" />)

    expect(screen.getByText('20')).toBeInTheDocument()
  })

  it('renders a shift as one compact span of both real times, in the office zone', () => {
    const punches = [
      punch({ id: 'in', direction: 'in', punched_at: '2026-07-20T08:02:00+08:00' }),
      punch({ id: 'out', direction: 'out', punched_at: '2026-07-20T17:05:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // "08:02–17:05" — both times, joined, rendered in Asia/Manila (not the wire enum,
    // not the viewer's zone). The dash char is incidental, so match around it.
    expect(screen.getByText(/08:02.*17:05/)).toBeInTheDocument()
  })

  it('renders a punch instant in the office zone even when it crosses a UTC day boundary', () => {
    // 2026-07-19T16:30Z is 2026-07-20 00:30 in Manila — the day-shift bug the whole date
    // layer prevents. The cell shows 00:30, on the 20th.
    const punches = [punch({ id: 'in', direction: 'in', punched_at: '2026-07-19T16:30:00Z' })]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" isToday />)

    expect(screen.getByText(/00:30/)).toBeInTheDocument()
  })

  it('shows the summed total for a day that pairs cleanly', () => {
    const punches = [
      punch({ id: 'in1', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'out1', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'in2', direction: 'in', punched_at: '2026-07-20T13:00:00+08:00' }),
      punch({ id: 'out2', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // Two spans, 4h + 4h = 8h total.
    expect(screen.getByText(/08:00.*12:00/)).toBeInTheDocument()
    expect(screen.getByText(/13:00.*17:00/)).toBeInTheDocument()
    expect(screen.getByText('8h')).toBeInTheDocument()
  })

  it('collapses a very busy day into the first spans plus a "+N more"', () => {
    // Five clean spans; only three draw, the rest collapse — so the cell stays the same
    // height as every other cell in the grid.
    const punches = Array.from({ length: 10 }, (_, i) =>
      punch({
        id: `p${i}`,
        direction: i % 2 === 0 ? 'in' : 'out',
        punched_at: `2026-07-20T${String(6 + i).padStart(2, '0')}:00:00+08:00`,
      }),
    )

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    expect(screen.getByText('+2 more')).toBeInTheDocument()
  })

  it('treats an even but mis-ordered day (in, in, out, out) as unpaired rather than cross-matching', () => {
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'in', punched_at: '2026-07-20T09:00:00+08:00' }),
      punch({ id: 'c', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'd', direction: 'out', punched_at: '2026-07-20T18:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // No total is guessed (a cross-matching pairer would have produced one), and the day
    // is flagged for attention.
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).toBeNull()
    expect(screen.getByText(/unpaired/i)).toBeInTheDocument()
  })

  it('omits the total for an odd, unpaired past day (a forgotten clock-out)', () => {
    const punches = [
      punch({ id: 'in1', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'out1', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'in2', direction: 'in', punched_at: '2026-07-20T13:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // The completed session still shows; the open one shows with no close.
    expect(screen.getByText(/08:00.*12:00/)).toBeInTheDocument()
    // On a past day (no isToday), a trailing open `in` is a forgotten clock-out — warn,
    // and never a guessed total.
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).toBeNull()
    expect(screen.getByText(/unpaired/i)).toBeInTheDocument()
  })

  it('reads an open shift on today as "in progress", not the unpaired warning', () => {
    // The exact shape the first browser pass caught: one completed session plus a
    // trailing `in` you haven't clocked out of because you're still working. The hero
    // says "clocked in"; the cell must not contradict it with a warning.
    const punches = [
      punch({ id: 'in1', direction: 'in', punched_at: '2026-07-24T08:00:00+08:00' }),
      punch({ id: 'out1', direction: 'out', punched_at: '2026-07-24T12:00:00+08:00' }),
      punch({ id: 'in2', direction: 'in', punched_at: '2026-07-24T13:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-24" punches={punches} timeZone="Asia/Manila" isToday />)

    expect(screen.getByText(/in progress/i)).toBeInTheDocument()
    expect(screen.queryByText(/unpaired/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).toBeNull()
  })

  it('marks a day with a flagged punch, keeping the reason on hover', () => {
    const punches = [
      punch({ id: 'in', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({
        id: 'out',
        direction: 'out',
        punched_at: '2026-07-20T17:00:00+08:00',
        verification: 'flagged',
        flag_reason: 'ip_not_allowlisted',
      }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    expect(screen.getByText('Flagged')).toBeInTheDocument()
    // The specific reason isn't spent on the dense grid, but it's not lost — it's the
    // hover title, so a curious HR admin can still see why.
    expect(screen.getByTitle('ip_not_allowlisted')).toBeInTheDocument()
  })

  it('renders nothing punch-wise for a day with no punches', () => {
    render(<DayCell date="2026-07-20" punches={[]} timeZone="Asia/Manila" />)

    expect(screen.queryByText(/–/)).toBeNull()
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).toBeNull()
    expect(screen.queryByText(/unpaired/i)).toBeNull()
  })
})
