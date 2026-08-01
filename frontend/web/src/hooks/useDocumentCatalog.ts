'use client'

import { useQuery } from '@tanstack/react-query'

import type { DocumentCatalog } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

/**
 * The document catalog (M10b-a) — every category plus every document kind, in one read.
 * This is the ungated `GET /documents/catalog` (see `api.documents.catalog`'s own comment),
 * not the `manageCatalog`-gated `admin/document-categories`/`admin/documents` list routes —
 * the admin screen lists FROM this same read every other dropdown in the app will eventually
 * source from, so an edit made here is visible everywhere without a second round trip.
 *
 * Nothing writes this key except `/admin/documents`'s own mutations
 * (`useSaveDocumentCatalog`), which invalidate it explicitly on every success — so a long
 * `staleTime` doesn't risk a stale list after a real edit.
 */
export function useDocumentCatalog() {
  return useQuery<DocumentCatalog>({
    queryKey: keys.documents.catalog(),
    queryFn: () => api.documents.catalog(),
    staleTime: 60 * 60 * 1000,
  })
}
