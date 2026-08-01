import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, api } from './api'
import { clearToken, getToken, onLogout, setToken } from './session'

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
})

function stubFetch(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fn = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  })
  vi.stubGlobal('fetch', fn)
  return fn
}

describe('api.health', () => {
  it('unwraps the data envelope', async () => {
    stubFetch(200, {
      data: {
        healthy: true,
        app_version: 'test',
        database: { ok: true, version: 'PostgreSQL 18.0', reason: null },
      },
    })

    const health = await api.health()

    expect(health.healthy).toBe(true)
    expect(health.database.version).toBe('PostgreSQL 18.0')
  })

  it('throws an ApiError carrying the stable code, not the message', async () => {
    stubFetch(503, {
      error: { code: 'not_found', message: 'Nope.', details: {} },
    })

    // Callers branch on `code`. `message` is human-readable and may change freely.
    await expect(api.health()).rejects.toMatchObject({
      code: 'not_found',
      status: 503,
    })
  })

  it('rejects a malformed error body with an ApiError, not a TypeError', async () => {
    // `{"error": null}` satisfies a naive `'error' in body` guard and then explodes on
    // `body.error.code`. A TypeError is not something the UI can branch on.
    stubFetch(500, { error: null })

    await expect(api.health()).rejects.toBeInstanceOf(ApiError)
    await expect(api.health()).rejects.toMatchObject({ code: 'unexpected_response', status: 500 })
  })

  it('reports an unreachable network as a real, showable state', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('ECONNREFUSED')))

    await expect(api.health()).rejects.toBeInstanceOf(ApiError)
    await expect(api.health()).rejects.toMatchObject({ code: 'network_unreachable', status: 0 })
  })
})

describe('bearer token attachment', () => {
  it('attaches Authorization when a token is stored', async () => {
    setToken('sekrit')
    const fetchMock = stubFetch(200, { data: { healthy: true, app_version: 'x', database: { ok: true, version: null, reason: null } } })

    await api.health()

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = init.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer sekrit')
  })

  it('sends no Authorization header when no token is stored', async () => {
    const fetchMock = stubFetch(200, { data: { healthy: true, app_version: 'x', database: { ok: true, version: null, reason: null } } })

    await api.health()

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = init.headers as Record<string, string>
    // Absence, not an undefined-valued key — a regression that set the header to
    // `undefined` would still ship an empty Authorization to the server.
    expect('Authorization' in headers).toBe(false)
  })

  it('carries the token alongside caller headers without clobbering them', async () => {
    // punch() supplies Content-Type and Idempotency-Key; auth injection must add to
    // those, never replace the header object. The backend rejects a punch with no
    // Idempotency-Key, so a clobber here would be a 4xx nobody could explain.
    setToken('sekrit')
    const fetchMock = stubFetch(201, {
      data: {
        id: 'p1', employee_id: 'e1', office_id: 'o1',
        punched_at: '2026-07-20T08:00:00+08:00',
        direction: 'in', source: 'web', verification: 'verified', flag_reason: null,
      },
    })

    await api.punch('in', 'key-123')

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = init.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer sekrit')
    expect(headers['Idempotency-Key']).toBe('key-123')
    expect(headers['Content-Type']).toBe('application/json')
  })
})

describe('401 handling', () => {
  it('clears the token, notifies onLogout subscribers, and still throws ApiError', async () => {
    setToken('sekrit')
    const logoutFn = vi.fn()
    const unsubscribe = onLogout(logoutFn)
    stubFetch(401, { error: { code: 'unauthenticated', message: 'Authentication is required.', details: {} } })

    await expect(api.health()).rejects.toBeInstanceOf(ApiError)
    await expect(api.health()).rejects.toMatchObject({ code: 'unauthenticated', status: 401 })

    expect(getToken()).toBeNull()
    expect(logoutFn).toHaveBeenCalled()

    unsubscribe()
  })
})

