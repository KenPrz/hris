import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'
import { keys } from '@/lib/keys'
import { clearToken, setToken } from '@/lib/session'
import { SessionProvider } from '@/components/SessionProvider'

const push = vi.fn()
const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/team/approvals',
}))

import TeamApprovalsPage from './page'
// A relative import across the team/office split on purpose — this is the ONE test file
// covering both queue pages, since they share every bit of behavior this suite exercises
// (the optimistic mutation, loading, empty) and differ only in scope/query key.
import OfficeApprovalsPage from '../../office/approvals/page'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
})

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

function sessionBody() {
  return {
    data: {
      user: { id: 'u1', email: 'mgr@x.com', name: 'Manager' },
      employee: { id: 'e-mgr', employee_no: 'E-000', current_office_id: 'o1', current_department_id: null },
      is_system_admin: false,
      has_reports: true,
      hr_offices: ['o1'],
      permissions: [],
    },
  }
}

interface Deferred<T> {
  promise: Promise<T>
  resolve: (value: T) => void
  reject: (reason?: unknown) => void
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

function renderQueuePage(page: ReactElement, client: QueryClient) {
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider>{page}</SessionProvider>
    </QueryClientProvider>,
  )
}

describe('/team/approvals — optimistic decide (the one place optimistic updates are allowed)', () => {
  it('approving a request removes its card immediately, before the POST resolves', async () => {
    const client = newClient()
    const setQueryDataSpy = vi.spyOn(client, 'setQueryData')

    let teamApprovalsCalls = 0
    const approveDeferred = deferred<{ ok: boolean; status: number; json: () => Promise<unknown> }>()

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'

        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/team/approvals' && method === 'GET') {
          teamApprovalsCalls += 1
          if (teamApprovalsCalls === 1) {
            return {
              ok: true,
              status: 200,
              json: async () => ({
                data: [requestRecord({ id: 'r1' }), requestRecord({ id: 'r2', note: 'Missed a punch' })],
              }),
            }
          }
          // Any refetch triggered by onSettled's invalidation is held open — the card's
          // disappearance below can only be explained by the optimistic cache write, never
          // by this resolving.
          return new Promise(() => {})
        }
        if (url === '/api/v1/requests/r1/approve' && method === 'POST') {
          return approveDeferred.promise
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    renderQueuePage(<TeamApprovalsPage />, client)

    expect(await screen.findByText('Forgot to punch out')).toBeInTheDocument()
    expect(screen.getByText('Missed a punch')).toBeInTheDocument()

    fireEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0])

    // Optimistic removal: the r1 card is gone while the approve POST is still pending.
    await waitFor(() => expect(screen.queryByText('Forgot to punch out')).not.toBeInTheDocument())
    expect(screen.getByText('Missed a punch')).toBeInTheDocument()

    expect(setQueryDataSpy).toHaveBeenCalledWith(keys.requests.teamApprovals(), expect.any(Function))

    // Let the network call resolve so nothing dangles past the test.
    approveDeferred.resolve({
      ok: true,
      status: 200,
      json: async () => ({ data: requestRecord({ id: 'r1', state: 'approved' }) }),
    })
    await waitFor(() => expect(teamApprovalsCalls).toBeGreaterThan(1))
  })

  it('a rejected decision rolls back — the card reappears', async () => {
    const client = newClient()

    let teamApprovalsCalls = 0
    const rejectDeferred = deferred<never>()

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'

        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/team/approvals' && method === 'GET') {
          teamApprovalsCalls += 1
          if (teamApprovalsCalls === 1) {
            return {
              ok: true,
              status: 200,
              json: async () => ({
                data: [requestRecord({ id: 'r1' }), requestRecord({ id: 'r2', note: 'Missed a punch' })],
              }),
            }
          }
          // Held open — reappearance below must come from onError's rollback, not from
          // this refetch completing with fresh (still-two-item) data.
          return new Promise(() => {})
        }
        if (url === '/api/v1/requests/r2/reject' && method === 'POST') {
          return rejectDeferred.promise
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    renderQueuePage(<TeamApprovalsPage />, client)

    expect(await screen.findByText('Missed a punch')).toBeInTheDocument()

    const rejectButtons = screen.getAllByRole('button', { name: 'Reject' })
    fireEvent.click(rejectButtons[1])
    fireEvent.change(screen.getByLabelText('Reason for rejecting'), { target: { value: 'Not enough evidence' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm reject' }))

    // Optimistic removal happens first.
    await waitFor(() => expect(screen.queryByText('Missed a punch')).not.toBeInTheDocument())

    // The network call fails — onError must restore the snapshot.
    rejectDeferred.reject(new Error('network down'))

    await waitFor(() => expect(screen.getByText('Missed a punch')).toBeInTheDocument())
  })

  it('shows a loading skeleton while the queue is fetching', async () => {
    const client = newClient()

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/team/approvals' && method === 'GET') {
          return new Promise(() => {})
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    const { container } = renderQueuePage(<TeamApprovalsPage />, client)

    await screen.findByText('Approvals')
    expect(screen.queryByText('Nothing awaiting your approval.')).not.toBeInTheDocument()
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull()
  })
})

describe('/office/approvals — same queue behavior, office scope', () => {
  it('shows the empty state when there is nothing to approve', async () => {
    const client = newClient()

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/office/approvals' && method === 'GET') {
          return { ok: true, status: 200, json: async () => ({ data: [] }) }
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    renderQueuePage(<OfficeApprovalsPage />, client)

    expect(await screen.findByText('Nothing awaiting your approval.')).toBeInTheDocument()
  })

  it("approving a request calls POST /requests/{id}/approve and keys its optimistic write off officeApprovals()", async () => {
    const client = newClient()
    const setQueryDataSpy = vi.spyOn(client, 'setQueryData')

    let officeApprovalsCalls = 0
    const approveDeferred = deferred<{ ok: boolean; status: number; json: () => Promise<unknown> }>()

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/office/approvals' && method === 'GET') {
          officeApprovalsCalls += 1
          if (officeApprovalsCalls === 1) {
            return { ok: true, status: 200, json: async () => ({ data: [requestRecord({ id: 'r9' })] }) }
          }
          return new Promise(() => {})
        }
        if (url === '/api/v1/requests/r9/approve' && method === 'POST') {
          return approveDeferred.promise
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    renderQueuePage(<OfficeApprovalsPage />, client)

    expect(await screen.findByText('Forgot to punch out')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))

    await waitFor(() => expect(screen.queryByText('Forgot to punch out')).not.toBeInTheDocument())
    expect(setQueryDataSpy).toHaveBeenCalledWith(keys.requests.officeApprovals(), expect.any(Function))

    approveDeferred.resolve({
      ok: true,
      status: 200,
      json: async () => ({ data: requestRecord({ id: 'r9', state: 'approved' }) }),
    })
    await waitFor(() => expect(officeApprovalsCalls).toBeGreaterThan(1))
  })

  it('a cutoff_locked refusal rolls the card back AND surfaces the domain error message', async () => {
    const client = newClient()

    let officeApprovalsCalls = 0

    setToken('sekrit')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        if (url === '/api/v1/me' && method === 'GET') {
          return { ok: true, status: 200, json: async () => sessionBody() }
        }
        if (url === '/api/v1/office/approvals' && method === 'GET') {
          officeApprovalsCalls += 1
          // Both the first load and the onSettled refetch return the same one item — so the
          // card's reappearance is the rollback + a queue still holding it, and the message
          // below is the only thing that could have come from the failed POST.
          return { ok: true, status: 200, json: async () => ({ data: [requestRecord({ id: 'r9' })] }) }
        }
        if (url === '/api/v1/requests/r9/approve' && method === 'POST') {
          return {
            ok: false,
            status: 422,
            json: async () => ({
              error: {
                code: 'cutoff_locked',
                message: 'This day falls in a closed cutoff period and can no longer be changed.',
                details: { date: '2026-07-20' },
              },
            }),
          }
        }
        throw new Error(`Unhandled fetch in test: ${method} ${url}`)
      }),
    )

    renderQueuePage(<OfficeApprovalsPage />, client)

    expect(await screen.findByText('Forgot to punch out')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))

    // The domain error's own message reaches the approver, and the card is back to decide again.
    expect(
      await screen.findByText('This day falls in a closed cutoff period and can no longer be changed.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Forgot to punch out')).toBeInTheDocument()
    expect(officeApprovalsCalls).toBeGreaterThan(1)
  })
})
