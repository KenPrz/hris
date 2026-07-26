import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { RequestRecord } from '@/lib/api'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

const push = vi.fn()
const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/me/requests',
}))

vi.mock('@/hooks/useMyRequests', () => ({
  useMyRequests: vi.fn(),
}))

vi.mock('@/hooks/useDecideRequest', () => ({
  useDecideRequest: vi.fn(),
}))

import { useDecideRequest } from '@/hooks/useDecideRequest'
import { useMyRequests } from '@/hooks/useMyRequests'

import MyRequestsPage from './page'

const mockedUseMyRequests = vi.mocked(useMyRequests)
const mockedUseDecideRequest = vi.mocked(useDecideRequest)

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
})

function request(overrides: Partial<RequestRecord> = {}): RequestRecord {
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

/** Stubs `useMyRequests`'s return shape down to the fields the page reads. */
function stubRequests(overrides: Partial<ReturnType<typeof useMyRequests>> = {}): void {
  mockedUseMyRequests.mockReturnValue({
    data: undefined,
    isLoading: false,
    isError: false,
    ...overrides,
  } as unknown as ReturnType<typeof useMyRequests>)
}

/** Stubs `useDecideRequest`'s return shape down to `mutate`/`isPending`/`variables`. */
function stubDecide(overrides: Partial<ReturnType<typeof useDecideRequest>> = {}): ReturnType<typeof vi.fn> {
  const mutate = (overrides.mutate as ReturnType<typeof vi.fn>) ?? vi.fn()
  mockedUseDecideRequest.mockReturnValue({
    mutate,
    isPending: false,
    variables: undefined,
    ...overrides,
  } as unknown as ReturnType<typeof useDecideRequest>)
  return mutate
}

function stubSessionFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      if (url === '/api/v1/me' && method === 'GET') {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              user: { id: 'u1', email: 'a@b.com', name: 'A' },
              employee: { id: 'e1', employee_no: 'E-001', current_office_id: 'o1', current_department_id: null },
              is_system_admin: false,
              has_reports: false,
              hr_offices: [],
              permissions: [],
            },
          }),
        }
      }
      throw new Error(`Unhandled fetch in test: ${method} ${url}`)
    }),
  )
}

function renderPage() {
  setToken('sekrit')
  stubSessionFetch()
  return render(
    <Providers>
      <MyRequestsPage />
    </Providers>,
  )
}

describe('/me/requests', () => {
  it('shows a loading skeleton', async () => {
    stubRequests({ isLoading: true })
    stubDecide()

    renderPage()

    // Not `findByText` — the SideNav (M6a) now also links to `/me/requests` as "My
    // requests", so the plain text is ambiguous; the page's own title is the `h1`.
    await screen.findByRole('heading', { name: 'My requests' })
  })

  it('shows an empty state when there are no requests', async () => {
    stubRequests({ data: [] })
    stubDecide()

    renderPage()

    expect(await screen.findByText("You haven't filed any requests.")).toBeInTheDocument()
  })

  it('renders a pending and an approved request, each with its state, and only the pending one has a Withdraw button', async () => {
    stubRequests({
      data: [
        request({ id: 'r-pending', state: 'pending', note: 'Forgot to punch out' }),
        request({
          id: 'r-approved',
          state: 'approved',
          note: 'Missed morning punch',
          decided_by: 'mgr1',
          decided_at: '2026-07-21T10:00:00+08:00',
          decision_note: 'Looks right, approved.',
        }),
      ],
    })
    stubDecide()

    renderPage()

    expect(await screen.findByText('Forgot to punch out')).toBeInTheDocument()
    expect(screen.getByText('Missed morning punch')).toBeInTheDocument()
    expect(screen.getByText('Pending')).toBeInTheDocument()
    expect(screen.getByText('Approved')).toBeInTheDocument()
    expect(screen.getByText('Decision note: Looks right, approved.')).toBeInTheDocument()

    const withdrawButtons = screen.getAllByRole('button', { name: 'Withdraw' })
    expect(withdrawButtons).toHaveLength(1)
  })

  it('clicking Withdraw calls the cancel mutation with the pending request\'s id', async () => {
    stubRequests({
      data: [
        request({ id: 'r-pending', state: 'pending' }),
        request({ id: 'r-approved', state: 'approved', decision_note: 'ok' }),
      ],
    })
    const mutate = stubDecide()

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Withdraw' }))

    expect(mutate).toHaveBeenCalledWith({ id: 'r-pending', action: 'cancel' })
  })
})
