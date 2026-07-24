import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { Holiday } from '@/lib/api'
import { currentMonth } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { clearToken, setToken } from '@/lib/session'
import { Providers } from '@/components/Providers'

const push = vi.fn()
const replace = vi.fn()
let searchParams = new URLSearchParams()

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace }),
  useSearchParams: () => searchParams,
  usePathname: () => '/office/holidays',
}))

import HolidaysPage from './page'

const THIS_MONTH = currentMonth(OFFICE_TIME_ZONE)
const THIS_YEAR = Number(THIS_MONTH.slice(0, 4))
const HOLIDAY_DATE = `${THIS_MONTH}-05`
const EMPTY_DATE = `${THIS_MONTH}-10`

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
  push.mockClear()
  replace.mockClear()
  searchParams = new URLSearchParams()
})

function holiday(overrides: Partial<Holiday> = {}): Holiday {
  return {
    id: 'h1',
    office_id: 'o1',
    date: HOLIDAY_DATE,
    day_type: 'regular_holiday',
    name: 'Founding Day',
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

/** Routes GET /me, GET/POST/PATCH /office/holidays* off one mock. */
function stubApi(options: {
  hrOffices?: string[]
  holidaysByOfficeYear?: Record<string, Holiday[]>
  onCreate?: (body: unknown) => Holiday
  onUpdate?: (id: string, body: unknown) => Holiday
  onClone?: (body: unknown) => Holiday[]
}): ReturnType<typeof vi.fn> {
  const holidaysByOfficeYear = options.holidaysByOfficeYear ?? {}

  const fn = vi.fn().mockImplementation(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET'

    if (url === '/api/v1/me' && method === 'GET') {
      return { ok: true, status: 200, json: async () => sessionBody(options.hrOffices ?? ['o1']) }
    }

    if (url.startsWith('/api/v1/office/holidays/clone') && method === 'POST') {
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onClone?.(body) ?? []
      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    if (url.startsWith('/api/v1/office/holidays') && method === 'GET') {
      const parsed = new URL(url, 'http://x')
      const office = parsed.searchParams.get('office') ?? ''
      const year = parsed.searchParams.get('year') ?? ''
      const data = holidaysByOfficeYear[`${office}:${year}`] ?? []
      return { ok: true, status: 200, json: async () => ({ data }) }
    }

    if (url === '/api/v1/office/holidays' && method === 'POST') {
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onCreate?.(body) ?? holiday()
      return { ok: true, status: 200, json: async () => ({ data: result }) }
    }

    if (url.startsWith('/api/v1/office/holidays/') && method === 'PATCH') {
      const id = url.split('/').at(-1) ?? ''
      const body: unknown = JSON.parse(String(init?.body ?? '{}'))
      const result = options.onUpdate?.(id, body) ?? holiday()
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
      <HolidaysPage />
    </Providers>,
  )
}

describe('/office/holidays — office picker', () => {
  it('shows no office picker when the session administers only one office', async () => {
    stubApi({ hrOffices: ['o1'], holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [] } })

    renderPage()

    await screen.findByRole('grid')

    expect(screen.queryByLabelText('Office')).not.toBeInTheDocument()
  })

  it('shows an office picker, defaulted to the first office, when the session administers more than one', async () => {
    stubApi({
      hrOffices: ['o1', 'o2'],
      holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [], [`o2:${THIS_YEAR}`]: [] },
    })

    renderPage()

    const picker = await screen.findByLabelText('Office')
    expect(picker).toHaveTextContent('o1')
  })
})

describe('/office/holidays — the month grid', () => {
  it('renders a holiday day with its name and DayTypeTag', async () => {
    stubApi({
      hrOffices: ['o1'],
      holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [holiday()] },
    })

    renderPage()

    expect(await screen.findByText('Founding Day')).toBeInTheDocument()
    expect(screen.getByText('Regular holiday')).toBeInTheDocument()
  })

  it('renders the calendar grid — not a dead-end EmptyState — for a month with no holidays', async () => {
    stubApi({ hrOffices: ['o1'], holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [] } })

    renderPage()

    expect(await screen.findByRole('grid')).toBeInTheDocument()
    expect(screen.queryByText(/no holidays/i)).not.toBeInTheDocument()
    expect(screen.getByLabelText(`Add holiday on ${EMPTY_DATE}`)).toBeInTheDocument()
  })
})

