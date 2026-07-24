import { afterEach, describe, expect, it, vi } from 'vitest'

import { clearToken, emitLogout, getToken, onLogout, setToken } from './session'

afterEach(() => {
  // Restore `window` before clearing: a test that stubs window away and then sets a
  // token would otherwise leave it behind, leaking into the next case.
  vi.unstubAllGlobals()
  clearToken()
})

describe('token storage', () => {
  it('round-trips a token through set/get', () => {
    expect(getToken()).toBeNull()

    setToken('abc123')

    expect(getToken()).toBe('abc123')
  })

  it('clearToken removes a stored token', () => {
    setToken('abc123')

    clearToken()

    expect(getToken()).toBeNull()
  })
})

describe('onLogout', () => {
  it('fires subscribers when emitLogout is called', () => {
    const fn = vi.fn()
    onLogout(fn)

    emitLogout()

    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('fires all subscribers, not just the first', () => {
    const first = vi.fn()
    const second = vi.fn()
    onLogout(first)
    onLogout(second)

    emitLogout()

    expect(first).toHaveBeenCalledTimes(1)
    expect(second).toHaveBeenCalledTimes(1)
  })

  it('stops firing a subscriber after it unsubscribes', () => {
    const fn = vi.fn()
    const unsubscribe = onLogout(fn)

    unsubscribe()
    emitLogout()

    expect(fn).not.toHaveBeenCalled()
  })

  it('does not affect other subscribers when one unsubscribes', () => {
    const first = vi.fn()
    const second = vi.fn()
    const unsubscribeFirst = onLogout(first)
    onLogout(second)

    unsubscribeFirst()
    emitLogout()

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })
})

describe('SSR safety', () => {
  it('getToken returns null without throwing when window is undefined', () => {
    vi.stubGlobal('window', undefined)

    expect(() => getToken()).not.toThrow()
    expect(getToken()).toBeNull()
  })

  it('setToken no-ops without throwing when window is undefined', () => {
    vi.stubGlobal('window', undefined)

    expect(() => setToken('abc123')).not.toThrow()
  })

  it('clearToken no-ops without throwing when window is undefined', () => {
    vi.stubGlobal('window', undefined)

    expect(() => clearToken()).not.toThrow()
  })

  it('emitLogout no-ops without throwing when window is undefined', () => {
    vi.stubGlobal('window', undefined)

    expect(() => emitLogout()).not.toThrow()
  })
})
