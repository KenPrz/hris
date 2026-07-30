'use client'

/**
 * The five sections of a personnel file, presentational only. Shared by /me/profile (read)
 * and the admin employee Profile tab (read + edit), so the two can never disagree about
 * what a personnel file looks like.
 */

import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import type { EmployeeProfile } from '@/lib/api'

/** Null renders as an em dash, never as blank space — "we have no value" must be visible. */
function value(raw: string | number | null | undefined): string {
  return raw === null || raw === undefined || raw === '' ? '—' : String(raw)
}

export function DefinitionList({ items }: { items: Array<[string, string | number | null]> }) {
  return (
    <dl
      className="grid"
      style={{
        gridTemplateColumns: 'minmax(8rem, 14rem) 1fr',
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
              ['Email', contact.personal_email],
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
              ['Gender', personal.gender],
              ['Birthday', personal.birth_date],
              // The backend sends a number; the label is a display concern, so it is composed
              // here rather than shipped as a pre-formatted string.
              ['Age', personal.age === null ? null : `${personal.age} Years Old`],
              ['Birthplace', personal.birthplace],
              ['Marital Status', personal.marital_status],
              ['Citizenship', personal.citizenship],
              ['Religion', personal.religion],
              ['Blood Type', personal.blood_type],
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
                  d.relationship ?? 'Dependent',
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
          <DefinitionList
            items={[
              ['Designation', assignment.designation],
              ['Business Unit', assignment.business_unit],
              ['Reporting To', assignment.reports_to],
              ['Employment Status', assignment.employment_status],
              ['Location', assignment.location],
              ['Region', assignment.region],
              ['Labor Type', assignment.labor_type],
              ['Date Hired', assignment.hired_at],
              ['Work Shift', assignment.work_shift],
            ]}
          />
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
