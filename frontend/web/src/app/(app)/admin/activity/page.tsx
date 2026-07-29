'use client'

/**
 * The activity-log viewer (M8c) — a read-only, filterable, paginated window onto
 * `GET /admin/activity`, sysadmin-gated like the rest of `/admin/*`. Mirrors the org
 * tree's screen shape (`/admin/offices`) for chrome, but there is nothing to create or
 * mutate here: no dialog, no row actions, just filters driving a table.
 *
 * Filter state is local (`useState`), not URL-synced — the task only asked for local
 * state, matching how `/admin/offices`'s organization/show-archived filters work. Every
 * filter change resets `page` to 1 so a stale second page never renders against a new,
 * possibly shorter, result set. `log_name`/`event` use a `''` sentinel for "all", stripped
 * before being sent (see `api.admin.activity.list`'s query-string builder), so the
 * `ActivityFilters` this page holds always mirrors what the request actually sent.
 */

import { useState } from 'react'

import type { ActivityEntry, ActivityFilters } from '@/lib/api'
import { useActivityLog } from '@/hooks/useActivityLog'
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

// The known log names `LogsActivity` writes under across the org tree, employee profiler,
// and cutoffs/leave-types screens — not an exhaustive server-enforced enum, just what this
// filter offers. `''` is the "all" sentinel, stripped before it reaches the API.
const LOG_NAME_OPTIONS: SelectOption[] = [
  { value: '', label: 'All log names' },
  { value: 'organization', label: 'Organization' },
  { value: 'office', label: 'Office' },
  { value: 'department', label: 'Department' },
  { value: 'employee', label: 'Employee' },
  { value: 'cutoff_period', label: 'Cutoff period' },
  { value: 'leave_type', label: 'Leave type' },
]

const EVENT_OPTIONS: SelectOption[] = [
  { value: '', label: 'All events' },
  { value: 'created', label: 'Created' },
  { value: 'updated', label: 'Updated' },
  { value: 'deleted', label: 'Deleted' },
]

const MAX_PROPERTIES_PEEK = 80

/** `created_at` (ISO8601) -> a locale date+time string, mirroring `/office/cutoffs`'s
 * `formatClosedAt` — a malformed timestamp falls back to the raw string rather than
 * throwing or silently rendering "Invalid Date". */