describe('api.login', () => {
  it('unwraps token and user from the data envelope', async () => {
    stubFetch(200, { data: { token: 'abc', user: { id: '1', email: 'a@b.com', name: 'A' } } })

    const result = await api.login('a@b.com', 'pw')

    expect(result.token).toBe('abc')
    expect(result.user.email).toBe('a@b.com')
  })
})

describe('api.punch', () => {
  it('sends the given Idempotency-Key header', async () => {
    const fetchMock = stubFetch(201, {
      data: {
        id: '1',
        employee_id: '2',
        office_id: '3',
        punched_at: '2026-07-24T08:00:00+08:00',
        direction: 'in',
        source: 'web',
        verification: 'verified',
        flag_reason: null,
      },
    })

    await api.punch('in', 'my-idempotency-key')

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = init.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBe('my-idempotency-key')
  })
})

describe('api.adjustments.submit', () => {
  it('sends a FormData body with NO Content-Type header, so the browser sets the multipart boundary', async () => {
    const fetchMock = stubFetch(201, {
      data: {
        id: 'r1',
        type: 'attendance_adjustment',
        state: 'pending',
        note: 'Missed punch',
        employee_id: 'e1',
        detail: { operation: 'void', target_log_id: 'log-1', direction: null, punched_at: null },
        decided_by: null,
        decided_at: null,
        decision_note: null,
        has_attachment: false,
      },
    })

    await api.adjustments.submit({ operation: 'void', target_log_id: 'log-1', note: 'Missed punch' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/attendance/adjustments')
    expect(init.method).toBe('POST')
    expect(init.body).toBeInstanceOf(FormData)

    // A `Content-Type: multipart/form-data` here would ship without the boundary the
    // browser generates for the body it's actually sending — the server could never parse
    // it. `Accept`/`Authorization` are fine; `Content-Type` specifically must be absent so
    // `fetch` computes it (with boundary) from the FormData body itself.
    const headers = init.headers as Record<string, string>
    expect('Content-Type' in headers).toBe(false)
  })
})

describe('api.documents.createKind', () => {
  it('sends snake_case field names — category_id, applies_to, is_required, validity_months', async () => {
    const fetchMock = stubFetch(201, {
      data: {
        id: 'd1',
        code: 'PASSPORT',
        name: 'Passport',
        description: null,
        category_id: 'c1',
        applies_to: 'employee',
        is_required: true,
        validity_months: 60,
      },
    })

    await api.documents.createKind({
      code: 'PASSPORT',
      name: 'Passport',
      category_id: 'c1',
      applies_to: 'employee',
      is_required: true,
      validity_months: 60,
    })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/admin/documents')
    expect(init.method).toBe('POST')

    // The request body is a plain object serialized by JSON.stringify — a camelCase slip
    // here (categoryId instead of category_id) is a silent 400 at runtime that no
    // typecheck catches, so this asserts the exact wire keys, not just that a call
    // happened.
    const body = JSON.parse(init.body as string) as Record<string, unknown>
    expect(body).toEqual({
      code: 'PASSPORT',
      name: 'Passport',
      category_id: 'c1',
      applies_to: 'employee',
      is_required: true,
      validity_months: 60,
    })
  })
})

describe('api.requests.reject', () => {
  it('POSTs { decision_note } as JSON to /requests/{id}/reject', async () => {
    const fetchMock = stubFetch(200, {
      data: {
        id: 'some-id',
        type: 'attendance_adjustment',
        state: 'rejected',
        note: 'Missed punch',
        employee_id: 'e1',
        detail: null,
        decided_by: 'u1',
        decided_at: '2026-07-26T09:00:00Z',
        decision_note: 'a reason',
        has_attachment: false,
      },
    })

    await api.requests.reject('some-id', 'a reason')

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/requests/some-id/reject')
    expect(init.method).toBe('POST')

    const headers = init.headers as Record<string, string>
    expect(headers['Content-Type']).toBe('application/json')
    expect(init.body).toBe(JSON.stringify({ decision_note: 'a reason' }))
  })
})
