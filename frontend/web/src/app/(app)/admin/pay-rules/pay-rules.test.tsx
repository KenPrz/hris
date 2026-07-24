import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { PayRule } from '@/lib/api'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

const push = vi.fn()
const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/pay-rules',
}))

import PayRulesPage from './page'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
})

function payRule(overrides: Partial<PayRule> = {}): PayRule {
  return {
    id: 'pr-current',
    effective_from: '2026-01-01',
    overtime_ordinary_bp: 12500,
    overtime_premium_bp: 14000,
    night_diff_bp: 11000,
    note: null,
    day_rates: [
      { day_type: 'ordinary', worked_bp: 10000, worked_rest_bp: 13000, unworked_bp: 0 },
      { day_type: 'special_working', worked_bp: 10500, worked_rest_bp: 13500, unworked_bp: 0 },
      { day_type: 'special_non_working', worked_bp: 13000, worked_rest_bp: 15000, unworked_bp: 0 },
      { day_type: 'regular_holiday', worked_bp: 20000, worked_rest_bp: 26000, unworked_bp: 12000 },
      { day_type: 'double_regular_holiday', worked_bp: 30000, worked_rest_bp: 39000, unworked_bp: 20000 },
    ],
    ...overrides,
  }
}

function sessionBody(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      user: { id: 'u1', email: 'admin@x.com', name: 'Admin' },
      employee: null,
      is_system_admin: true,
      has_reports: false,
      hr_offices: [],
      permissions: [],
      ...overrides,
    },
  }
}

/** Routes GET /me, GET/POST /admin/pay-rules off one mock. `onCreate` may throw an
 * `ApiError`-shaped rejection (`{ status, code, message, details }`) to simulate a 422/409. */
function stubApi(options: {
  payRules?: PayRule[]
  onCreate?: (body: unknown) => PayRule | { status: number; code: string; message: string; details?: Record<string, unknown> }
}): ReturnType<typeof vi.fn> {
  const payRules = options.payRules ?? [payRule()]

  const fn = vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'

    if (url === '/api/v1/me' && method === 'GET') {
      return { ok: true, status: 200, json: async () => sessionBody() }
    }

    if (url === '/api/v1/admin/pay-rules' && method === 'GET') {
      return { ok: true, status: 200, json: async () => ({ data: payRules }) }
    }

    if (url === '/api/v1/admin/pay-rules' && method === 'POST') {
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onCreate?.(body) ?? payRule()

      if ('status' in result) {
        return {
          ok: false,
          status: result.status,
          json: async () => ({ error: { code: result.code, message: result.message, details: result.details ?? {} } }),
        }
      }

      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    throw new Error(`Unhandled fetch in test: ${method} ${url}`)
  })

  vi.stubGlobal('fetch', fn)
  return fn
}

function renderPage() {
  setToken('sekrit')
  return render(
    <Providers>
      <PayRulesPage />
    </Providers>,
  )
}

describe('/admin/pay-rules — the effective version matrix', () => {
  it('renders the currently-effective version (greatest effective_from <= today) as percentages, including an ordinary row', async () => {
    stubApi({
      payRules: [
        payRule({ id: 'pr-future', effective_from: '2099-01-01', overtime_ordinary_bp: 99999 }),
        payRule({ id: 'pr-current', effective_from: '2026-01-01' }),
        payRule({ id: 'pr-old', effective_from: '2025-01-01', overtime_ordinary_bp: 12000 }),
      ],
    })

    renderPage()

    expect(await screen.findByText('Ordinary')).toBeInTheDocument()
    expect(screen.getByText('Regular holiday')).toBeInTheDocument()

    // pr-current's values, as percentages — never the future version's.
    expect(screen.getByText('100%')).toBeInTheDocument() // ordinary worked_bp 10000
    expect(screen.getByText('260%')).toBeInTheDocument() // regular_holiday worked_rest_bp 26000
    expect(screen.getByText('125%')).toBeInTheDocument() // overtime_ordinary_bp 12500

    // Not the future version's overtime_ordinary_bp (999.99%).
    expect(screen.queryByText('999.99%')).not.toBeInTheDocument()

    // Version history lists every version, newest first.
    expect(screen.getByText('2099-01-01')).toBeInTheDocument()
    expect(screen.getByText('2025-01-01')).toBeInTheDocument()
  })

  it('shows an empty state when no version is effective yet', async () => {
    stubApi({ payRules: [payRule({ id: 'pr-future', effective_from: '2099-01-01' })] })

    renderPage()

    expect(await screen.findByText(/no.*effective/i)).toBeInTheDocument()
  })
})

