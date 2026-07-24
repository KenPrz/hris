'use client'

import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import type { ShiftDay } from '@/lib/api'
import { WeekEditor } from './WeekEditor'

const LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

/** 7 working days, Mon..Sun, all identical unless overridden by index. */
function week(overrides: Partial<Record<number, Partial<ShiftDay>>> = {}): ShiftDay[] {
  return LABELS.map((_, weekday) => ({
    weekday: weekday as ShiftDay['weekday'],
    is_rest: false,
    start_minute: 480,
    end_minute: 1020,
    break_minutes: 60,
    ...overrides[weekday],
  }))
}

/** A thin stateful wrapper so a sequence of edits (start, then end, then break) composes
 * the way a real screen would — WeekEditor itself is fully controlled and takes no state
 * of its own. */
function Harness({ initial, onEmit }: { initial: ShiftDay[]; onEmit: (next: ShiftDay[]) => void }) {
  const [value, setValue] = useState(initial)
  return (
    <WeekEditor
      value={value}
      onChange={(next) => {
        setValue(next)
        onEmit(next)
      }}
    />
  )
}

describe('WeekEditor', () => {
  it('renders all 7 Mon..Sun rows', () => {
    render(<WeekEditor value={week()} onChange={() => {}} />)

    for (const label of LABELS) {
      expect(screen.getByText(label)).toBeInTheDocument()
    }
  })

  it('toggling a day to rest emits is_rest:true with null hours, leaving other days untouched', () => {
    const onChange = vi.fn()
    render(<WeekEditor value={week()} onChange={onChange} />)

    fireEvent.click(screen.getByLabelText('Mon rest day'))

    expect(onChange).toHaveBeenCalledTimes(1)
    const next = onChange.mock.calls[0][0] as ShiftDay[]
    expect(next[0]).toEqual({
      weekday: 0,
      is_rest: true,
      start_minute: null,
      end_minute: null,
      break_minutes: null,
    })
    // Every other day is unchanged.
    expect(next[1]).toEqual(week()[1])
  })

  it('hides the hour inputs for a rest day', () => {
    render(<WeekEditor value={week({ 0: { is_rest: true, start_minute: null, end_minute: null, break_minutes: null } })} onChange={() => {}} />)

    expect(screen.queryByLabelText('Mon start')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Mon end')).not.toBeInTheDocument()
  })

  it('entering 08:00 start / 18:00 end / 60 break on a working day emits the combined ShiftDay', () => {
    const onEmit = vi.fn()
    const initial = week({
      0: { start_minute: null, end_minute: null, break_minutes: null },
    })
    render(<Harness initial={initial} onEmit={onEmit} />)

    fireEvent.change(screen.getByLabelText('Mon start'), { target: { value: '08:00' } })
    fireEvent.change(screen.getByLabelText('Mon end'), { target: { value: '18:00' } })
    fireEvent.change(screen.getByLabelText('Mon break minutes'), { target: { value: '60' } })

    const last = onEmit.mock.calls[onEmit.mock.calls.length - 1][0] as ShiftDay[]
    expect(last[0]).toEqual({
      weekday: 0,
      is_rest: false,
      start_minute: 480,
      end_minute: 1080,
      break_minutes: 60,
    })
  })

  it('checking "crosses midnight" on a 17:00->03:00 row emits end_minute:1620 and shows the +1 day hint', () => {
    const onEmit = vi.fn()
    const initial = week({
      0: { start_minute: null, end_minute: null, break_minutes: null },
    })
    render(<Harness initial={initial} onEmit={onEmit} />)

    fireEvent.change(screen.getByLabelText('Mon start'), { target: { value: '17:00' } })
    fireEvent.change(screen.getByLabelText('Mon end'), { target: { value: '03:00' } })
    fireEvent.click(screen.getByLabelText('Mon crosses midnight'))

    const last = onEmit.mock.calls[onEmit.mock.calls.length - 1][0] as ShiftDay[]
    expect(last[0]).toEqual({
      weekday: 0,
      is_rest: false,
      start_minute: 1020,
      end_minute: 1620,
      break_minutes: null,
    })
    expect(screen.getByText('+1 day')).toBeInTheDocument()
  })

  it('does not cap end_minute at 1439 for a cross-midnight row', () => {
    render(
      <WeekEditor
        value={week({ 0: { start_minute: 1020, end_minute: 1620, break_minutes: 60 } })}
        onChange={() => {}}
      />,
    )

    // The wall-clock display wraps (03:00) but the underlying value stays 1620 — proven by
    // the hint being present and the round trip in the earlier test, not by reading the
    // native time input's value attribute (JSDOM normalizes it).
    expect(screen.getByText('+1 day')).toBeInTheDocument()
  })
})
