'use client'

/**
 * The admin Profile tab's edit surface (M10a Task 14) — three independent submits against
 * three independent endpoints (personal details, dependents, identifications), because
 * they are three separate writes and one combined save would need a transaction the API
 * does not offer. `profile`/`relationships`/`categories` are already-resolved data the
 * caller (the admin employee detail page, via `useEmployeeProfile`/`useProfileCatalog`)
 * fetches; this component owns only field state and the four mutations from
 * `useSaveProfile`.
 *
 * Labels mirror `ProfileSections`' `DefinitionList` labels exactly (e.g. "Home" for
 * `home_address`, "Birthday" for `birth_date`) — the read view and the edit view describe
 * the same personnel file and must never disagree about what a field is called. The one
 * exception is `personal_email`, labelled "Personal Email" on both sides rather than bare
 * "Email" — this same admin page's "Provision login" form has its own, unrelated "Email"
 * field (a login), and the two forms render at once.
 *
 * Gender, marital status, and blood type are validated backend-side with `Rule::enum()`,
 * which matches the BACKED VALUE exactly — `'male'`, never `'Male'`. They use the tier-1
 * `Select` like every other dropdown on this page (six others on this same admin employee
 * screen alone). `GENDER_OPTIONS`/`MARITAL_STATUS_OPTIONS`/`BLOOD_TYPE_OPTIONS` live in
 * `@/lib/profileOptions`, not here — `ProfileSections` needs the same label set to display
 * an already-stored value, and a second copy of the label strings would drift from this
 * one. Re-exported below so existing imports of them from this module keep working.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'

import type {
  DependentWrite,
  EmployeeProfile,
  ProfileCatalog,
  ProfileIdentification,
  ProfileWriteBody,
} from '@/lib/api'
import { ApiError } from '@/lib/api'
import type { IdentificationFields } from '@/hooks/useSaveProfile'
import { useSaveProfile } from '@/hooks/useSaveProfile'
import { uuidV4 } from '@/lib/uuid'
import { BLOOD_TYPE_OPTIONS, GENDER_OPTIONS, MARITAL_STATUS_OPTIONS } from '@/lib/profileOptions'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { TextInput } from '@/components/ui/TextInput'
import { IdentificationScan } from '@/components/domain/IdentificationScan'

export interface ProfileFormProps {
  profile: EmployeeProfile
  relationships: ProfileCatalog['relationships']
  /** A subset of `ProfileCatalog['identification_categories']` — only what the form needs
   * to populate the category picker. */
  categories: Array<{ id: string; code: string; name: string }>
}

const ACCEPTED_SCAN_TYPES = '.pdf,.jpg,.jpeg,.png'

// Re-exported so this module stays the one place other code (and this file's own test)
// imports the three closed-set option arrays from — the arrays themselves live in
// `@/lib/profileOptions` because `ProfileSections` needs them too, for read-view labels.
export { BLOOD_TYPE_OPTIONS, GENDER_OPTIONS, MARITAL_STATUS_OPTIONS }

/** A blank/unselected option prepended at each closed-set `Select`'s call site (not baked
 * into the exported *_OPTIONS constants themselves, which must stay exactly the backed
 * enum values for the constant-based test to assert against) — mirrors `EmploymentForm`'s
 * `[{ value: '', label: 'Select an office' }, ...officeOptions]` pattern on this same page
 * for a nullable field that starts out unset. */
function withBlank(label: string, options: SelectOption[]): SelectOption[] {
  return [{ value: '', label }, ...options]
}

function apiErrorMessage(error: unknown): string | null {
  return error instanceof ApiError ? error.message : null
}

// ---------------------------------------------------------------------------
// Personal details — salutation/nickname/contact/personal, i.e. every key
// ProfileWriteBody carries. Assignment fields are edited elsewhere (employment history,
// via the page's existing "Record employment change" form) and are not part of this
// endpoint at all.
// ---------------------------------------------------------------------------

interface PersonalDetailsFormProps {
  profile: EmployeeProfile
  submitting: boolean
  submitError: string | null
  onSubmit: (body: ProfileWriteBody) => void
}

