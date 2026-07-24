/**
 * The bearer-token store. The ONLY module that touches storage — no component ever reads
 * or writes the token directly. SSR-safe: Next renders this on the server too, where
 * `localStorage` and `window` do not exist, so every function no-ops rather than throws.
 */

const STORAGE_KEY = 'hris.token'

type Listener = () => void

const listeners = new Set<Listener>()

function hasWindow(): boolean {
  return typeof window !== 'undefined'
}

export function getToken(): string | null {
  if (!hasWindow()) return null

  return window.localStorage.getItem(STORAGE_KEY)
}

export function setToken(token: string): void {
  if (!hasWindow()) return

  window.localStorage.setItem(STORAGE_KEY, token)
}

export function clearToken(): void {
  if (!hasWindow()) return

  window.localStorage.removeItem(STORAGE_KEY)
}

/** Subscribe to logout events. Returns an unsubscribe function. */
export function onLogout(fn: Listener): () => void {
  if (!hasWindow()) return () => {}

  listeners.add(fn)

  return () => {
    listeners.delete(fn)
  }
}

/** Notify every subscriber that the session ended (e.g. a 401 from the API). */
export function emitLogout(): void {
  if (!hasWindow()) return

  for (const listener of listeners) {
    listener()
  }
}
