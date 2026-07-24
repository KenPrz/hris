'use client'

/**
 * The screen everything else was built for: the daily punch action sits above the record
 * it produces. Direction is derived, never guessed, from the LAST punch of the day —
 * never from parity — so the button and the status line can never disagree (see
 * `deriveNextDirection` / `deriveStatusText`, both keyed off the same `lastTodaysPunch`).
 *
 * The hero always reflects TODAY, in `OFFICE_TIME_ZONE` — regardless of which month is
 * being browsed below it — because a punch always records "now." The ledger below shows
 * whichever month `?month=` names, defaulting to the current one.
 */

import { useEffect, useState } from 'react'
import { usePathname, useRouter, useSearchParams } from 'next/navigation'

import type { AttendanceLog, AttendanceMonth, PunchDirection } from '@/lib/api'
import { ApiError } from '@/lib/api'
import { addMonths, currentMonth, monthLabel, timeInZone, todayInZone } from '@/lib/date'
import { formatDuration } from '@/lib/duration'
import { type PunchPairing, pairPunches, sortByPunchedAt } from '@/lib/punches'
import { OFFICE_TIME_ZONE } from '@/lib/timezone'
import { useMyAttendance } from '@/hooks/useMyAttendance'
import { usePunch } from '@/hooks/usePunch'
import { useSession } from '@/hooks/useSession'
import { AppShell } from '@/components/AppShell'
import { SectionHeader } from '@/components/SectionHeader'
import { StatTile } from '@/components/StatTile'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/Button'
import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { MonthCalendar } from '@/components/domain/MonthCalendar'

const MONTH_PATTERN = /^\d{4}-\d{2}$/

function parseViewedMonth(raw: string | null): string {
  return raw !== null && MONTH_PATTERN.test(raw) ? raw : currentMonth(OFFICE_TIME_ZONE)
}

/** `null` → the next punch is `in` (an empty day). Otherwise flips the LAST punch's
 * direction — never parity, which agrees with "last punch" only when a day's punches
 * happen to alternate starting with `in`. A day that ends on a lone `out` (a night shift
 * closing out after midnight, one punch, odd count) must still produce `in` next. */
function deriveNextDirection(lastTodaysPunch: AttendanceLog | null): PunchDirection {
  return lastTodaysPunch?.direction === 'in' ? 'out' : 'in'
}

/**
 * "Clocked out" for an empty day; otherwise names the last punch's direction and the
 * clock time it happened, in the office's zone — never any other formatting of a punch
 * time. Fed the SAME `lastTodaysPunch` as `deriveNextDirection` so the two can never
 * disagree about what "last" means.
 */
function deriveStatusText(lastTodaysPunch: AttendanceLog | null, timeZone: string): string {
  if (lastTodaysPunch === null) return 'Clocked out'

  const time = timeInZone(lastTodaysPunch.punched_at, timeZone)

  return lastTodaysPunch.direction === 'in' ? `Clocked in since ${time}` : `Clocked out since ${time}`
}

/**
 * "Today," live. A cleanly-paired day is just its sum; a day still open (a trailing,
 * unclosed `in`) adds the elapsed time from that punch up to `nowMs` so the tile is a
 * genuinely live total while clocked in, not "0m" until clock-out. A genuinely irregular
 * day (see `pairPunches`) gets no invented number — same honesty `DayCell` holds to, so
 * the hero and the ledger below it never contradict each other for the same day.
 */
function heroTodayText(pairing: PunchPairing, nowMs: number): string {
  switch (pairing.kind) {
    case 'none':
      return formatDuration(0)
    case 'paired':
      return formatDuration(pairing.totalMinutes)
    case 'open': {
      const elapsedMs = nowMs - new Date(pairing.openSince).getTime()
      const elapsedMinutes = Math.max(0, Math.round(elapsedMs / 60_000))
      return formatDuration(pairing.completedMinutes + elapsedMinutes)
    }
    case 'unpaired':
      // Honestly nothing to report — inventing a number here is exactly the
      // hero-vs-ledger contradiction this function exists to prevent.
      return '—'
  }
}

function isMonthEmpty(month: AttendanceMonth): boolean {
  return Object.values(month).every((punches) => punches.length === 0)
}

/** Ticks once a second so the hero's clock — and the live "Today" total — stay current. */
function useNow(): Date {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(id)
  }, [])

  return now
}

