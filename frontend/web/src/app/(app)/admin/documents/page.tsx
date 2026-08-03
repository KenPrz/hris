'use client'

/**
 * The document catalog admin screen (M10b-a Task 10) — two sections, Categories and
 * Document kinds, each with an inline create/edit form and a delete control, mirroring
 * `/admin/offices`'s list+form shape but WITHOUT a `Dialog`: the form sits inline under its
 * section, not in a modal (the brief calls this out explicitly — this screen's forms are
 * short enough that a modal would be ceremony, not clarity).
 *
 * Both lists render from `useDocumentCatalog()` (`GET /documents/catalog`), not the
 * `admin/document-categories`/`admin/documents` list routes — see that hook's own comment.
 * Writes go through `useSaveDocumentCatalog()`'s six mutations, which invalidate all three
 * document query keys on every success.
 *
 * `applies_to`'s Select options are exported (`APPLIES_TO_OPTIONS`) so a test can assert
 * their values directly rather than introspecting Radix's portal-rendered DOM, which emits
 * no `<option>` nodes at all. The blank option ('') means "Both" and is sent as `null` —
 * `Rule::enum` on the backend matches the backed enum value exactly, so `'Employee'` (or any
 * other casing) 400s.
 *
 * Deleting a category or kind still referenced elsewhere 409s as `document_catalog_in_use`
 * with `error.details.dependents` — surfaced verbatim as "N documents still use this
 * category/document kind", not the generic failure copy, since this is the one error in the
 * milestone an admin will actually hit (see `DeleteDocumentCategory`/`DeleteDocument`).
 *
 * **Authorization (M10b-a final fixes).** `GET /documents/catalog` is intentionally ungated
 * (see `useDocumentCatalog`'s own comment), so the lists always render — but every write 403s
 * for an actor who lacks `document.manage`, and unlike a sibling screen this one is NOT
 * `is_system_admin`-only: `manageCatalog` (`app/Policies/DocumentPolicy.php`) reads
 * `document.manage`, which `RbacSeeder` grants to `HR Admin` too. `canManage` mirrors that:
 * `is_system_admin` OR the session holds `document.manage`. When neither holds, this screen
 * hides the "New …"/"Edit"/"Delete" controls and shows the sibling-style notice instead of
 * presenting a button that can only 403.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type { DocumentCategory, DocumentCategoryWrite, DocumentKind, DocumentKindWrite } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { useDocumentCatalog } from '@/hooks/useDocumentCatalog'
import { useSaveDocumentCatalog } from '@/hooks/useSaveDocumentCatalog'
import { useSession } from '@/hooks/useSession'
import { AppShell } from '@/components/AppShell'
import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'

/** Exactly the Documentable enum's backed values (plus '' for "Both", sent as `null` on
 * submit) — never a title-cased label as the value, or `Rule::enum` 400s. */
export const APPLIES_TO_OPTIONS: SelectOption[] = [
  { value: '', label: 'Both' },
  { value: 'employee', label: 'Employee' },
  { value: 'office', label: 'Office' },
]

const APPLIES_TO_LABEL: Record<'employee' | 'office', string> = {
  employee: 'Employee',
  office: 'Office',
}

/** Blank → null (never expires / field absent); a number → itself; anything else → invalid,
 * blocking submit rather than silently serializing `NaN`. Same shape as
 * `/admin/offices`'s own `parseOptionalNumber` — not shared, each admin screen keeps its
 * own copy, same as that page and `/office/leave-types` do. */
function parseOptionalNumber(raw: string): { value: number | null; invalid: boolean } {
  if (raw.trim() === '') return { value: null, invalid: false }
  const value = Number(raw)
  return Number.isNaN(value) ? { value: null, invalid: true } : { value, invalid: false }
}

/** The `document_catalog_in_use` detail shape, read off the `ApiError` only when the code
 * matches — every other failure falls back to generic copy via `genericErrorMessage`. */
function catalogInUseMessage(error: unknown): string | null {
  if (!(error instanceof ApiError) || error.code !== 'document_catalog_in_use') return null

  const details = error.details as Partial<{ subject_type: string; dependents: number }>
  const count = typeof details.dependents === 'number' ? details.dependents : 0
  const subject = details.subject_type === 'document' ? 'document kind' : 'category'
  const noun = count === 1 ? 'document' : 'documents'
  const verb = count === 1 ? 'uses' : 'use'
  return `${count} ${noun} still ${verb} this ${subject}.`
}

function genericErrorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Check your connection and try again.'
}

interface CheckboxFieldProps {
  id: string
  label: string
  checked: boolean
  onChange: (checked: boolean) => void
}

/** A token-styled checkbox — mirrors `/office/leave-types`'s `CheckboxField`/`/admin/offices`'s
 * `CheckboxToggle` raw `<input type="checkbox">` treatment; there is no tier-1 checkbox. */
