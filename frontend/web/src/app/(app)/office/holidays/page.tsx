'use client'

/**
 * An HR admin's view of one office's holiday calendar for one year — the generalized
 * `MonthCalendar` shell (Task 6) with a holiday-specific `renderDay`. Clicking a day with
 * no holiday opens the add `Dialog` for that date; clicking a day that already has one
 * opens it for editing. A holiday's `date` is fixed once created — the edit dialog shows
 * it read-only and submits only `{ day_type, name }` (see `HolidayUpdateInput`); only the
 * add dialog's date comes from the day the admin clicked.
 *
 * Scoped to the offices `session.hr_offices` says this account administers — never
 * `current_office_id` alone, which is "the office you work at," not "the offices you can
 * edit holidays for." A single office skips the picker entirely; only length > 1 shows
 * one, labeled by office id (a name lookup is deferred past this task).
 *
 * A month with no holidays is normal — the calendar still renders so a day can still be
 * clicked to add one. That's the one deliberate difference from `/me/attendance`, which
 * shows `EmptyState` for an empty month: there, an empty month is nothing to look at; here
 * it's still the one way in.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { usePathname, useRouter, useSearchParams } from 'next/navigation'

import type { DayType } from '@/components/domain/DayTypeTag'
import { DayTypeTag } from '@/components/domain/DayTypeTag'
import { MonthCalendar } from '@/components/domain/MonthCalendar'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Dialog } from '@/components/ui/Dialog'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Select } from '@/components/ui/Select'
import type { SelectOption } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { TextInput } from '@/components/ui/TextInput'
import type { Holiday } from '@/lib/api'
import { addMonths, currentMonth, monthLabel } from '@/lib/date'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { useCloneHolidays, useCreateHoliday, useHolidays, useUpdateHoliday } from '@/hooks/useHolidays'
import { useSession } from '@/hooks/useSession'

// Month 01–12 only — same guard as /me/attendance, so an impossible `?month=` falls back
// to the current month instead of rendering "undefined 2026" over an empty grid.
const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/

function parseViewedMonth(raw: string | null): string {
  return raw !== null && MONTH_PATTERN.test(raw) ? raw : currentMonth(OFFICE_TIME_ZONE)
}

function holidaysByDate(holidays: Holiday[]): Record<string, Holiday> {
  const map: Record<string, Holiday> = {}
  for (const holiday of holidays) map[holiday.date] = holiday
  return map
}

const DAY_TYPE_OPTIONS: SelectOption[] = [
  { value: 'special_working', label: 'Special working' },
  { value: 'special_non_working', label: 'Special non-working' },
  { value: 'regular_holiday', label: 'Regular holiday' },
  { value: 'double_regular_holiday', label: 'Double regular holiday' },
]

type DialogState =
  | { mode: 'closed' }
  | { mode: 'add'; date: string }
  | { mode: 'edit'; holiday: Holiday }

interface HolidayFormProps {
  mode: 'add' | 'edit'
  date: string
  initialName: string
  initialDayType: DayType
  submitting: boolean
  submitError: boolean
  onCancel: () => void
  onSubmit: (input: { name: string; day_type: DayType }) => void
}

/** Owns its own `name`/`day_type` field state — mounted fresh (via a `key` on the caller)
 * every time the dialog opens on a different date or holiday, so switching targets never
 * leaks the previous form's draft into the new one. */
function HolidayForm({
  mode,
  date,
  initialName,
  initialDayType,
  submitting,
  submitError,
  onCancel,
  onSubmit,
}: HolidayFormProps) {
  const [name, setName] = useState(initialName)
  const [dayType, setDayType] = useState<DayType>(initialDayType)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ name, day_type: dayType })
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: 'var(--sp-md)' }}>
      <div className="flex flex-col" style={{ gap: 'var(--sp-xxs)' }}>
        <span style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
          Date
        </span>
        {/* The date is fixed by the day clicked (add) or by the holiday itself (edit) —
            never a field the admin types into. The backend has no rule for changing a
            holiday's date; this is display only, never submitted on edit. */}
        <span style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)' }}>{date}</span>
      </div>

      <TextInput id="holiday-name" label="Name" value={name} onChange={setName} required />

      <Select
        id="holiday-day-type"
        label="Day type"
        value={dayType}
        onChange={(value) => setDayType(value as DayType)}
        options={DAY_TYPE_OPTIONS}
      />

      {submitError ? (
        <InlineNotification kind="error" title="That didn't save.">
          Check your connection and try again.
        </InlineNotification>
      ) : null}

      <div className="flex" style={{ gap: 'var(--sp-sm)' }}>
        <Button type="submit" loading={submitting} disabled={submitting || name.trim().length === 0}>
          {mode === 'add' ? 'Add holiday' : 'Save changes'}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

