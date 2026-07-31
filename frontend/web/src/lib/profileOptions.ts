/**
 * The three closed sets `Rule::enum()` validates on the backend — verified against
 * app/Domain/Profile/{Gender,MaritalStatus,BloodType}.php. Case names cannot contain '+'/
 * '-', so BloodType's backed values carry the real notation directly.
 *
 * Shared by `ProfileForm` (the `Select` options offered when editing) and `ProfileSections`
 * (the read view's label for an already-stored backed value) — ONE place a backed value's
 * display label is defined, so the read view and the edit view can never disagree about
 * what a value is called (see `ProfileForm`'s doc comment on that invariant). Exported so a
 * test can assert the exact backed values on offer without depending on `Select`'s internal
 * DOM shape (Radix's `Select.Item` renders no real `<option>` element to introspect).
 */

import type { SelectOption } from '@/components/ui/Select'

export const GENDER_OPTIONS: SelectOption[] = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
]

export const MARITAL_STATUS_OPTIONS: SelectOption[] = [
  { value: 'single', label: 'Single' },
  { value: 'married', label: 'Married' },
  { value: 'widowed', label: 'Widowed' },
  { value: 'separated', label: 'Separated' },
  { value: 'annulled', label: 'Annulled' },
]

export const BLOOD_TYPE_OPTIONS: SelectOption[] = [
  { value: 'A+', label: 'A+' },
  { value: 'A-', label: 'A-' },
  { value: 'B+', label: 'B+' },
  { value: 'B-', label: 'B-' },
  { value: 'AB+', label: 'AB+' },
  { value: 'AB-', label: 'AB-' },
  { value: 'O+', label: 'O+' },
  { value: 'O-', label: 'O-' },
]

/**
 * Resolves one of the closed-set backed values above to its display label — `'male'` →
 * `'Male'`. Falls back to the raw value itself when it doesn't match any known option (e.g.
 * stale data from a removed enum case) rather than going blank, since "we don't recognize
 * this" still deserves *something* on screen. `null` stays `null` so the caller's own
 * em-dash-for-missing-value rendering (`ProfileSections`' `value()`) still applies.
 */
export function labelForOption(options: SelectOption[], value: string | null): string | null {
  if (value === null) return null
  return options.find((option) => option.value === value)?.label ?? value
}
