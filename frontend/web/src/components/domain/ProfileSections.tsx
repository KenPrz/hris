'use client'

/**
 * The five sections of a personnel file, presentational only. Shared by /me/profile (read)
 * and `/employees/{id}/profile` (the HR-Admin/System-Admin full read, alongside
 * `ProfileForm` for the edit half), so the two can never disagree about what a personnel
 * file looks like.
 *
 * `ProfileSummarySections` below renders the OTHER shape `/employees/{id}/profile` can
 * receive — `EmployeeProfileSummary`, what a manager sees of a direct report. It is a
 * separate function over a separate (non-`Partial`) type, not a conditional inside
 * `ProfileSections`, mirroring why the backend keeps `EmployeeProfileSummaryResource` a
 * separate class from `EmployeeProfileResource`: a field added to the full shape must not
 * silently leak into the redacted one.
 */

import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import type { EmployeeProfile, EmployeeProfileSummary } from '@/lib/api'
import { BLOOD_TYPE_OPTIONS, GENDER_OPTIONS, LABOR_TYPE_OPTIONS, labelForOption, MARITAL_STATUS_OPTIONS } from '@/lib/profileOptions'

/** Null renders as an em dash, never as blank space — "we have no value" must be visible. */
function value(raw: string | number | null | undefined): string {
  return raw === null || raw === undefined || raw === '' ? '—' : String(raw)
}

export function DefinitionList({ items }: { items: Array<[string, string | number | null]> }) {
  return (
    <dl
      className="grid"
      style={{
        gridTemplateColumns: 'var(--dl-label-col) 1fr',
        gap: 'var(--sp-xs) var(--sp-md)',
        margin: 0,
      }}
    >
      {items.map(([label, raw], index) => (
        // Index alongside the label, not the label alone: two dependents sharing a
        // relationship (e.g. two children) — or two identifications with no resolved
        // category name — produce duplicate labels, and `key={label}` on its own collides.
        <div key={`${index}-${label}`} style={{ display: 'contents' }}>
          <dt style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {label}
          </dt>
          <dd style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)', margin: 0 }}>
            {value(raw)}
          </dd>
        </div>
      ))}
    </dl>
  )
}

/** The nine Assignment rows, shared verbatim by the full and redacted sections below —
 * `ProfileAssignment` is identical on both resources (`EmployeeAssignmentPresenter` on the
 * backend), so there is exactly one place that decides what a row's label is. */
function assignmentItems(assignment: EmployeeProfile['assignment']): Array<[string, string | null]> {
  return [
    ['Designation', assignment.designation],
    ['Business Unit', assignment.business_unit],
    ['Reporting To', assignment.reports_to],
    // `employment_status` (EmploymentRecord.employment_type) is rendered raw, deliberately
    // — RecordEmploymentRequest validates it as a free string, not a Rule::enum() backed
    // set, so there is no closed set on the backend for a frontend label list to claim
    // authority over. `labor_type` IS one (see LABOR_TYPE_OPTIONS' own doc comment).
    ['Employment Status', assignment.employment_status],
    ['Location', assignment.location],
    ['Region', assignment.region],
    ['Labor Type', labelForOption(LABOR_TYPE_OPTIONS, assignment.labor_type)],
    ['Date Hired', assignment.hired_at],
    ['Work Shift', assignment.work_shift],
  ]
}

export function ProfileSections({ profile }: { profile: EmployeeProfile }) {
  const { details, contact, personal, assignment, dependents, identifications } = profile

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <section>
        <SectionHeader title="Details" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList
            items={[
              ['Employee ID', profile.employee_no],
              ['Salutation', details.salutation],
              ['Firstname', details.first_name],
              ['Middle Name', details.middle_name],
              ['Last Name', details.last_name],
              ['Suffix', details.name_suffix],
              ['Nickname', details.nickname],
            ]}
          />
        </div>
      </section>

      <section>
        <SectionHeader title="Contact" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList
            items={[
              ['Home', contact.home_address],
              ['Personal Email', contact.personal_email],
              ['Phone', contact.phone],
              ['Fax', contact.fax],
              ['Mobile', contact.mobile],
              ['Emergency', contact.emergency_contact],
            ]}
          />
        </div>
      </section>

      <section>
        <SectionHeader title="Personal" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList
            items={[
              // gender/marital_status/blood_type are the backend's BACKED enum values
              // (`'male'`, `'single'`), not display text — `labelForOption` resolves each
              // through the same option arrays `ProfileForm`'s `Select`s offer, so the read
              // view and the edit view can never disagree about what a value is called.
              ['Gender', labelForOption(GENDER_OPTIONS, personal.gender)],
              ['Birthday', personal.birth_date],
              // The backend sends a number; the label is a display concern, so it is composed
              // here rather than shipped as a pre-formatted string.
              ['Age', personal.age === null ? null : `${personal.age} Years Old`],
              ['Birthplace', personal.birthplace],
              ['Marital Status', labelForOption(MARITAL_STATUS_OPTIONS, personal.marital_status)],
              ['Citizenship', personal.citizenship],
              ['Religion', personal.religion],
              ['Blood Type', labelForOption(BLOOD_TYPE_OPTIONS, personal.blood_type)],
            ]}
          />
        </div>
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <SectionHeader title="Dependents" />
          <div style={{ marginTop: 'var(--sp-md)' }}>
            {dependents.length === 0 ? (
              <EmptyState title="No dependents on file." />
            ) : (
              <DefinitionList
                items={dependents.map((d): [string, string] => [
                  // `relationship_label` is the catalog description ('Spouse'), added
                  // alongside `relationship` (the CODE, 'spouse') purely for display —
                  // `ProfileForm` still matches on `relationship` to pre-select a dependent's
                  // catalog entry when editing, so that field's meaning must not change.
                  d.relationship_label ?? d.relationship ?? 'Dependent',
                  d.birth_date === null ? d.name : `${d.name} · ${d.birth_date}`,
                ])}
              />
            )}
          </div>
        </div>
      </section>

      <section>
        <SectionHeader title="Assignment" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList items={assignmentItems(assignment)} />
        </div>
      </section>

      <section>
        <SectionHeader title="National IDs" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          {identifications.length === 0 ? (
            <EmptyState title="No identification numbers on file." />
          ) : (
            <DefinitionList
              items={identifications.map((i): [string, string] => [
                i.category_name ?? i.category_code ?? 'ID',
                i.number,
              ])}
            />
          )}
        </div>
      </section>
    </div>
  )
}

/**
 * What a manager sees of a direct report — contact plus assignment, nothing else. Renders
 * `EmployeeProfileSummary`, NOT a filtered `EmployeeProfile`: there is no personal section,
 * no dependents, no identifications, and no home address to fall back to, matching
 * `EmployeeProfileSummaryResource` on the wire (see the M10a spec, "Redaction").
 */
export function ProfileSummarySections({ summary }: { summary: EmployeeProfileSummary }) {
  const { contact, assignment } = summary

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <section>
        <SectionHeader title="Contact" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList
            items={[
              ['Personal Email', contact.personal_email],
              ['Phone', contact.phone],
              ['Mobile', contact.mobile],
            ]}
          />
        </div>
      </section>

      <section>
        <SectionHeader title="Assignment" />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <DefinitionList items={assignmentItems(assignment)} />
        </div>
      </section>
    </div>
  )
}
