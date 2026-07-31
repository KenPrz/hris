import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'

import { RequestCard } from './RequestCard'

function requestRecord(overrides: Partial<RequestRecord> = {}): RequestRecord {
  return {
    id: 'r1',
    type: 'attendance_adjustment',
    state: 'pending',
    note: 'Forgot to punch out',
    employee_id: 'e1',
    detail: {
      operation: 'add',
      target_log_id: null,
      direction: 'out',
      punched_at: '2026-07-20T18:00:00+08:00',
    },
    decided_by: null,
    decided_at: null,
    decision_note: null,
    has_attachment: false,
    ...overrides,
  }
}

describe('RequestCard', () => {
  it('renders the attendance-adjustment summary and the requester note', () => {
    render(<RequestCard request={requestRecord()} onApprove={vi.fn()} onReject={vi.fn()} pending={false} />)

    expect(screen.getByText('Add OUT punch at 18:00')).toBeInTheDocument()
    expect(screen.getByText('Forgot to punch out')).toBeInTheDocument()
  })

  it('links the requester id to their personnel file (M10a fix round 2)', () => {
    render(<RequestCard request={requestRecord({ employee_id: 'emp-42' })} onApprove={vi.fn()} onReject={vi.fn()} pending={false} />)

    expect(screen.getByRole('link', { name: 'emp-42' })).toHaveAttribute('href', '/employees/emp-42/profile')
  })

  it('summarizes a void as "Void the <time> <DIRECTION>"', () => {
    render(
      <RequestCard
        request={requestRecord({
          detail: { operation: 'void', target_log_id: 'log-1', direction: 'in', punched_at: '2026-07-20T08:00:00+08:00' },
        })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )

    expect(screen.getByText('Void the 08:00 IN')).toBeInTheDocument()
  })

  it('summarizes an amend as "Amend to <time>"', () => {
    render(
      <RequestCard
        request={requestRecord({
          detail: { operation: 'amend', target_log_id: 'log-1', direction: 'in', punched_at: '2026-07-20T08:15:00+08:00' },
        })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )

    expect(screen.getByText('Amend to 08:15')).toBeInTheDocument()
  })

  it('summarizes a leave request as "<span> · <day part> · <cost>"', () => {
    render(
      <RequestCard
        request={requestRecord({
          type: 'leave',
          detail: {
            leave_type_id: 'lt1',
            start_date: '2026-08-10',
            end_date: '2026-08-12',
            day_part: 'full',
            amount_minutes: 1440,
          },
        })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )

    expect(screen.getByText('Aug 10–12 · full day · 3 days')).toBeInTheDocument()
    expect(screen.getByText('Leave')).toBeInTheDocument()
  })

  it('summarizes a half-day leave request', () => {
    render(
      <RequestCard
        request={requestRecord({
          type: 'leave',
          detail: {
            leave_type_id: 'lt1',
            start_date: '2026-08-10',
            end_date: '2026-08-10',
            day_part: 'half',
            amount_minutes: 240,
          },
        })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )

    expect(screen.getByText('Aug 10 · half day · 4 hrs')).toBeInTheDocument()
  })

  it('summarizes an overtime request as "<duration> overtime · <date>"', () => {
    render(
      <RequestCard
        request={requestRecord({
          type: 'overtime',
          detail: { date: '2026-07-15', minutes: 150 },
        })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )

    expect(screen.getByText('2h 30m overtime · Jul 15')).toBeInTheDocument()
    expect(screen.getByText('Overtime')).toBeInTheDocument()
  })

  it('renders a "Pending" tag for a pending request and a distinct "Awaiting HR" tag for manager_approved', () => {
    const { rerender } = render(
      <RequestCard request={requestRecord({ state: 'pending' })} onApprove={vi.fn()} onReject={vi.fn()} pending={false} />,
    )
    expect(screen.getByText('Pending')).toBeInTheDocument()
    expect(screen.queryByText('Awaiting HR')).not.toBeInTheDocument()

    rerender(
      <RequestCard
        request={requestRecord({ state: 'manager_approved' })}
        onApprove={vi.fn()}
        onReject={vi.fn()}
        pending={false}
      />,
    )
    expect(screen.getByText('Awaiting HR')).toBeInTheDocument()
    expect(screen.queryByText('Pending')).not.toBeInTheDocument()
  })

  it('clicking Approve calls onApprove', () => {
    const onApprove = vi.fn()
    render(<RequestCard request={requestRecord()} onApprove={onApprove} onReject={vi.fn()} pending={false} />)

    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))

    expect(onApprove).toHaveBeenCalledTimes(1)
  })

  it('Reject requires a note before it calls onReject(note)', () => {
    const onReject = vi.fn()
    render(<RequestCard request={requestRecord()} onApprove={vi.fn()} onReject={onReject} pending={false} />)

    fireEvent.click(screen.getByRole('button', { name: 'Reject' }))

    const confirmButton = screen.getByRole('button', { name: 'Confirm reject' })
    expect(confirmButton).toBeDisabled()

    fireEvent.click(confirmButton)
    expect(onReject).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Reason for rejecting'), { target: { value: 'Not enough evidence' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm reject' }))

    expect(onReject).toHaveBeenCalledWith('Not enough evidence')
  })

  it('Cancel backs out of the reject flow without calling onReject', () => {
    const onReject = vi.fn()
    render(<RequestCard request={requestRecord()} onApprove={vi.fn()} onReject={onReject} pending={false} />)

    fireEvent.click(screen.getByRole('button', { name: 'Reject' }))
    fireEvent.change(screen.getByLabelText('Reason for rejecting'), { target: { value: 'draft note' } })
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByLabelText('Reason for rejecting')).not.toBeInTheDocument()
    expect(onReject).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Reject' })).toBeInTheDocument()
  })

  it('shows a Download attachment control when has_attachment is true, and none otherwise', () => {
    const { rerender } = render(
      <RequestCard request={requestRecord({ has_attachment: false })} onApprove={vi.fn()} onReject={vi.fn()} pending={false} />,
    )
    expect(screen.queryByRole('button', { name: 'Download attachment' })).not.toBeInTheDocument()

    rerender(<RequestCard request={requestRecord({ has_attachment: true })} onApprove={vi.fn()} onReject={vi.fn()} pending={false} />)
    expect(screen.getByRole('button', { name: 'Download attachment' })).toBeInTheDocument()
  })

  it('disables Approve/Reject while pending', () => {
    render(<RequestCard request={requestRecord()} onApprove={vi.fn()} onReject={vi.fn()} pending />)

    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Reject' })).toBeDisabled()
  })
})
