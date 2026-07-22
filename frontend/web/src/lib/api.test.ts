import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, api } from './api'

afterEach(() => {
  vi.unstubAllGlobals()
})

function stubFetch(status: number, body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
    }),
  )
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

  it('reports an unreachable network as a real, showable state', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('ECONNREFUSED')))

    await expect(api.health()).rejects.toBeInstanceOf(ApiError)
    await expect(api.health()).rejects.toMatchObject({ code: 'network_unreachable', status: 0 })
  })
})