function CheckboxField({ id, label, checked, onChange }: CheckboxFieldProps) {
  return (
    <label
      htmlFor={id}
      className="flex items-center"
      style={{ gap: 'var(--sp-xxs)', font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
    >
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        style={{ accentColor: 'var(--blue)' }}
      />
      {label}
    </label>
  )
}

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

const DEFAULT_CATEGORY_INPUT: DocumentCategoryWrite = { code: '', name: '', description: null }

function toCategoryInput(category: DocumentCategory): DocumentCategoryWrite {
  return { code: category.code, name: category.name, description: category.description }
}

type CategoryFormState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; category: DocumentCategory }

interface CategoryFormProps {
  initial: DocumentCategoryWrite
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: DocumentCategoryWrite) => void
}

/** Owns its own field state, remounted fresh per target via a `key` on the caller — same
 * idiom as `/admin/offices`'s `OfficeForm`. */
function CategoryForm({ initial, submitting, submitError, onCancel, onSubmit }: CategoryFormProps) {
  const [code, setCode] = useState(initial.code)
  const [name, setName] = useState(initial.name)
  const [description, setDescription] = useState(initial.description ?? '')

  const hasInvalidInput = code.trim() === '' || name.trim() === ''

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return

    onSubmit({ code, name, description: description.trim() === '' ? null : description })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="document-category-code" label="Code" value={code} onChange={setCode} required />
      <TextInput id="document-category-name" label="Name" value={name} onChange={setName} required />
      <TextInput
        id="document-category-description"
        label="Description"
        value={description}
        onChange={setDescription}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || hasInvalidInput}>
          Save
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface CategoryRowProps {
  category: DocumentCategory
  deleting: boolean
  canManage: boolean
  onEdit: () => void
  onDelete: () => void
}

function CategoryRow({ category, deleting, canManage, onEdit, onDelete }: CategoryRowProps) {
  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {category.name}
        </span>
        {canManage ? (
          <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
            <Button variant="ghost" onClick={onEdit}>
              Edit
            </Button>
            <Button variant="ghost" loading={deleting} disabled={deleting} onClick={onDelete}>
              Delete
            </Button>
          </span>
        ) : null}
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Code: {category.code}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {category.description ?? '—'}
        </span>
      </div>
    </li>
  )
}

// ---------------------------------------------------------------------------
// Document kinds
// ---------------------------------------------------------------------------

const DEFAULT_KIND_INPUT: DocumentKindWrite = {
  code: '',
  name: '',
  description: null,
  category_id: '',
  applies_to: null,
  is_required: false,
  validity_months: null,
}

function toKindInput(kind: DocumentKind): DocumentKindWrite {
  return {
    code: kind.code,
    name: kind.name,
    description: kind.description,
    category_id: kind.category_id,
    applies_to: kind.applies_to,
    is_required: kind.is_required,
    validity_months: kind.validity_months,
  }
}

type KindFormState = { mode: 'closed' } | { mode: 'add' } | { mode: 'edit'; kind: DocumentKind }

interface KindFormProps {
  initial: DocumentKindWrite
  categoryOptions: SelectOption[]
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: DocumentKindWrite) => void
}

