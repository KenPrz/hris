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
    expect(headers.Authorization).toBeUndefined()
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
