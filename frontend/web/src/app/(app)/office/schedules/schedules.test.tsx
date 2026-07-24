import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import type { ShiftTemplate } from '@/lib/api'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

const push = vi.fn()
const replace = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/office/schedules',
}))

import SchedulesPage from './page'

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView — Radix
// Select's trigger/content call both when opening. Same workaround as Select.test.tsx.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
})

const REST_DAY = { is_rest: true, start_minute: null, end_minute: null, break_minutes: null }
const WORKING_DAY = { is_rest: false, start_minute: 480, end_minute: 1080, break_minutes: 60 }

function template(overrides: Partial<ShiftTemplate> = {}): ShiftTemplate {
  return {
    id: 't1',
    office_id: 'o1',
    name: 'Day Shift',
    days: [
      { weekday: 0, ...WORKING_DAY },
      { weekday: 1, ...WORKING_DAY },
      { weekday: 2, ...WORKING_DAY },
      { weekday: 3, ...WORKING_DAY },
      { weekday: 4, ...WORKING_DAY },
      { weekday: 5, ...REST_DAY },
      { weekday: 6, ...REST_DAY },
    ],
    ...overrides,
  }
}

function sessionBody(hrOffices: string[]) {
  return {
    data: {
      user: { id: 'u1', email: 'hr@x.com', name: 'HR' },
      employee: {
        id: 'e1',
        employee_no: 'E-001',
        current_office_id: 'o1',
        current_department_id: null,
      },
      is_system_admin: false,
      has_reports: false,
      hr_offices: hrOffices,
      permissions: [],
    },
  }
}

/** Routes GET /me, GET/POST/PATCH/DELETE /office/shift-templates*, PATCH
 * /office/default-template off one mock. */
function stubApi(options: {
  hrOffices?: string[]
  templatesByOffice?: Record<string, ShiftTemplate[]>
  onCreate?: (body: unknown) => ShiftTemplate
  onUpdate?: (id: string, body: unknown) => ShiftTemplate
  onDelete?: (id: string) => { status: number; body: unknown }
  onSetDefault?: (body: unknown) => { id: string; default_shift_template_id: string }
}): ReturnType<typeof vi.fn> {
  const templatesByOffice = options.templatesByOffice ?? {}

  const fn = vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'

    if (url === '/api/v1/me' && method === 'GET') {
      return { ok: true, status: 200, json: async () => sessionBody(options.hrOffices ?? ['o1']) }
    }

    if (url.startsWith('/api/v1/office/shift-templates?') && method === 'GET') {
      const parsed = new URL(url, 'http://x')
      const office = parsed.searchParams.get('office') ?? ''
      const data = templatesByOffice[office] ?? []
      return { ok: true, status: 200, json: async () => ({ data }) }
    }

    if (url === '/api/v1/office/shift-templates' && method === 'POST') {
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onCreate?.(body) ?? template()
      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    if (url.startsWith('/api/v1/office/shift-templates/') && method === 'PATCH') {
      const id = url.split('/').at(-1) ?? ''
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onUpdate?.(id, body) ?? template()
      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    if (url.startsWith('/api/v1/office/shift-templates/') && method === 'DELETE') {
      const id = url.split('/').at(-1) ?? ''
      const outcome = options.onDelete?.(id) ?? { status: 204, body: null }
      return { ok: outcome.status >= 200 && outcome.status < 300, status: outcome.status, json: async () => outcome.body }
    }

    if (url === '/api/v1/office/default-template' && method === 'PATCH') {
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onSetDefault?.(body) ?? { id: 'o1', default_shift_template_id: 't1' }
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
      <SchedulesPage />
    </Providers>,
  )
}

describe('/office/schedules — office picker', () => {
  it('shows no office picker when the session administers only one office', async () => {
    stubApi({ hrOffices: ['o1'], templatesByOffice: { o1: [] } })

    renderPage()

    await screen.findByText('No shift templates yet')

    expect(screen.queryByLabelText('Office')).not.toBeInTheDocument()
  })

  it('shows an office picker, defaulted to the first office, when the session administers more than one', async () => {
    stubApi({
      hrOffices: ['o1', 'o2'],
      templatesByOffice: { o1: [], o2: [] },
    })

    renderPage()

    const picker = await screen.findByLabelText('Office')
    expect(picker).toHaveTextContent('o1')
  })
})

describe('/office/schedules — templates list', () => {
  it('lists a template with its name and a working/rest summary', async () => {
    stubApi({ hrOffices: ['o1'], templatesByOffice: { o1: [template()] } })

    renderPage()

    expect(await screen.findByText('Day Shift')).toBeInTheDocument()
    expect(screen.getByText('5 working days, 2 rest days')).toBeInTheDocument()
  })

  it('shows an empty state with no templates', async () => {
    stubApi({ hrOffices: ['o1'], templatesByOffice: { o1: [] } })

    renderPage()

    expect(await screen.findByText('No shift templates yet')).toBeInTheDocument()
  })
})

describe('/office/schedules — create a template', () => {
  it('"New template" opens a Dialog with a WeekEditor seeded Mon-Fri working / Sat-Sun rest; submitting calls api.shiftTemplates.create and invalidates', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      templatesByOffice: { o1: [] },
      onCreate: () => template({ name: 'New Template' }),
    })

    renderPage()

    await screen.findByText('No shift templates yet')

    fireEvent.click(screen.getByRole('button', { name: 'New template' }))

    expect(await screen.findByRole('dialog', { name: 'New template' })).toBeInTheDocument()
    // WeekEditor renders — seeded Mon..Fri working, Sat/Sun rest.
    expect(screen.getByLabelText('Mon start')).toHaveValue('08:00')
    expect(screen.getByLabelText('Fri end')).toHaveValue('18:00')
    expect(screen.getByLabelText('Sat rest day')).toBeChecked()
    expect(screen.getByLabelText('Sun rest day')).toBeChecked()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New Template' } })

    fireEvent.click(screen.getByRole('button', { name: 'Add template' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/shift-templates' && (call[1] as RequestInit)?.method === 'POST',
        ),
      ).toBe(true)
    })

    const createCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/shift-templates' && (call[1] as RequestInit)?.method === 'POST',
    )!
    const body = JSON.parse(String((createCall[1] as RequestInit).body)) as {
      office_id: string
      name: string
      days: unknown[]
    }
    expect(body.office_id).toBe('o1')
    expect(body.name).toBe('New Template')
    expect(body.days).toHaveLength(7)

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})