function PersonalDetailsForm({ profile, submitting, submitError, onSubmit }: PersonalDetailsFormProps) {
  const [salutation, setSalutation] = useState(profile.details.salutation ?? '')
  const [nickname, setNickname] = useState(profile.details.nickname ?? '')
  const [homeAddress, setHomeAddress] = useState(profile.contact.home_address ?? '')
  const [personalEmail, setPersonalEmail] = useState(profile.contact.personal_email ?? '')
  const [phone, setPhone] = useState(profile.contact.phone ?? '')
  const [fax, setFax] = useState(profile.contact.fax ?? '')
  const [mobile, setMobile] = useState(profile.contact.mobile ?? '')
  const [emergencyContact, setEmergencyContact] = useState(profile.contact.emergency_contact ?? '')
  const [gender, setGender] = useState(profile.personal.gender ?? '')
  const [birthDate, setBirthDate] = useState(profile.personal.birth_date ?? '')
  const [birthplace, setBirthplace] = useState(profile.personal.birthplace ?? '')
  const [maritalStatus, setMaritalStatus] = useState(profile.personal.marital_status ?? '')
  const [citizenship, setCitizenship] = useState(profile.personal.citizenship ?? '')
  const [religion, setReligion] = useState(profile.personal.religion ?? '')
  const [bloodType, setBloodType] = useState(profile.personal.blood_type ?? '')

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()

    const body: ProfileWriteBody = {
      salutation: nullIfBlank(salutation),
      nickname: nullIfBlank(nickname),
      home_address: nullIfBlank(homeAddress),
      personal_email: nullIfBlank(personalEmail),
      phone: nullIfBlank(phone),
      fax: nullIfBlank(fax),
      mobile: nullIfBlank(mobile),
      emergency_contact: nullIfBlank(emergencyContact),
      gender: nullIfBlank(gender),
      birth_date: nullIfBlank(birthDate),
      birthplace: nullIfBlank(birthplace),
      marital_status: nullIfBlank(maritalStatus),
      citizenship: nullIfBlank(citizenship),
      religion: nullIfBlank(religion),
      blood_type: nullIfBlank(bloodType),
    }

    onSubmit(body)
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <TextInput id="profile-salutation" label="Salutation" value={salutation} onChange={setSalutation} />
      <TextInput id="profile-nickname" label="Nickname" value={nickname} onChange={setNickname} />
      <TextInput id="profile-home-address" label="Home" value={homeAddress} onChange={setHomeAddress} />
      <TextInput
        id="profile-personal-email"
        label="Personal Email"
        type="email"
        value={personalEmail}
        onChange={setPersonalEmail}
      />
      <TextInput id="profile-phone" label="Phone" value={phone} onChange={setPhone} />
      <TextInput id="profile-fax" label="Fax" value={fax} onChange={setFax} />
      <TextInput id="profile-mobile" label="Mobile" value={mobile} onChange={setMobile} />
      <TextInput id="profile-emergency" label="Emergency" value={emergencyContact} onChange={setEmergencyContact} />
      <Select
        id="profile-gender"
        label="Gender"
        value={gender}
        onChange={setGender}
        options={withBlank('Select gender', GENDER_OPTIONS)}
      />
      <TextInput id="profile-birth-date" label="Birthday" type="date" value={birthDate} onChange={setBirthDate} />
      <TextInput id="profile-birthplace" label="Birthplace" value={birthplace} onChange={setBirthplace} />
      <Select
        id="profile-marital-status"
        label="Marital Status"
        value={maritalStatus}
        onChange={setMaritalStatus}
        options={withBlank('Select marital status', MARITAL_STATUS_OPTIONS)}
      />
      <TextInput id="profile-citizenship" label="Citizenship" value={citizenship} onChange={setCitizenship} />
      <TextInput id="profile-religion" label="Religion" value={religion} onChange={setReligion} />
      <Select
        id="profile-blood-type"
        label="Blood Type"
        value={bloodType}
        onChange={setBloodType}
        options={withBlank('Select blood type', BLOOD_TYPE_OPTIONS)}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          {submitError}
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting}>
          Save profile
        </Button>
      </div>
    </form>
  )
}

/** `''` → `null` (never an empty string on the wire); anything else passes through as-is. */
function nullIfBlank(value: string): string | null {
  return value.trim() === '' ? null : value
}

// ---------------------------------------------------------------------------
// Dependents — `PUT /admin/employees/{id}/dependents` REPLACES the whole set, so this
// form always sends every current row, not a diff.
// ---------------------------------------------------------------------------

interface DependentRow {
  /** Local-only identity for React keys and row editing — never sent to the backend. */
  key: string
  name: string
  relationshipId: string
  birthDate: string
}

function defaultRelationshipId(relationships: ProfileCatalog['relationships']): string {
  return relationships[0]?.id ?? ''
}

