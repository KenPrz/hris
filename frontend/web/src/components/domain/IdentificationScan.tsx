'use client'

/**
 * Previews an identification scan. The stream is bearer-authenticated, so an <img src> or
 * <object data> pointing straight at the route navigates WITHOUT the token and 401s —
 * authedBlobUrl is the workaround, and the object URL is revoked on unmount so the blob does
 * not leak for the life of the tab.
 */

import { useEffect, useState } from 'react'

import { authedBlobUrl } from '@/lib/authedBlobUrl'

export function IdentificationScan({
  employeeId,
  identificationId,
}: {
  employeeId: string
  identificationId: string
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    authedBlobUrl(`/api/v1/employees/${employeeId}/identifications/${identificationId}/scan`)
      .then((blobUrl) => {
        if (cancelled) {
          URL.revokeObjectURL(blobUrl)

          return
        }
        objectUrl = blobUrl
        setUrl(blobUrl)
      })
      .catch(() => setFailed(true))

    return () => {
      cancelled = true
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl)
    }
  }, [employeeId, identificationId])

  if (failed) {
    return (
      <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
        Scan unavailable
      </span>
    )
  }

  if (url === null) {
    return (
      <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
        Loading scan…
      </span>
    )
  }

  // <object> renders both PDFs and images, so one element covers every accepted mime type.
  return (
    <object
      data={url}
      style={{ width: '100%', maxWidth: '32rem', height: '24rem', borderRadius: 'var(--radius)' }}
      aria-label="Identification scan"
    />
  )
}
