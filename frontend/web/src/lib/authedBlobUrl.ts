/**
 * Fetches a bearer-authenticated stream and hands back a same-origin blob URL.
 *
 * Every attachment route in this API is a private, app-mediated stream — never a public or
 * presigned object URL — so a plain `<a href>` or `<img src>` navigates WITHOUT the
 * Authorization header and gets a 401. This wraps the one workaround: fetch with the header,
 * then `createObjectURL` the result.
 *
 * Lifted out of RequestCard.tsx (M3.6) when M10a needed the same trick for identification
 * scan previews. Callers own the returned URL and should `URL.revokeObjectURL` it on unmount.
 */

import { getToken } from './session'

export async function authedBlobUrl(path: string): Promise<string> {
  const token = getToken()

  const response = await fetch(path, {
    headers: token === null ? {} : { Authorization: `Bearer ${token}` },
  })

  if (!response.ok) {
    throw new Error(`Failed to fetch ${path}: HTTP ${response.status}`)
  }

  return URL.createObjectURL(await response.blob())
}
