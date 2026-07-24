/**
 * A v4 UUID that works outside a secure context.
 *
 * `crypto.randomUUID` exists only over HTTPS or localhost — on a plain `http://10.x` LAN
 * deployment (a realistic on-prem Philippine rollout before a TLS cert is in place) it is
 * `undefined`, and a bare `crypto.randomUUID()` throws. `crypto.getRandomValues` has no
 * such gate, so we fall back to building the UUID from it. The result is the same shape,
 * so the idempotency key the punch endpoint requires is always available.
 */
export function uuidV4(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40 // version 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80 // variant 10xx

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'))

  return (
    hex.slice(0, 4).join('') +
    '-' +
    hex.slice(4, 6).join('') +
    '-' +
    hex.slice(6, 8).join('') +
    '-' +
    hex.slice(8, 10).join('') +
    '-' +
    hex.slice(10, 16).join('')
  )
}