/**
 * `ProfileDependent` (the read side) carries the relationship's CODE, not a description and
 * not an id — verified against `EmployeeProfileResource::toArray`
 * (`'relationship' => $dependent->relationship?->code`), e.g. `'spouse'`. Re-deriving the id
 * by matching on `code` is the only way to pre-select the right catalog entry when editing
 * an existing dependent.
 *
 * This used to match on `description` instead (`'Spouse'`), which NEVER matched — every
 * seeded relationship's code and description differ only in case, so every existing
 * dependent silently fell through to `defaultRelationshipId` (`relationships[0]`, which is
 * `'child'` under `ProfileCatalogSeeder`'s `orderBy('code')`). Because `PUT .../dependents`
 * REPLACES the whole set, saving after that silent fallback rewrote EVERY dependent's
 * relationship to "Child" — including spouses. The fallback below still exists for a
 * genuinely renamed/removed catalog entry; that is now the rare case, not the only case.
 */
function initialDependentRows(
  profile: EmployeeProfile,
  relationships: ProfileCatalog['relationships'],
): DependentRow[] {
  return profile.dependents.map((dependent) => ({
    key: dependent.id,
    name: dependent.name,
    relationshipId:
      relationships.find((relationship) => relationship.code === dependent.relationship)?.id ??
      defaultRelationshipId(relationships),
    birthDate: dependent.birth_date ?? '',
  }))
}

interface DependentsFormProps {
  profile: EmployeeProfile
  relationships: ProfileCatalog['relationships']
  submitting: boolean
  submitError: string | null
  onSubmit: (dependents: DependentWrite[]) => void
}