function formatCreatedAt(createdAt: string): string {
  const date = new Date(createdAt)
  if (Number.isNaN(date.getTime())) return createdAt
  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/** A one-line, length-capped `JSON.stringify` of `properties` — enough to see the shape of
 * what changed without a table cell wide enough to break the layout. */
function propertiesPeek(properties: Record<string, unknown>): string {
  const json = JSON.stringify(properties)
  if (json.length <= MAX_PROPERTIES_PEEK) return json
  return `${json.slice(0, MAX_PROPERTIES_PEEK)}…`
}

const HEAD_CELL = {
  padding: 'var(--sp-sm) var(--sp-md)',
  font: 'var(--t-caption)',
  letterSpacing: 'var(--ls-caption)',
  color: 'var(--ink-subtle)',
  textAlign: 'left' as const,
}

const BODY_CELL = {
  padding: 'var(--sp-sm) var(--sp-md)',
  font: 'var(--t-body-sm)',
  letterSpacing: 'var(--ls-body)',
  color: 'var(--ink)',
  borderTop: '1px solid var(--hairline)',
  textAlign: 'left' as const,
}

function ActivityRow({ entry }: { entry: ActivityEntry }) {
  return (
    <tr>
      <td style={BODY_CELL}>{formatCreatedAt(entry.created_at)}</td>
      <td style={{ ...BODY_CELL, color: 'var(--ink-muted)' }}>{entry.causer_id ?? '—'}</td>
      <td style={BODY_CELL}>{entry.event}</td>
      <td style={BODY_CELL}>{entry.log_name}</td>
      <td style={BODY_CELL}>{entry.description}</td>
      <td style={{ ...BODY_CELL, color: 'var(--ink-muted)' }}>{entry.subject_type ?? '—'}</td>
      <td style={{ ...BODY_CELL, color: 'var(--ink-muted)', fontFamily: 'monospace' }}>
        {propertiesPeek(entry.properties)}
      </td>
    </tr>
  )
}

const DEFAULT_FILTERS: ActivityFilters = { log_name: '', event: '', from: '', to: '', page: 1 }

export default function ActivityLogPage() {
  const { session } = useSession()
  const [filters, setFilters] = useState<ActivityFilters>(DEFAULT_FILTERS)

  const isSysAdmin = session?.is_system_admin ?? false
  const activityQuery = useActivityLog(filters)

  const entries = activityQuery.data?.data ?? []
  const meta = activityQuery.data?.meta

  /** Any filter change other than paging itself resets to page 1 — a stale later page
   * must never render against a freshly narrowed (and likely shorter) result set. */
  function updateFilter(patch: Omit<ActivityFilters, 'page'>): void {
    setFilters((current) => ({ ...current, ...patch, page: 1 }))
  }

  function goToPage(page: number): void {
    setFilters((current) => ({ ...current, page }))
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Admin" title="Activity log" level={1} />

        {session !== null && !isSysAdmin ? (
          <InlineNotification kind="info" title="This account can't view the activity log.">
            The activity log is a system-admin-only screen.
          </InlineNotification>
        ) : (
          <>
            <div className="flex items-end flex-wrap" style={{ gap: 'var(--sp-lg)' }}>
              <Select
                id="activity-filter-log-name"
                label="Log name"
                value={filters.log_name ?? ''}
                onChange={(value) => updateFilter({ log_name: value })}
                options={LOG_NAME_OPTIONS}
              />
              <Select
                id="activity-filter-event"
                label="Event"
                value={filters.event ?? ''}
                onChange={(value) => updateFilter({ event: value })}
                options={EVENT_OPTIONS}
              />
              <TextInput
                id="activity-filter-from"
                label="From"
                type="date"
                value={filters.from ?? ''}
                onChange={(value) => updateFilter({ from: value })}
              />
              <TextInput
                id="activity-filter-to"
                label="To"
                type="date"
                value={filters.to ?? ''}
                onChange={(value) => updateFilter({ to: value })}
              />
            </div>

            {activityQuery.isLoading ? (
              <Skeleton height="16rem" />
            ) : activityQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load the activity log.">
                Check your connection and try again.
              </InlineNotification>
            ) : entries.length === 0 ? (
              <EmptyState title="No activity to show">Try widening the filters above.</EmptyState>
            ) : (
              <>
                <div style={{ overflowX: 'auto' }}>
                  <table aria-label="Activity log" style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr>
                        <th scope="col" style={HEAD_CELL}>When</th>
                        <th scope="col" style={HEAD_CELL}>Causer</th>
                        <th scope="col" style={HEAD_CELL}>Event</th>
                        <th scope="col" style={HEAD_CELL}>Log</th>
                        <th scope="col" style={HEAD_CELL}>Description</th>
                        <th scope="col" style={HEAD_CELL}>Subject type</th>
                        <th scope="col" style={HEAD_CELL}>Properties</th>
                      </tr>
                    </thead>
                    <tbody>
                      {entries.map((entry) => (
                        <ActivityRow key={entry.id} entry={entry} />
                      ))}
                    </tbody>
                  </table>
                </div>

                {meta !== undefined ? (
                  <div className="flex items-center justify-between" style={{ gap: 'var(--sp-md)' }}>
                    <span
                      style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}
                    >
                      Page {meta.current_page} of {meta.last_page} · {meta.total} total
                    </span>
                    <span className="flex items-center" style={{ gap: 'var(--sp-sm)' }}>
                      <Button
                        variant="ghost"
                        disabled={meta.current_page <= 1}
                        onClick={() => goToPage(meta.current_page - 1)}
                      >
                        Prev
                      </Button>
                      <Button
                        variant="ghost"
                        disabled={meta.current_page >= meta.last_page}
                        onClick={() => goToPage(meta.current_page + 1)}
                      >
                        Next
                      </Button>
                    </span>
                  </div>
                ) : null}
              </>
            )}
          </>
        )}
      </div>
    </AppShell>
  )
}
