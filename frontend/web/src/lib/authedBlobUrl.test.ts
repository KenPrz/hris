import { afterEach, describe, expect, it, vi } from 'vitest'

import { authedBlobUrl } from './authedBlobUrl'
import { setToken, clearToken } from './session'

afterEach(() => {
  clearToken()
  vi.unstubAllGlobals()
})

describe('authedBlobUrl', () => {
  it('sends the bearer token and returns an object URL for the blob', async () => {
    setToken('test-token')

    const blob = new Blob(['pdf bytes'], { type: 'application/pdf' })
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, blob: () => Promise.resolve(blob) })
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:fake-url') })

    const url = await authedBlobUrl('/api/v1/requests/abc/attachment')

    expect(url).toBe('blob:fake-url')
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/requests/abc/attachment',
      expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer test-token' }) }),
    )
  })

  it('throws when the response is not ok, so a 404 never becomes a broken <img>', async () => {
    setToken('test-token')
    // `blob()` is defined and would happily resolve if reached — the assertion below only
    // holds if `authedBlobUrl` checks `response.ok` itself and never calls it. Without a
    // usable `blob()` here, a removed-check mutant would coincidentally still throw (calling
    // `.blob` on an object that lacks it), which would let this test pass for the wrong
    // reason and defeat the point of asserting the check exists at all.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ ok: false, status: 404, blob: () => Promise.resolve(new Blob(['not found'])) }),
    )

    await expect(authedBlobUrl('/api/v1/nope')).rejects.toThrow()
  })
})