function DependentsForm({ profile, relationships, submitting, submitError, onSubmit }: DependentsFormProps) {
  const [rows, setRows] = useState<DependentRow[]>(() => initialDependentRows(profile, relationships))

  const relationshipOptions: SelectOption[] = relationships.map((relationship) => ({
    value: relationship.id,
    label: relationship.description,
  }))

  function addRow(): void {
    setRows((current) => [
      ...current,
      { key: uuidV4(), name: '', relationshipId: defaultRelationshipId(relationships), birthDate: '' },
    ])
  }

  function removeRow(key: string): void {
    setRows((current) => current.filter((row) => row.key !== key))
  }

  function updateRow(key: string, patch: Partial<Omit<DependentRow, 'key'>>): void {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  }

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()

    // Direct keys, not a conditional spread: a spread's resulting object type isn't
    // subject to excess-property checking, so a `birthDate`/`birth_date` typo here would
    // typecheck clean and silently drop the date on the wire. The explicit `: DependentWrite`
    // return-type annotation on the callback ITSELF is load-bearing, not decoration — this
    // project's typecheck runs through `tsgo` (`@typescript/native-preview`), and confirmed
    // by a throwaway repro, `tsgo` does NOT excess-property-check an arrow function's
    // returned object literal through `.map()`'s generic inference, even with the target
    // array type annotated on the `const` (`rows.map((row) => (...))`) or an explicit
    // `.map<DependentWrite>(...)` type argument — only an explicit return-type annotation
    // directly on the callback reliably triggers it under `tsgo`.
    const dependents: DependentWrite[] = rows.map((row): DependentWrite => ({
      name: row.name.trim(),
      relationship_id: row.relationshipId,
      birth_date: row.birthDate !== '' ? row.birthDate : null,
    }))

    onSubmit(dependents)
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      {rows.length === 0 ? (
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          No dependents on file.
        </span>
      ) : (
        <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
          {rows.map((row) => (
            <div
              key={row.key}
              className="flex flex-col"
              style={{ gap: 'var(--sp-xs)', padding: 'var(--sp-sm)', background: 'var(--surface-1)', borderRadius: 'var(--radius)' }}
            >
              <TextInput
                id={`dependent-name-${row.key}`}
                label="Dependent name"
                value={row.name}
                onChange={(value) => updateRow(row.key, { name: value })}
              />
              <Select
                id={`dependent-relationship-${row.key}`}
                label="Relationship"
                value={row.relationshipId}
                onChange={(value) => updateRow(row.key, { relationshipId: value })}
                options={relationshipOptions}
              />
              <TextInput
                id={`dependent-birth-date-${row.key}`}
                label="Dependent birthday"
                type="date"
                value={row.birthDate}
                onChange={(value) => updateRow(row.key, { birthDate: value })}
              />
              <div>
                <Button type="button" variant="ghost" onClick={() => removeRow(row.key)}>
                  Remove dependent
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div>
        <Button type="button" variant="secondary" onClick={addRow}>
          Add dependent
        </Button>
      </div>

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          {submitError}
        </InlineNotification>
      ) : null}

      <div>
        <Button type="submit" loading={submitting} disabled={submitting}>
          Save dependents
        </Button>
      </div>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Identifications — `POST .../identifications` upserts ONE identification by
// (employee, category): a second save for the same category corrects that category's
// existing row rather than adding another. So this is one add/update form (not a
// row-per-identification list like dependents), plus the read-only list of what is
// already on file below it, each with an optional scan preview and its own delete.
// ---------------------------------------------------------------------------

function categoryIdForIdentification(
  identification: ProfileIdentification,
  categories: Array<{ id: string; code: string; name: string }>,
): string | undefined {
  return categories.find((category) => category.code === identification.category_code)?.id
}

interface IdentificationsFormProps {
  profile: EmployeeProfile
  categories: Array<{ id: string; code: string; name: string }>
  submitting: boolean
  submitError: string | null
  onSubmit: (fields: IdentificationFields) => void
  /** The id of the identification currently being deleted, or `null` if none is — NOT a
   * shared `deleteIdentification.isPending` boolean, which would disable every row's
   * Delete button while any ONE row's delete is in flight. */
  deletingId: string | null
  onDelete: (identificationId: string) => void
}

function IdentificationsForm({
  profile,
  categories,
  submitting,
  submitError,
  onSubmit,
  deletingId,
  onDelete,
}: IdentificationsFormProps) {
  const categoryOptions: SelectOption[] = categories.map((category) => ({
    value: category.id,
    label: category.name,
  }))

  const existingByCategoryId = new Map<string, ProfileIdentification>()
  for (const identification of profile.identifications) {
    const categoryId = categoryIdForIdentification(identification, categories)
    if (categoryId !== undefined) existingByCategoryId.set(categoryId, identification)
  }

  const initialCategoryId = categories[0]?.id ?? ''
  const initialExisting = existingByCategoryId.get(initialCategoryId)

  const [categoryId, setCategoryId] = useState(initialCategoryId)
  const [number, setNumber] = useState(initialExisting?.number ?? '')
  const [issuedOn, setIssuedOn] = useState(initialExisting?.issued_on ?? '')
  const [expiresOn, setExpiresOn] = useState(initialExisting?.expires_on ?? '')
  const [notes, setNotes] = useState(initialExisting?.notes ?? '')
  // A save with no chosen file must never clear an existing scan — the backend
  // preserves it deliberately when `scan` is absent from the request. `null` here means
  // exactly that: "no new file", not "clear the file".
  const [scan, setScan] = useState<File | null>(null)

  const isValid = categoryId !== '' && number.trim() !== ''

  // Picking a different category loads that category's existing identification (if any)
  // into the form so re-saving corrects it rather than accidentally overwriting a real
  // number with blanks; picking a category with nothing on file yet clears the fields.
  function handleCategoryChange(nextCategoryId: string): void {
    const existing = existingByCategoryId.get(nextCategoryId)
    setCategoryId(nextCategoryId)
    setNumber(existing?.number ?? '')
    setIssuedOn(existing?.issued_on ?? '')
    setExpiresOn(existing?.expires_on ?? '')
    setNotes(existing?.notes ?? '')
    setScan(null)
  }

  function handleSubmit(event: FormEvent): void {
    event.preventDefault()
    if (!isValid) return

    onSubmit({
      category_id: categoryId,
      number: number.trim(),
      // Direct keys with `undefined` for "omit", not a conditional spread — a spread's
      // result isn't excess-property-checked, so `issuedOn`/`issued_on` (or the
      // `expires_on` equivalent) would typecheck clean while silently never reaching the
      // wire. `api.profile.saveIdentification` already skips any field that is
      // `undefined` when building the FormData.
      issued_on: issuedOn !== '' ? issuedOn : undefined,
      expires_on: expiresOn !== '' ? expiresOn : undefined,
      ...(notes.trim() !== '' ? { notes: notes.trim() } : {}),
      ...(scan !== null ? { scan } : {}),
    })
  }

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
        <Select
          id="identification-category"
          label="Category"
          value={categoryId}
          onChange={handleCategoryChange}
          options={categoryOptions}
        />
        <TextInput id="identification-number" label="ID number" value={number} onChange={setNumber} required />
        <TextInput id="identification-issued-on" label="Issued" type="date" value={issuedOn} onChange={setIssuedOn} />
        <TextInput
          id="identification-expires-on"
          label="Expires"
          type="date"
          value={expiresOn}
          onChange={setExpiresOn}
        />
        <TextInput id="identification-notes" label="Notes" value={notes} onChange={setNotes} />

        <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
          <label
            htmlFor="identification-scan"
            style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
          >
            Scan
          </label>
          <input
            id="identification-scan"
            type="file"
            accept={ACCEPTED_SCAN_TYPES}
            onChange={(event) => setScan(event.target.files?.[0] ?? null)}
            style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}
          />
          <span style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-muted)' }}>
            Leave blank to keep the existing scan on file.
          </span>
        </div>

        {submitError ? (
          <InlineNotification kind="error" title="That didn't save.">
            {submitError}
          </InlineNotification>
        ) : null}

        <div>
          <Button type="submit" loading={submitting} disabled={submitting || !isValid}>
            Save identification
          </Button>
        </div>
      </form>

      {profile.identifications.length > 0 ? (
        <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
          {profile.identifications.map((identification) => (
            <IdentificationRow
              key={identification.id}
              employeeId={profile.employee_id}
              identification={identification}
              deleting={identification.id === deletingId}
              onDelete={() => onDelete(identification.id)}
            />
          ))}
        </div>
      ) : null}
    </div>
  )
}

interface IdentificationRowProps {
  employeeId: string
  identification: ProfileIdentification
  deleting: boolean
  onDelete: () => void
}

function IdentificationRow({ employeeId, identification, deleting, onDelete }: IdentificationRowProps) {
  const [showScan, setShowScan] = useState(false)

  return (
    <div
      className="flex flex-col"
      style={{ gap: 'var(--sp-xs)', padding: 'var(--sp-sm)', background: 'var(--surface-1)', borderRadius: 'var(--radius)' }}
    >
      <div className="flex items-center justify-between" style={{ gap: 'var(--sp-sm)' }}>
        <div className="flex flex-col">
          <span style={{ font: 'var(--t-emphasis)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>
            {identification.category_name ?? identification.category_code ?? 'ID'}
          </span>
          <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {identification.number}
          </span>
        </div>
        <div className="flex items-center" style={{ gap: 'var(--sp-xs)' }}>
          {identification.has_scan ? (
            <Button type="button" variant="ghost" onClick={() => setShowScan((current) => !current)}>
              {showScan ? 'Hide scan' : 'View scan'}
            </Button>
          ) : null}
          <Button type="button" variant="danger" loading={deleting} disabled={deleting} onClick={onDelete}>
            Delete
          </Button>
        </div>
      </div>
      {showScan ? <IdentificationScan employeeId={employeeId} identificationId={identification.id} /> : null}
    </div>
  )
}

// ---------------------------------------------------------------------------

export function ProfileForm({ profile, relationships, categories }: ProfileFormProps) {
  const { saveProfile, saveDependents, saveIdentification, deleteIdentification } = useSaveProfile(
    profile.employee_id,
  )

  // Tracked separately from `deleteIdentification.isPending` — that flag is shared across
  // every row (there's one mutation object for the whole form), so using it directly would
  // disable EVERY row's Delete button while any one row's delete is in flight.
  const [deletingId, setDeletingId] = useState<string | null>(null)

  function handleDeleteIdentification(identificationId: string): void {
    setDeletingId(identificationId)
    deleteIdentification.mutate(identificationId, { onSettled: () => setDeletingId(null) })
  }

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
        <SectionHeader title="Personal details" level={2} />
        <PersonalDetailsForm
          profile={profile}
          submitting={saveProfile.isPending}
          submitError={apiErrorMessage(saveProfile.error)}
          onSubmit={(body) => saveProfile.mutate(body)}
        />
      </div>

      <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
        <SectionHeader title="Dependents" level={2} />
        <DependentsForm
          profile={profile}
          relationships={relationships}
          submitting={saveDependents.isPending}
          submitError={apiErrorMessage(saveDependents.error)}
          onSubmit={(dependents) => saveDependents.mutate(dependents)}
        />
      </div>

      <div className="flex flex-col" style={{ gap: 'var(--sp-sm)' }}>
        <SectionHeader title="Identifications" level={2} />
        <IdentificationsForm
          profile={profile}
          categories={categories}
          submitting={saveIdentification.isPending}
          submitError={apiErrorMessage(saveIdentification.error)}
          onSubmit={(fields) => saveIdentification.mutate(fields)}
          deletingId={deletingId}
          onDelete={handleDeleteIdentification}
        />
      </div>
    </div>
  )
}
