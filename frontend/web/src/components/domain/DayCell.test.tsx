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

  it('lists both punch times in office-local time — the ledger, not just a summary', () => {
    const punches = [
      punch({ id: 'in', direction: 'in', punched_at: '2026-07-20T08:02:00+08:00' }),
      punch({ id: 'out', direction: 'out', punched_at: '2026-07-20T17:05:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    expect(screen.getByText(/in.*08:02/i)).toBeInTheDocument()
    expect(screen.getByText(/out.*17:05/i)).toBeInTheDocument()
  })

  it('treats an even but mis-ordered day (in, in, out, out) as unpaired rather than cross-matching', () => {
    // The count is even, so an ordering-blind pairer would happily invent a total.
    // Two people's shifts, a device replay, a missed clock-out followed by a fresh
    // clock-in — all land here, and none of them mean "one span". Show the punches.
    const punches = [
      punch({ id: 'a', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'b', direction: 'in', punched_at: '2026-07-20T09:00:00+08:00' }),
      punch({ id: 'c', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
      punch({ id: 'd', direction: 'out', punched_at: '2026-07-20T18:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // Every punch still visible — the ledger is never occluded.
    expect(screen.getByText(/in.*08:00/i)).toBeInTheDocument()
    expect(screen.getByText(/out.*18:00/i)).toBeInTheDocument()
    // But no total was guessed (a cross-matching pairer would have produced 9h/18h).
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).toBeNull()
    expect(screen.getByText(/unpaired/i)).toBeInTheDocument()
  })

  it('shows the total for a day that pairs cleanly', () => {
    const punches = [
      punch({ id: 'in', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'out', direction: 'out', punched_at: '2026-07-20T17:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    expect(screen.getByText('9h')).toBeInTheDocument()
  })

  it('renders the punches but omits the total for an odd, unpairable punch count', () => {
    const punches = [
      punch({ id: 'in1', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' }),
      punch({ id: 'out1', direction: 'out', punched_at: '2026-07-20T12:00:00+08:00' }),
      punch({ id: 'in2', direction: 'in', punched_at: '2026-07-20T13:00:00+08:00' }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    // The punches themselves are still shown honestly.
    expect(screen.getByText(/in.*08:00/i)).toBeInTheDocument()
    expect(screen.getByText(/out.*12:00/i)).toBeInTheDocument()
    expect(screen.getByText(/in.*13:00/i)).toBeInTheDocument()

    // But there is no invented total — a missing clock-out must not be papered over.
    expect(screen.queryByText('4h')).not.toBeInTheDocument()
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).not.toBeInTheDocument()
    // On a past day (no isToday), a trailing open `in` is a forgotten clock-out — warn.
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
    // Still no invented total on the cell — the running total lives in the hero.
    expect(screen.queryByText(/^\d+h(\s\d+m)?$/)).not.toBeInTheDocument()
  })

  it('surfaces the flag reason for a flagged punch as a warning tag', () => {
    const punches = [
      punch({
        id: 'flagged',
        direction: 'in',
        verification: 'flagged',
        flag_reason: 'Outside geofence',
      }),
    ]

    render(<DayCell date="2026-07-20" punches={punches} timeZone="Asia/Manila" />)

    expect(screen.getByText('Outside geofence')).toBeInTheDocument()
  })

  it('renders nothing punch-wise for a day with no punches', () => {
    render(<DayCell date="2026-07-20" punches={[]} timeZone="Asia/Manila" />)

    expect(screen.queryByText(/unpaired/i)).not.toBeInTheDocument()
  })
})