function KindForm({ initial, categoryOptions, submitting, submitError, onCancel, onSubmit }: KindFormProps) {
  const [code, setCode] = useState(initial.code)
  const [name, setName] = useState(initial.name)
  const [description, setDescription] = useState(initial.description ?? '')
  const [categoryId, setCategoryId] = useState(initial.category_id || categoryOptions[0]?.value || '')
  const [appliesTo, setAppliesTo] = useState(initial.applies_to ?? '')
  const [isRequired, setIsRequired] = useState(initial.is_required ?? false)
  const [validityMonths, setValidityMonths] = useState(
    initial.validity_months != null ? String(initial.validity_months) : '',
  )

  const validity = parseOptionalNumber(validityMonths)
  const hasInvalidInput = code.trim() === '' || name.trim() === '' || categoryId === '' || validity.invalid

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (hasInvalidInput) return

    onSubmit({
      code,
      name,
      description: description.trim() === '' ? null : description,
      category_id: categoryId,
      // '' → null ("Both"); Rule::enum matches the backed value exactly, so the empty
      // string itself would 400 — it exists only as the Select's blank option.
      applies_to: appliesTo === '' ? null : (appliesTo as 'employee' | 'office'),
      is_required: isRequired,
      // Blank means "never expires" and must stay `null`, not fall through to `0` —
      // `validity_months` has a `min:1` server-side validator that a literal `0` would trip.
      validity_months: validity.value,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="document-kind-code" label="Code" value={code} onChange={setCode} required />
      <TextInput id="document-kind-name" label="Name" value={name} onChange={setName} required />
      <TextInput
        id="document-kind-description"
        label="Description"
        value={description}
        onChange={setDescription}
      />
      <Select
        id="document-kind-category"
        label="Category"
        value={categoryId}
        onChange={setCategoryId}
        options={categoryOptions}
      />
      <Select
        id="document-kind-applies-to"
        label="Applies to"
        value={appliesTo}
        onChange={setAppliesTo}
        options={APPLIES_TO_OPTIONS}
      />
      <CheckboxField id="document-kind-is-required" label="Required" checked={isRequired} onChange={setIsRequired} />
      <TextInput
        id="document-kind-validity-months"
        label="Validity (months)"
        value={validityMonths}
        onChange={setValidityMonths}
        error={validity.invalid ? 'Enter a whole number of months, or leave it blank for never expires.' : undefined}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || hasInvalidInput}>
          Save
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

interface KindRowProps {
  kind: DocumentKind
  categoryName: string
  deleting: boolean
  canManage: boolean
  onEdit: () => void
  onDelete: () => void
}

function KindRow({ kind, categoryName, deleting, canManage, onEdit, onDelete }: KindRowProps) {
  return (
    <li
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', background: 'var(--surface-1)', borderRadius: 'var(--radius)', padding: 'var(--sp-md)' }}
    >
      <div className="flex items-center justify-between flex-wrap" style={{ gap: 'var(--sp-sm)' }}>
        <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
          {kind.name}
        </span>
        {canManage ? (
          <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
            <Button variant="ghost" onClick={onEdit}>
              Edit
            </Button>
            <Button variant="ghost" loading={deleting} disabled={deleting} onClick={onDelete}>
              Delete
            </Button>
          </span>
        ) : null}
      </div>

      <div className="flex flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Code: {kind.code}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Category: {categoryName}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Applies to: {kind.applies_to === null ? 'Both' : APPLIES_TO_LABEL[kind.applies_to]}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {kind.is_required ? 'Required' : 'Optional'}
        </span>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          {kind.validity_months === null ? 'Never expires' : `Valid ${kind.validity_months} months`}
        </span>
      </div>
    </li>
  )
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function DocumentsPage() {
  const { session } = useSession()
  const catalogQuery = useDocumentCatalog()
  const { createCategory, updateCategory, deleteCategory, createKind, updateKind, deleteKind } =
    useSaveDocumentCatalog()

  // Unlike every sibling admin screen, this one is not is_system_admin-only:
  // DocumentPolicy::manageCatalog reads document.manage, which RbacSeeder grants to HR
  // Admin too (see the file docblock). `null` while the session hasn't loaded yet — treated
  // the same as "can't manage" below, but the notice itself waits for `session !== null` so
  // it doesn't flash before the session resolves.
  const canManage = session?.is_system_admin === true || (session?.permissions.includes('document.manage') ?? false)

  const [categoryFormState, setCategoryFormState] = useState<CategoryFormState>({ mode: 'closed' })
  const [kindFormState, setKindFormState] = useState<KindFormState>({ mode: 'closed' })
  const [deletingCategoryId, setDeletingCategoryId] = useState<string | null>(null)
  const [deletingKindId, setDeletingKindId] = useState<string | null>(null)

  const categories = catalogQuery.data?.categories ?? []
  const kinds = catalogQuery.data?.documents ?? []
  const categoryNameById = new Map(categories.map((category) => [category.id, category.name]))
  const categoryOptions: SelectOption[] = categories.map((category) => ({ value: category.id, label: category.name }))

  function closeCategoryForm(): void {
    setCategoryFormState({ mode: 'closed' })
  }

  function closeKindForm(): void {
    setKindFormState({ mode: 'closed' })
  }

  function handleCategorySubmit(input: DocumentCategoryWrite): void {
    if (categoryFormState.mode === 'add') {
      createCategory.mutate(input, { onSuccess: closeCategoryForm })
      return
    }

    if (categoryFormState.mode === 'edit') {
      updateCategory.mutate({ id: categoryFormState.category.id, body: input }, { onSuccess: closeCategoryForm })
    }
  }

  function handleKindSubmit(input: DocumentKindWrite): void {
    if (kindFormState.mode === 'add') {
      createKind.mutate(input, { onSuccess: closeKindForm })
      return
    }

    if (kindFormState.mode === 'edit') {
      updateKind.mutate({ id: kindFormState.kind.id, body: input }, { onSuccess: closeKindForm })
    }
  }

  function handleDeleteCategory(id: string): void {
    setDeletingCategoryId(id)
    deleteCategory.mutate(id, { onSettled: () => setDeletingCategoryId(null) })
  }

  function handleDeleteKind(id: string): void {
    setDeletingKindId(id)
    deleteKind.mutate(id, { onSettled: () => setDeletingKindId(null) })
  }

  const isCategoryEdit = categoryFormState.mode === 'edit'
  const activeCategoryMutation = isCategoryEdit ? updateCategory : createCategory
  const isKindEdit = kindFormState.mode === 'edit'
  const activeKindMutation = isKindEdit ? updateKind : createKind

  const categoryDeleteMessage = catalogInUseMessage(deleteCategory.error) ?? genericErrorMessage(deleteCategory.error)
  const kindDeleteMessage = catalogInUseMessage(deleteKind.error) ?? genericErrorMessage(deleteKind.error)

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Admin" title="Documents" level={1} />

        {session !== null && !canManage ? (
          <InlineNotification kind="info" title="This account can't administer documents.">
            Editing the document catalog needs the document.manage permission — held by HR
            Admins and system admins. You can still browse the categories and document kinds
            below.
          </InlineNotification>
        ) : null}

        {catalogQuery.isLoading ? (
          <Skeleton height="16rem" />
        ) : catalogQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load the document catalog.">
            Check your connection and try again.
          </InlineNotification>
        ) : (
          <>
            <section className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
              <SectionHeader
                title="Categories"
                actions={
                  canManage && categoryFormState.mode === 'closed' ? (
                    <Button onClick={() => setCategoryFormState({ mode: 'add' })}>New category</Button>
                  ) : undefined
                }
              />

              {categories.length === 0 && categoryFormState.mode === 'closed' ? (
                <EmptyState title="No categories yet">
                  Create the first one with “New category” above.
                </EmptyState>
              ) : (
                <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                  {categories.map((category) => (
                    <CategoryRow
                      key={category.id}
                      category={category}
                      deleting={deletingCategoryId === category.id && deleteCategory.isPending}
                      canManage={canManage}
                      onEdit={() => setCategoryFormState({ mode: 'edit', category })}
                      onDelete={() => handleDeleteCategory(category.id)}
                    />
                  ))}
                </ul>
              )}

              {deleteCategory.isError ? (
                <InlineNotification kind="error" title="That category couldn't be deleted.">
                  {categoryDeleteMessage}
                </InlineNotification>
              ) : null}

              {categoryFormState.mode !== 'closed' ? (
                <CategoryForm
                  key={categoryFormState.mode === 'add' ? 'add' : categoryFormState.category.id}
                  initial={
                    categoryFormState.mode === 'edit' ? toCategoryInput(categoryFormState.category) : DEFAULT_CATEGORY_INPUT
                  }
                  submitting={activeCategoryMutation.isPending}
                  submitError={activeCategoryMutation.isError}
                  onCancel={closeCategoryForm}
                  onSubmit={handleCategorySubmit}
                />
              ) : null}
            </section>

            <section className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
              <SectionHeader
                title="Document kinds"
                actions={
                  canManage && kindFormState.mode === 'closed' && categories.length > 0 ? (
                    <Button onClick={() => setKindFormState({ mode: 'add' })}>New document kind</Button>
                  ) : undefined
                }
              />

              {categories.length === 0 ? (
                <EmptyState title="No document kinds yet">
                  Create a category first, then add its document kinds here.
                </EmptyState>
              ) : kinds.length === 0 && kindFormState.mode === 'closed' ? (
                <EmptyState title="No document kinds yet">
                  Create the first one with “New document kind” above.
                </EmptyState>
              ) : (
                <ul className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
                  {kinds.map((kind) => (
                    <KindRow
                      key={kind.id}
                      kind={kind}
                      categoryName={categoryNameById.get(kind.category_id) ?? '—'}
                      deleting={deletingKindId === kind.id && deleteKind.isPending}
                      canManage={canManage}
                      onEdit={() => setKindFormState({ mode: 'edit', kind })}
                      onDelete={() => handleDeleteKind(kind.id)}
                    />
                  ))}
                </ul>
              )}

              {deleteKind.isError ? (
                <InlineNotification kind="error" title="That document kind couldn't be deleted.">
                  {kindDeleteMessage}
                </InlineNotification>
              ) : null}

              {kindFormState.mode !== 'closed' ? (
                <KindForm
                  key={kindFormState.mode === 'add' ? 'add' : kindFormState.kind.id}
                  initial={kindFormState.mode === 'edit' ? toKindInput(kindFormState.kind) : DEFAULT_KIND_INPUT}
                  categoryOptions={categoryOptions}
                  submitting={activeKindMutation.isPending}
                  submitError={activeKindMutation.isError}
                  onCancel={closeKindForm}
                  onSubmit={handleKindSubmit}
                />
              ) : null}
            </section>
          </>
        )}
      </div>
    </AppShell>
  )
}
