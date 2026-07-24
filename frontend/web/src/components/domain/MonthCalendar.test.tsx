import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import type { AttendanceLog, AttendanceMonth } from '@/lib/api'
import { MonthCalendar } from './MonthCalendar'

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

describe('MonthCalendar', () => {
  it('uses grid semantics with Monday-first column headers a screen reader can navigate', () => {
    render(<MonthCalendar month="2026-07" days={{}} timeZone="Asia/Manila" />)

    expect(screen.getByRole('grid')).toBeInTheDocument()
    const headers = screen.getAllByRole('columnheader')
    expect(headers.map((header) => header.textContent)).toEqual([
      'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
    ])
  })

  it('renders all 31 day cells for 2026-07', () => {
    render(<MonthCalendar month="2026-07" days={{}} timeZone="Asia/Manila" />)

    for (let day = 1; day <= 31; day++) {
      expect(screen.getByText(String(day))).toBeInTheDocument()
    }
  })

  it("places the 1st of the month in its correct weekday column (2026-07-01 is a Wednesday)", () => {
    render(<MonthCalendar month="2026-07" days={{}} timeZone="Asia/Manila" />)

    const firstCell = screen.getByText('1')
    const row = firstCell.closest('[role="row"]')
    expect(row).not.toBeNull()

    // Monday-first columns: Mon, Tue, Wed, Thu, Fri, Sat, Sun. 2026-07-01 is a
    // Wednesday, so it must be the 3rd cell in its row (two leading blanks before it).
    const cellIndex = Array.from(row!.children).indexOf(firstCell.closest('[role="gridcell"]')!)
    expect(cellIndex).toBe(2)
  })

  it('renders a punch under its correct office-local date key', () => {
    const days: AttendanceMonth = {
      '2026-07-20': [punch({ punched_at: '2026-07-20T08:02:00+08:00' })],
    }

    render(<MonthCalendar month="2026-07" days={days} timeZone="Asia/Manila" />)

    expect(screen.getByText(/08:02/)).toBeInTheDocument()
  })

  it('renders a cross-zone punch on the Manila date/time, not the UTC one — the day-shift bug the date layer prevents', () => {
    // This instant is 2026-07-19 16:30 UTC — late evening the 19th in UTC — but
    // 2026-07-20 00:30 in Manila (UTC+8). The backend groups by office-local date, so
    // the AttendanceMonth key is already 2026-07-20; the calendar must render the
    // punch's wall-clock time as 00:30, in that Manila cell, never a UTC-shifted time.
    const days: AttendanceMonth = {
      '2026-07-20': [punch({ punched_at: '2026-07-19T16:30:00Z' })],
    }

    render(<MonthCalendar month="2026-07" days={days} timeZone="Asia/Manila" />)

    const punchTime = screen.getByText(/00:30/)
    expect(punchTime).toBeInTheDocument()

    // It must land under day 20, not day 19.
    const day20 = screen.getByText('20').closest('[role="gridcell"]')
    expect(day20).toContainElement(punchTime)
  })
})
