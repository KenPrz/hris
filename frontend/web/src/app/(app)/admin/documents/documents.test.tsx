import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import type { DocumentCatalog, DocumentCategory, DocumentKind } from '@/lib/api'
import { Providers } from '@/components/Providers'

// Same reasoning as `src/app/(app)/me/profile/profile.test.tsx`: `AppShell` needs a mounted
// router (`usePathname`) and a `<SessionProvider>` (via `<Providers>`). No token is set, so
// `SessionProvider`'s `GET /me` stays disabled and `useSession()` resolves to a `null`
// session — this page reads no session field, so that's a non-issue here.
vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => '/admin/documents',
}))

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: {
    documents: {
      catalog: vi.fn(),
      createCategory: vi.fn(),
      updateCategory: vi.fn(),
      deleteCategory: vi.fn(),
      createKind: vi.fn(),
      updateKind: vi.fn(),
      deleteKind: vi.fn(),
    },
  },
}))

const { api, ApiError } = await import('@/lib/api')
import { APPLIES_TO_OPTIONS } from './page'
import DocumentsPage from './page'

// jsdom implements neither Pointer Events capture nor Element.scrollIntoView, which Radix
// Select's trigger/content call on open — see src/components/ui/Select.test.tsx.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn()
  Element.prototype.releasePointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
})

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

function catalog(overrides: Partial<DocumentCatalog> = {}): DocumentCatalog {
  return { categories: [category()], documents: [kind()], ...overrides }
}

beforeEach(() => {
  vi.mocked(api.documents.catalog).mockResolvedValue(catalog())
})

function renderPage() {
  return render(
    <Providers>
      <DocumentsPage />
    </Providers>,
  )
}

describe('/admin/documents — lists', () => {
  it('renders every category and every document kind from the catalog', async () => {
    vi.mocked(api.documents.catalog).mockResolvedValue(
      catalog({
        categories: [category({ id: 'cat-1', name: 'Government IDs' })],
        documents: [kind({ id: 'kind-1', name: 'Passport', category_id: 'cat-1' })],
      }),
    )

    renderPage()

    expect(await screen.findByText('Government IDs')).toBeInTheDocument()
    expect(screen.getByText('Passport')).toBeInTheDocument()
  })

  it('shows a skeleton while loading', () => {
    vi.mocked(api.documents.catalog).mockReturnValue(new Promise(() => {}))
    const { container } = renderPage()

    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull()
  })

  it('shows an empty state when there are no categories yet', async () => {
    vi.mocked(api.documents.catalog).mockResolvedValue(catalog({ categories: [], documents: [] }))

    renderPage()

    expect(await screen.findByText('No categories yet')).toBeInTheDocument()
    expect(screen.getByText(/Create a category first/)).toBeInTheDocument()
  })
})

describe('/admin/documents — applies_to options', () => {
  it('are exactly the Documentable enum\'s backed values, plus "" for Both', () => {
    expect(APPLIES_TO_OPTIONS.map((option) => option.value)).toEqual(['', 'employee', 'office'])
  })
})

describe('/admin/documents — create a category', () => {
  it('submitting the category form sends its snake_case body', async () => {
    const create = vi.mocked(api.documents.createCategory).mockResolvedValue(category())
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New category' }))
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'GOVT_ID' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Government IDs' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    expect(create).toHaveBeenCalledWith({ code: 'GOVT_ID', name: 'Government IDs', description: null })
  })
})

describe('/admin/documents — create a kind', () => {
  it('sends exact snake_case field names, with applies_to: null for "Both"', async () => {
    const create = vi.mocked(api.documents.createKind).mockResolvedValue(kind())
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New document kind' }))
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'PASSPORT' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Passport' } })
    // Applies to is left untouched — its default is the blank "Both" option.
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    const [body] = create.mock.calls[0]
    expect(body).toEqual({
      code: 'PASSPORT',
      name: 'Passport',
      description: null,
      category_id: 'cat-1',
      applies_to: null,
      is_required: false,
      validity_months: null,
    })
  })

  it('an empty validity_months submits null, not 0', async () => {
    const create = vi.mocked(api.documents.createKind).mockResolvedValue(kind())
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New document kind' }))
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'PASSPORT' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Passport' } })
    // Validity (months) is left blank on purpose.
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    const [body] = create.mock.calls[0]
    expect(body.validity_months).toBeNull()
  })

  it('a chosen applies_to value is sent verbatim, not null', async () => {
    const create = vi.mocked(api.documents.createKind).mockResolvedValue(kind())
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'New document kind' }))
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'PASSPORT' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Passport' } })

    fireEvent.click(screen.getByLabelText('Applies to'))
    fireEvent.click(await screen.findByRole('option', { name: 'Employee' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    const [body] = create.mock.calls[0]
    expect(body.applies_to).toBe('employee')
  })
})

describe('/admin/documents — delete surfaces the 409', () => {
  it('deleting a category still in use renders the dependents count', async () => {
    vi.mocked(api.documents.deleteCategory).mockRejectedValue(
      new ApiError('document_catalog_in_use', 'This catalog entry is still in use and cannot be deleted.', 409, {
        subject_type: 'document_category',
        subject_id: 'cat-1',
        dependents: 3,
      }),
    )
    renderPage()

    await screen.findByText('Government IDs')
    // The default catalog fixture has one category AND one kind, so both rows render their
    // own "Delete" button — the category's is first in the DOM (Categories renders above
    // Document kinds).
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[0])

    expect(await screen.findByText('3 documents still use this category.')).toBeInTheDocument()
  })

  it('deleting a document kind still in use renders the dependents count', async () => {
    vi.mocked(api.documents.deleteKind).mockRejectedValue(
      new ApiError('document_catalog_in_use', 'This catalog entry is still in use and cannot be deleted.', 409, {
        subject_type: 'document',
        subject_id: 'kind-1',
        dependents: 1,
      }),
    )
    renderPage()

    await screen.findByText('Passport')
    const deleteButtons = screen.getAllByRole('button', { name: 'Delete' })
    // The kind row's Delete is the second one — the category's comes first in the DOM.
    fireEvent.click(deleteButtons[deleteButtons.length - 1])

    expect(await screen.findByText('1 document still uses this document kind.')).toBeInTheDocument()
  })

  it('a non-409 delete failure falls back to the generic message, not a fabricated count', async () => {
    vi.mocked(api.documents.deleteCategory).mockRejectedValue(
      new ApiError('network_unreachable', 'Cannot reach the server.', 0),
    )
    renderPage()

    await screen.findByText('Government IDs')
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[0])

    expect(await screen.findByText('Cannot reach the server.')).toBeInTheDocument()
  })
})