const DIRECTION_LABEL: Record<PunchDirection, string> = {
  in: 'Clock in',
  out: 'Clock out',
}

function isNotAnEmployeeError(error: unknown): boolean {
  return error instanceof ApiError && error.code === 'not_an_employee'
}

export default function AttendancePage() {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const { session } = useSession()

  const viewedMonth = parseViewedMonth(searchParams.get('month'))
  const thisMonth = currentMonth(OFFICE_TIME_ZONE)
  const today = todayInZone(OFFICE_TIME_ZONE)

  // Same query key when browsing the current month — TanStack Query dedupes it into one
  // request, so this never doubles the fetch in the common case.
  const heroQuery = useMyAttendance(thisMonth)
  const viewedQuery = useMyAttendance(viewedMonth)
  const punchMutation = usePunch()

  const now = useNow()
  const clockText = timeInZone(now.toISOString(), OFFICE_TIME_ZONE)

  function navigateToMonth(nextMonth: string) {
    router.replace(`${pathname}?month=${nextMonth}`)
  }

  const todaysPunches = sortByPunchedAt(heroQuery.data?.[today] ?? [])
  const lastTodaysPunch = todaysPunches.at(-1) ?? null
  const nextDirection = deriveNextDirection(lastTodaysPunch)
  const statusText = deriveStatusText(lastTodaysPunch, OFFICE_TIME_ZONE)
  const todayText = heroTodayText(pairPunches(todaysPunches), now.getTime())

  function handlePunch() {
    punchMutation.mutate(nextDirection)
  }

  // A bare System Admin account (or any user) with no linked employee record cannot
  // punch or read `/me/attendance` — the backend's `NotAnEmployee` (422) exists exactly
  // for this. Login always lands here, so this is the one dead end in the app; the fix
  // is an explanation and nothing else, never the generic "check your connection" copy.
  const notAnEmployee =
    session?.employee === null || isNotAnEmployeeError(heroQuery.error) || isNotAnEmployeeError(viewedQuery.error)

  if (notAnEmployee) {
    return (
      <AppShell>
        <EmptyState title="This account isn't linked to an employee record">
          There&rsquo;s no attendance to show or clock until HR links this account to an
          employee record. Contact HR to get one set up.
        </EmptyState>
      </AppShell>
    )
  }

  return (
    <AppShell>
      <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
        <SectionHeader eyebrow="Me" title="Attendance" level={1} />

        <section
          style={{
            background: 'var(--surface-1)',
            borderRadius: 'var(--radius)',
            padding: 'var(--sp-lg)',
            display: 'flex',
            flexDirection: 'column',
            gap: 'var(--sp-md)',
          }}
        >
          {heroQuery.isLoading ? (
            <Skeleton height="6rem" />
          ) : heroQuery.isError ? (
            <InlineNotification kind="error" title="Couldn't load today's attendance.">
              Check your connection and try again.
            </InlineNotification>
          ) : (
            <>
              <div className="flex flex-wrap" style={{ gap: 'var(--sp-md)' }}>
                <StatTile label="Now" value={clockText} />
                <StatTile label="Status" value={statusText} />
                <StatTile label="Today" value={todayText} />
              </div>

              {punchMutation.isError ? (
                <InlineNotification kind="error" title="That punch didn't go through.">
                  Check your connection and try again.
                </InlineNotification>
              ) : null}

              <div>
                <Button
                  onClick={handlePunch}
                  loading={punchMutation.isPending}
                  disabled={punchMutation.isPending}
                >
                  {DIRECTION_LABEL[nextDirection]}
                </Button>
              </div>
            </>
          )}
        </section>

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
            </>
          }
        />

        {viewedQuery.isLoading ? (
          <Skeleton height="20rem" />
        ) : viewedQuery.isError ? (
          <InlineNotification kind="error" title="Couldn't load this month's attendance.">
            Check your connection and try again.
          </InlineNotification>
        ) : isMonthEmpty(viewedQuery.data ?? {}) ? (
          <EmptyState title="No punches this month">
            Nothing recorded for {monthLabel(viewedMonth)} yet.
          </EmptyState>
        ) : (
          <MonthCalendar month={viewedMonth} days={viewedQuery.data ?? {}} timeZone={OFFICE_TIME_ZONE} />
        )}
      </div>
    </AppShell>
  )
}