describe('/office/schedules — edit a template', () => {
  it('the edit affordance opens the Dialog pre-filled with the template days; submitting calls api.shiftTemplates.update', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      templatesByOffice: { o1: [template()] },
      onUpdate: (_id, body) => template({ ...(body as object) }),
    })

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Edit Day Shift' }))

    expect(await screen.findByRole('dialog', { name: 'Edit template' })).toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('Day Shift')
    expect(screen.getByLabelText('Mon start')).toHaveValue('08:00')
    expect(screen.getByLabelText('Sat rest day')).toBeChecked()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Renamed Shift' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/shift-templates/t1' && (call[1] as RequestInit)?.method === 'PATCH',
        ),
      ).toBe(true)
    })

    const updateCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/shift-templates/t1' && (call[1] as RequestInit)?.method === 'PATCH',
    )!
    const body = JSON.parse(String((updateCall[1] as RequestInit).body)) as { name: string; days: unknown[] }
    expect(body.name).toBe('Renamed Shift')
    expect(body.days).toHaveLength(7)
  })
})

describe('/office/schedules — delete a template', () => {
  it('deletes a template and refetches the list', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      templatesByOffice: { o1: [template()] },
    })

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Delete Day Shift' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/shift-templates/t1' && (call[1] as RequestInit)?.method === 'DELETE',
        ),
      ).toBe(true)
    })
  })

  it('surfaces a 422 template_in_use error via InlineNotification instead of crashing', async () => {
    stubApi({
      hrOffices: ['o1'],
      templatesByOffice: { o1: [template()] },
      onDelete: () => ({
        status: 422,
        body: {
          error: {
            code: 'template_in_use',
            message: 'This shift template is still in use and cannot be deleted.',
            details: { template_id: 't1' },
          },
        },
      }),
    })

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Delete Day Shift' }))

    expect(
      await screen.findByText('This shift template is still in use and cannot be deleted.'),
    ).toBeInTheDocument()
    // The template row is still there — the failed delete never invalidated the list.
    expect(screen.getByText('Day Shift')).toBeInTheDocument()
  })
})

describe('/office/schedules — office default template', () => {
  it('the office-default Select calls api.officeDefaultTemplate.set', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      templatesByOffice: { o1: [template(), template({ id: 't2', name: 'Night Shift' })] },
      onSetDefault: () => ({ id: 'o1', default_shift_template_id: 't2' }),
    })

    renderPage()

    const select = await screen.findByLabelText('Office default template')
    fireEvent.click(select)
    fireEvent.click(await screen.findByRole('option', { name: 'Night Shift' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/default-template' && (call[1] as RequestInit)?.method === 'PATCH',
        ),
      ).toBe(true)
    })

    const setCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/default-template' && (call[1] as RequestInit)?.method === 'PATCH',
    )!
    const body = JSON.parse(String((setCall[1] as RequestInit).body)) as { office_id: string; template_id: string }
    expect(body).toEqual({ office_id: 'o1', template_id: 't2' })

    await waitFor(() => {
      expect(screen.getByLabelText('Office default template')).toHaveTextContent('Night Shift')
    })
  })
})
