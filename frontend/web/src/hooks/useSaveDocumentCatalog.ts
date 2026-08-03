'use client'

/**
 * The six catalog-admin mutations (M10b-a) — category and kind create/update/delete, all
 * bundled behind one `useSaveDocumentCatalog()` call, the same "one hook, several named
 * mutations" shape as `useSaveProfile`. Every mutation invalidates all THREE document keys,
 * not just the one it wrote: editing a category's name changes what the kind form's own
 * category dropdown shows, and creating/editing/deleting a kind changes what
 * `/documents/catalog` returns to every other screen that reads it. `adminCategories`/
 * `adminKinds` back no screen yet, but invalidating them here costs nothing and keeps them
 * honest for whenever one lands.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { DocumentCategory, DocumentCategoryWrite, DocumentKind, DocumentKindWrite } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useSaveDocumentCatalog() {
  const queryClient = useQueryClient()

  function invalidate(): void {
    void queryClient.invalidateQueries({ queryKey: keys.documents.catalog() })
    void queryClient.invalidateQueries({ queryKey: keys.documents.adminCategories() })
    void queryClient.invalidateQueries({ queryKey: keys.documents.adminKinds() })
  }

  const createCategory = useMutation<DocumentCategory, unknown, DocumentCategoryWrite>({
    mutationFn: (body) => api.documents.createCategory(body),
    onSuccess: invalidate,
  })

  const updateCategory = useMutation<DocumentCategory, unknown, { id: string; body: DocumentCategoryWrite }>({
    mutationFn: ({ id, body }) => api.documents.updateCategory(id, body),
    onSuccess: invalidate,
  })

  const deleteCategory = useMutation<DocumentCategory[], unknown, string>({
    mutationFn: (id) => api.documents.deleteCategory(id),
    onSuccess: invalidate,
  })

  const createKind = useMutation<DocumentKind, unknown, DocumentKindWrite>({
    mutationFn: (body) => api.documents.createKind(body),
    onSuccess: invalidate,
  })

  const updateKind = useMutation<DocumentKind, unknown, { id: string; body: DocumentKindWrite }>({
    mutationFn: ({ id, body }) => api.documents.updateKind(id, body),
    onSuccess: invalidate,
  })

  const deleteKind = useMutation<DocumentKind[], unknown, string>({
    mutationFn: (id) => api.documents.deleteKind(id),
    onSuccess: invalidate,
  })

  return { createCategory, updateCategory, deleteCategory, createKind, updateKind, deleteKind }
}
