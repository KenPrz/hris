import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { DocumentCategory, DocumentKind } from '@/lib/api'
import { keys } from '@/lib/keys'

vi.mock('@/lib/api', () => ({
  api: {
    documents: {
      createCategory: vi.fn(),
      updateCategory: vi.fn(),
      deleteCategory: vi.fn(),
      createKind: vi.fn(),
      updateKind: vi.fn(),
      deleteKind: vi.fn(),
    },
  },
}))

import { api } from '@/lib/api'

import { useSaveDocumentCatalog } from './useSaveDocumentCatalog'

afterEach(() => {
  vi.clearAllMocks()
})

function category(overrides: Partial<DocumentCategory> = {}): DocumentCategory {
  return { id: 'cat-1', code: 'GOVT_ID', name: 'Government IDs', description: null, ...overrides }
}

function kind(overrides: Partial<DocumentKind> = {}): DocumentKind {
  return {
    id: 'kind-1',
    code: 'PASSPORT',
    name: 'Passport',
    description: null,
    category_id: 'cat-1',
    applies_to: null,
    is_required: false,
    validity_months: null,
    ...overrides,
  }
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function newClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

// The whole point of this hook (see its own docblock) is that every one of the six
// mutations invalidates all THREE document query keys, not just the one it wrote — a
// category rename changes what the kind form's own category dropdown shows, and any kind
// write changes what /documents/catalog returns everywhere else it's read. useDocumentCatalog
// carries a 1-hour staleTime, so a silently dropped invalidation here means an hour of stale
// dropdowns in M10b-b, not a quick self-correcting refetch — cheap to pin, expensive to lose.
describe('useSaveDocumentCatalog — cache invalidation', () => {
  it('createCategory invalidates catalog, adminCategories, and adminKinds on success', async () => {
    vi.mocked(api.documents.createCategory).mockResolvedValue(category())

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveDocumentCatalog(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.createCategory.mutate({ code: 'GOVT_ID', name: 'Government IDs', description: null })
    })

    await waitFor(() => expect(result.current.createCategory.isSuccess).toBe(true))

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.catalog() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.adminCategories() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.adminKinds() })
  })

  it('deleteKind also invalidates all three keys — every mutation shares the same invalidate()', async () => {
    vi.mocked(api.documents.deleteKind).mockResolvedValue([kind()])

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveDocumentCatalog(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.deleteKind.mutate('kind-1')
    })

    await waitFor(() => expect(result.current.deleteKind.isSuccess).toBe(true))

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.catalog() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.adminCategories() })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: keys.documents.adminKinds() })
  })

  it('does not invalidate anything when the mutation fails', async () => {
    vi.mocked(api.documents.createCategory).mockRejectedValue(new Error('validation failed'))

    const client = newClient()
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')

    const { result } = renderHook(() => useSaveDocumentCatalog(), { wrapper: makeWrapper(client) })

    await act(async () => {
      result.current.createCategory.mutate({ code: 'GOVT_ID', name: 'Government IDs', description: null })
    })

    await waitFor(() => expect(result.current.createCategory.isError).toBe(true))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