describe('/office/holidays — add a holiday', () => {
  it('clicking an empty day opens the add dialog; submitting calls api.holidays.create and refetches the list', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [] },
      onCreate: () => holiday({ date: EMPTY_DATE, name: 'New Holiday' }),
    })

    renderPage()

    const dayButton = await screen.findByLabelText(`Add holiday on ${EMPTY_DATE}`)
    fireEvent.click(dayButton)

    expect(await screen.findByRole('dialog', { name: 'Add holiday' })).toBeInTheDocument()
    expect(screen.getByText(EMPTY_DATE)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New Holiday' } })

    const callsBefore = fetchMock.mock.calls.filter((call) =>
      String(call[0]).startsWith('/api/v1/office/holidays?'),
    ).length

    fireEvent.click(screen.getByRole('button', { name: 'Add holiday' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/holidays' && (call[1] as RequestInit)?.method === 'POST',
        ),
      ).toBe(true)
    })

    const createCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/holidays' && (call[1] as RequestInit)?.method === 'POST',
    )!
    const body: unknown = JSON.parse(String((createCall[1] as RequestInit).body))
    expect(body).toEqual({ office_id: 'o1', date: EMPTY_DATE, day_type: 'regular_holiday', name: 'New Holiday' })

    await waitFor(() => {
      const callsAfter = fetchMock.mock.calls.filter((call) =>
        String(call[0]).startsWith('/api/v1/office/holidays?'),
      ).length
      expect(callsAfter).toBeGreaterThan(callsBefore)
    })

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})

describe('/office/holidays — edit a holiday', () => {
  it('clicking an existing holiday opens the edit dialog with the date read-only and submits only { day_type, name }', async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [holiday()] },
      onUpdate: (_id, body) => holiday({ ...(body as object) }),
    })

    renderPage()

    const dayButton = await screen.findByLabelText(`${HOLIDAY_DATE}: Founding Day`)
    fireEvent.click(dayButton)

    expect(await screen.findByRole('dialog', { name: 'Edit holiday' })).toBeInTheDocument()
    // The date shows, but there is no editable date field — no textbox/spinbutton named "Date".
    expect(screen.getByText(HOLIDAY_DATE)).toBeInTheDocument()
    expect(screen.queryByLabelText('Date')).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Renamed Day' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/holidays/h1' && (call[1] as RequestInit)?.method === 'PATCH',
        ),
      ).toBe(true)
    })

    const updateCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/holidays/h1' && (call[1] as RequestInit)?.method === 'PATCH',
    )!
    const body: unknown = JSON.parse(String((updateCall[1] as RequestInit).body))
    expect(body).toEqual({ day_type: 'regular_holiday', name: 'Renamed Day' })
  })
})

describe('/office/holidays — clone', () => {
  it(`clicking "Clone from ${THIS_YEAR - 1}" calls api.holidays.clone and refetches the list`, async () => {
    const fetchMock = stubApi({
      hrOffices: ['o1'],
      holidaysByOfficeYear: { [`o1:${THIS_YEAR}`]: [] },
      onClone: () => [holiday()],
    })

    renderPage()

    const cloneButton = await screen.findByRole('button', { name: `Clone from ${THIS_YEAR - 1}` })

    const callsBefore = fetchMock.mock.calls.filter((call) =>
      String(call[0]).startsWith('/api/v1/office/holidays?'),
    ).length

    fireEvent.click(cloneButton)

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          (call) => call[0] === '/api/v1/office/holidays/clone' && (call[1] as RequestInit)?.method === 'POST',
        ),
      ).toBe(true)
    })

    const cloneCall = fetchMock.mock.calls.find(
      (call) => call[0] === '/api/v1/office/holidays/clone' && (call[1] as RequestInit)?.method === 'POST',
    )!
    const body: unknown = JSON.parse(String((cloneCall[1] as RequestInit).body))
    expect(body).toEqual({ office_id: 'o1', from_year: THIS_YEAR - 1, to_year: THIS_YEAR })

    await waitFor(() => {
      const callsAfter = fetchMock.mock.calls.filter((call) =>
        String(call[0]).startsWith('/api/v1/office/holidays?'),
      ).length
      expect(callsAfter).toBeGreaterThan(callsBefore)
    })
  })
})