describe('/admin/pay-rules — new version', () => {
  it('"New version" opens the dialog', async () => {
    stubApi({})

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New version' }))

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })

  it('submitting maps percent to bp and posts the full 5-day_rate matrix, then invalidates', async () => {
    const fetchMock = stubApi({})

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New version' }))
    await screen.findByRole('dialog')

    fireEvent.change(screen.getByLabelText('Effective from'), { target: { value: '2026-08-01' } })
    fireEvent.change(screen.getByLabelText('Overtime ordinary'), { target: { value: '150' } })

    const callsBefore = fetchMock.mock.calls.filter(
      (call) => call[0] === '/api/v1/admin/pay-rules' && (call[1] as RequestInit | undefined)?.method === undefined,
    ).length

    fireEvent.click(screen.getByRole('button', { name: 'Create version' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/admin/pay-rules' && (call[1] as RequestInit)?.method === 'POST',
        ),
      ).toBe(true)
    })

    const createCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/admin/pay-rules' && (call[1] as RequestInit)?.method === 'POST',
    )!
    const body = JSON.parse(String((createCall[1] as RequestInit).body)) as {
      effective_from: string
      overtime_ordinary_bp: number
      day_rates: Array<{ day_type: string; worked_bp: number; worked_rest_bp: number; unworked_bp: number }>
    }

    expect(body.effective_from).toBe('2026-08-01')
    expect(body.overtime_ordinary_bp).toBe(15000) // 150% -> bp, never 150
    expect(body.day_rates).toHaveLength(5)
    expect(body.day_rates.map((r) => r.day_type).sort()).toEqual(
      ['double_regular_holiday', 'ordinary', 'regular_holiday', 'special_non_working', 'special_working'].sort(),
    )
    const ordinaryRow = body.day_rates.find((r) => r.day_type === 'ordinary')!
    expect(ordinaryRow.worked_bp).toBe(10000) // seeded from the effective version, bp not percent

    await waitFor(() => {
      const getCalls = fetchMock.mock.calls.filter(
        (call) => call[0] === '/api/v1/admin/pay-rules' && (call[1] as RequestInit | undefined)?.method === undefined,
      ).length
      expect(getCalls).toBeGreaterThan(callsBefore)
    })

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('a 422 pay_rate_below_floor surfaces the violating cell inline', async () => {
    let attempt = 0
    stubApi({
      onCreate: () => {
        attempt += 1
        if (attempt === 1) {
          return {
            status: 422,
            code: 'pay_rate_below_floor',
            message: 'One or more proposed rates fall below the statutory floor.',
            details: { violations: [{ multiplier: 'night_diff', proposed_bp: 10000, floor_bp: 11000 }] },
          }
        }
        return payRule()
      },
    })

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New version' }))
    await screen.findByRole('dialog')

    fireEvent.change(screen.getByLabelText('Effective from'), { target: { value: '2026-08-01' } })
    fireEvent.change(screen.getByLabelText('Night differential'), { target: { value: '100' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create version' }))

    expect(await screen.findByText(/below the statutory floor/i)).toBeInTheDocument()
    expect(screen.getByLabelText('Night differential')).toHaveAttribute('aria-invalid', 'true')
  })

  it('a 409 surfaces the duplicate-date message', async () => {
    stubApi({
      onCreate: () => ({
        status: 409,
        code: 'pay_rule_exists',
        message: 'A pay rule already takes effect on that date.',
        details: { effective_from: '2026-01-01' },
      }),
    })

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New version' }))
    await screen.findByRole('dialog')

    fireEvent.change(screen.getByLabelText('Effective from'), { target: { value: '2026-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create version' }))

    expect(await screen.findByText(/version already exists on that date/i)).toBeInTheDocument()
  })
})