export default function HolidaysPage() {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const { session } = useSession()

  const viewedMonth = parseViewedMonth(searchParams.get('month'))
  const year = Number(viewedMonth.slice(0, 4))

  const hrOffices = session?.hr_offices ?? []
  // hr_offices is the authority for "offices you administer"; current_office_id only
  // covers the degenerate single-office case where an admin has none listed there yet.
  const defaultOfficeId = hrOffices[0] ?? session?.employee?.current_office_id ?? null

  const [chosenOfficeId, setChosenOfficeId] = useState<string | null>(null)
  const officeId = chosenOfficeId ?? defaultOfficeId

  const [dialogState, setDialogState] = useState<DialogState>({ mode: 'closed' })

  const holidaysQuery = useHolidays(officeId, year)
  const createMutation = useCreateHoliday(officeId, year)
  const updateMutation = useUpdateHoliday(officeId, year)
  const cloneMutation = useCloneHolidays(officeId, year)

  function navigateToMonth(nextMonth: string) {
    router.replace(`${pathname}?month=${nextMonth}`)
  }

  function closeDialog() {
    setDialogState({ mode: 'closed' })
  }

  function handleSubmit(input: { name: string; day_type: DayType }) {
    if (officeId === null || dialogState.mode === 'closed') return

    if (dialogState.mode === 'add') {
      createMutation.mutate(
        { office_id: officeId, date: dialogState.date, day_type: input.day_type, name: input.name },
        { onSuccess: closeDialog },
      )
      return
    }

    updateMutation.mutate(
      { id: dialogState.holiday.id, body: { day_type: input.day_type, name: input.name } },
      { onSuccess: closeDialog },
    )
  }

  function handleClone() {
    if (officeId === null) return
    cloneMutation.mutate({ office_id: officeId, from_year: year - 1, to_year: year })
  }

  const byDate = holidaysByDate(holidaysQuery.data ?? [])

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Office" title="Holidays" level={1} />

        {hrOffices.length > 1 ? (
          <Select
            id="holiday-office"
            label="Office"
            value={officeId ?? ''}
            onChange={setChosenOfficeId}
            options={hrOffices.map((id) => ({ value: id, label: id }))}
          />
        ) : null}

        {officeId === null ? (
          <InlineNotification kind="info" title="No office to show holidays for.">
            This account doesn&rsquo;t administer any office&rsquo;s holidays.
          </InlineNotification>
        ) : (
          <>
            <SectionHeader
              title={monthLabel(viewedMonth)}
              actions={
                <>
                  <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, -1))}>
                    Previous month
                  </Button>
                  <Button variant="ghost" onClick={() => navigateToMonth(addMonths(viewedMonth, 1))}>
                    Next month
                  </Button>
                  <Button variant="secondary" onClick={handleClone} loading={cloneMutation.isPending}>
                    Clone from {year - 1}
                  </Button>
                </>
              }
            />

            {cloneMutation.isError ? (
              <InlineNotification kind="error" title="Cloning didn't go through.">
                Check your connection and try again.
              </InlineNotification>
            ) : null}

            {holidaysQuery.isLoading ? (
              <Skeleton height="20rem" />
            ) : holidaysQuery.isError ? (
              <InlineNotification kind="error" title="Couldn't load this year's holidays.">
                Check your connection and try again.
              </InlineNotification>
            ) : (
              // No EmptyState here even when `byDate` is empty — an empty holiday month is
              // expected, and the grid itself is how an admin adds the first one.
              <MonthCalendar
                month={viewedMonth}
                timeZone={OFFICE_TIME_ZONE}
                renderDay={({ date, isToday, inMonth }) => {
                  const holiday = byDate[date]

                  return (
                    <button
                      type="button"
                      aria-label={holiday !== undefined ? `${date}: ${holiday.name}` : `Add holiday on ${date}`}
                      onClick={() =>
                        setDialogState(holiday !== undefined ? { mode: 'edit', holiday } : { mode: 'add', date })
                      }
                      className="flex h-full w-full flex-col items-start text-left focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--blue)]"
                      style={{
                        gap: 'var(--sp-xxs)',
                        padding: 'var(--sp-xs)',
                        background: isToday ? 'var(--surface-1)' : 'transparent',
                        opacity: inMonth ? 1 : 0.4,
                        border: 'none',
                        cursor: 'pointer',
                      }}
                    >
                      <span
                        style={{
                          font: isToday ? 'var(--t-emphasis)' : 'var(--t-body-sm)',
                          letterSpacing: 'var(--ls-body)',
                          color: inMonth ? 'var(--ink)' : 'var(--ink-subtle)',
                        }}
                      >
                        {Number(date.slice(8, 10))}
                      </span>

                      {holiday !== undefined ? (
                        <>
                          <span
                            style={{
                              font: 'var(--t-caption)',
                              letterSpacing: 'var(--ls-caption)',
                              color: 'var(--ink)',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              maxWidth: '100%',
                            }}
                          >
                            {holiday.name}
                          </span>
                          <DayTypeTag dayType={holiday.day_type} />
                        </>
                      ) : (
                        <span
                          style={{ font: 'var(--t-caption)', letterSpacing: 'var(--ls-caption)', color: 'var(--ink-subtle)' }}
                        >
                          + Add
                        </span>
                      )}
                    </button>
                  )
                }}
              />
            )}
          </>
        )}

        <Dialog
          open={dialogState.mode !== 'closed'}
          onClose={closeDialog}
          title={dialogState.mode === 'edit' ? 'Edit holiday' : 'Add holiday'}
        >
          {dialogState.mode === 'closed' ? null : (
            <HolidayForm
              key={dialogState.mode === 'add' ? dialogState.date : dialogState.holiday.id}
              mode={dialogState.mode}
              date={dialogState.mode === 'add' ? dialogState.date : dialogState.holiday.date}
              initialName={dialogState.mode === 'edit' ? dialogState.holiday.name : ''}
              initialDayType={dialogState.mode === 'edit' ? dialogState.holiday.day_type : 'regular_holiday'}
              submitting={createMutation.isPending || updateMutation.isPending}
              submitError={createMutation.isError || updateMutation.isError}
              onCancel={closeDialog}
              onSubmit={handleSubmit}
            />
          )}
        </Dialog>
      </div>
    </AppShell>
  )
}
