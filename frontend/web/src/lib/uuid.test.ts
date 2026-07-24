import { afterEach, describe, expect, it, vi } from 'vitest'

import { uuidV4 } from './uuid'

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('uuidV4', () => {
  it('returns a well-formed v4 uuid when crypto.randomUUID is available', () => {
    expect(uuidV4()).toMatch(V4)
  })

  it('still returns a valid v4 uuid without crypto.randomUUID (an insecure/plain-HTTP context)', () => {
    // The exact failure mode on a plain-http:// LAN: randomUUID is undefined, getRandomValues
    // is not. The fallback must produce a real v4 (correct version + variant nibbles).
    vi.stubGlobal('crypto', {
      randomUUID: undefined,
      getRandomValues: (arr: Uint8Array) => {
        for (let i = 0; i < arr.length; i++) arr[i] = i * 17
        return arr
      },
    })

    const id = uuidV4()
    expect(id).toMatch(V4)
    expect(id[14]).toBe('4') // version nibble
  })

  it('does not collide across calls', () => {
    expect(uuidV4()).not.toBe(uuidV4())
  })
})
