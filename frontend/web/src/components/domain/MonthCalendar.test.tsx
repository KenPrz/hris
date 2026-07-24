import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { todayInZone } from '@/lib/date'
import { MonthCalendar } from './MonthCalendar'

describe('MonthCalendar', () => {
  it('uses grid semantics with Monday-first column headers a screen reader can navigate', () => {
    render(<MonthCalendar month="2026-07" timeZone="Asia/Manila" renderDay={() => null} />)

    expect(screen.getByRole('grid')).toBeInTheDocument()
    const headers = screen.getAllByRole('columnheader')
    expect(headers.map((header) => header.textContent)).toEqual([
      'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
    ])
  })

  it('calls renderDay once per day of the month, with isToday true only for the office-local today', () => {
    const renderDay = vi.fn(({ date }: { date: string; isToday: boolean; inMonth: boolean }) => date)
    const today = todayInZone('Asia/Manila')

    render(<MonthCalendar month="2026-07" timeZone="Asia/Manila" renderDay={renderDay} />)

    expect(renderDay).toHaveBeenCalledTimes(31)

    const calledWith = renderDay.mock.calls.map((call) => call[0])
    const expected = Array.from({ length: 31 }, (_, i) => {
      const date = `2026-07-${String(i + 1).padStart(2, '0')}`
      return { date, isToday: date === today, inMonth: true }
    })

    expect(calledWith).toEqual(expected)
  })

  it("places the 1st of the month in its correct weekday column (2026-07-01 is a Wednesday)", () => {
    render(
      <MonthCalendar
        month="2026-07"
        timeZone="Asia/Manila"
        renderDay={({ date }) => date.slice(8, 10)}
      />,
    )

    const firstCell = screen.getByText('01')
    const row = firstCell.closest('[role="row"]')
    expect(row).not.toBeNull()

    // Monday-first columns: Mon, Tue, Wed, Thu, Fri, Sat, Sun. 2026-07-01 is a
    // Wednesday, so it must be the 3rd cell in its row (two leading blanks before it).
    const cellIndex = Array.from(row!.children).indexOf(firstCell.closest('[role="gridcell"]')!)
    expect(cellIndex).toBe(2)
  })

  it("renders renderDay's returned node inside the correct day's gridcell", () => {
    render(
      <MonthCalendar
        month="2026-07"
        timeZone="Asia/Manila"
        renderDay={({ date }) => (date === '2026-07-20' ? <span>marker-20</span> : null)}
      />,
    )

    const marker = screen.getByText('marker-20')
    const day20Cell = screen.getByText('marker-20').closest('[role="gridcell"]')
    expect(day20Cell).not.toBeNull()
    expect(day20Cell).toContainElement(marker)
  })
})
