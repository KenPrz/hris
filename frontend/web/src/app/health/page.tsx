'use client'

import { useEffect, useState } from 'react'

import { ApiError, api, type Health } from '@/lib/api'

export default function Page() {
  const [health, setHealth] = useState<Health | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .health()
      .then(setHealth)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.code : 'unexpected_response'))
  }, [])

  if (error) {
    return (
      <main className="p-8 font-mono">
        <h1 className="text-2xl">System unreachable</h1>
        <p className="mt-2">{error}</p>
      </main>
    )
  }

  if (!health) {
    return <main className="p-8 font-mono">Checking…</main>
  }

  return (
    <main className="p-8 font-mono">
      <h1 className="text-2xl">{health.healthy ? 'System healthy' : 'System degraded'}</h1>
      <dl className="mt-4">
        <dt>API version</dt>
        <dd>{health.app_version}</dd>
        <dt className="mt-2">Database</dt>
        <dd>{health.database.version ?? health.database.reason}</dd>
      </dl>
    </main>
  )
}
